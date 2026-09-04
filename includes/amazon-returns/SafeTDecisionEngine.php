<?php

declare(strict_types=1);

require_once __DIR__ . '/Enums.php';
require_once __DIR__ . '/DenialAnalyzer.php';
require_once __DIR__ . '/SafeTStatus.php';

final class SvAmazonSafeTDecisionEngine
{
    public function __construct(private ?SvAmazonDenialAnalyzer $denialAnalyzer = null)
    {
        $this->denialAnalyzer ??= new SvAmazonDenialAnalyzer();
    }

    /** @return array<string,mixed> */
    public function nextAction(array $case, array $timeline, array $policy): array
    {
        $caseId = (int)($case['id'] ?? 0);
        $orderId = trim((string)($case['amazon_order_id'] ?? ''));
        $safeTId = trim((string)($case['safe_t_id'] ?? ''));
        $state = trim((string)($case['state'] ?? ''));

        if ($this->hasRecoveredCredit($case)) {
            return $this->decision('WAIT', 'ALREADY_REIMBURSED', $caseId);
        }
        if ((string)($case['physical_status'] ?? '') === SvAmazonReturnPhysicalStatuses::RECEIVED_OK) {
            return $this->decision('WAIT', 'PHYSICAL_RETURN_RECEIVED', $caseId);
        }

        // Once a SAFE-T exists, follow the claim lifecycle independently of the
        // original eligibility classification. Historical claims can legitimately
        // lack refund/program facts that were never captured before this system.
        if ($safeTId !== '') {
            if ($state === SvAmazonReturnStates::SAFE_T_INFO_REQUESTED) {
                return [
                    'action' => 'SAFE_T_APPEAL',
                    'reason' => 'SAFE_T_INFORMATION_RESPONSE_REQUIRED',
                    'idempotency_key' => hash('sha256', 'safe-t-info-response|' . $safeTId . '|' . (string)($case['info_request_fingerprint'] ?? 'current')),
                ];
            }

            if ($state === SvAmazonReturnStates::APPEAL_DENIED_FINAL) {
                $denialContext = SvAmazonSafeTStatus::denialContext($timeline);
                $latestText = trim((string)($case['latest_denial_text'] ?? $denialContext['latest_denial_text']));
                if ($latestText === '') {
                    return $this->decision('BLOCKED_REVIEW', 'DENIAL_TEXT_MISSING', $caseId);
                }
                $fingerprint = $this->denialAnalyzer->fingerprint($latestText);
                return [
                    'action' => 'SAFE_T_EMAIL_REVIEW',
                    'reason' => 'APPEAL_DENIED_REQUIRES_DETAILED_EMAIL_REVIEW',
                    'denial_fingerprint' => $fingerprint,
                    'idempotency_key' => hash('sha256', 'safe-t-email-review|' . $safeTId . '|' . $fingerprint),
                ];
            }

            if ($state === SvAmazonReturnStates::SUPPORT_ESCALATION) {
                $denialContext = SvAmazonSafeTStatus::denialContext($timeline);
                $latestText = trim((string)($case['latest_denial_text'] ?? $denialContext['latest_denial_text']));
                if ($latestText === '') return $this->decision('BLOCKED_REVIEW', 'DENIAL_TEXT_MISSING', $caseId);
                $fingerprint = $this->denialAnalyzer->fingerprint($latestText);
                if ($this->hasActiveSupportCase($case)) {
                    if (($case['new_support_fact'] ?? false) === true) {
                        return [
                            'action'=>'SELLER_SUPPORT_UPDATE',
                            'reason'=>'EMAIL_REVIEW_DENIED_NEW_FACT',
                            'support_case_id'=>(string)$case['support_case_id'],
                            'denial_fingerprint'=>$fingerprint,
                            'idempotency_key'=>hash('sha256', 'support-update|' . (string)$case['support_case_id'] . '|' . $fingerprint . '|' . (string)($case['support_fact_hash'] ?? 'new-fact')),
                        ];
                    }
                    return ['action'=>'WAIT','reason'=>'SUPPORT_ESCALATION_ALREADY_ACTIVE','support_case_id'=>(string)$case['support_case_id'],'denial_fingerprint'=>$fingerprint];
                }
                $round = max(1, (int)($case['support_escalation_round'] ?? 1));
                return [
                    'action'=>'SELLER_SUPPORT_OPEN',
                    'reason'=>'EMAIL_REVIEW_DENIED_REQUIRES_SUPPORT',
                    'denial_fingerprint'=>$fingerprint,
                    'idempotency_key'=>hash('sha256', 'support-open|' . $safeTId . '|' . $fingerprint . '|' . $round),
                ];
            }

            if (in_array($state, [SvAmazonReturnStates::SAFE_T_DENIED, SvAmazonReturnStates::APPEAL_REQUIRED], true)) {
                $denialContext = SvAmazonSafeTStatus::denialContext($timeline);
                $latestText = trim((string)($case['latest_denial_text'] ?? $denialContext['latest_denial_text']));
                if ($latestText === '') {
                    return $this->decision('BLOCKED_REVIEW', 'DENIAL_TEXT_MISSING', $caseId);
                }
                $fingerprint = $this->denialAnalyzer->fingerprint($latestText);
                return [
                    'action' => 'SAFE_T_APPEAL',
                    'reason' => 'SAFE_T_DENIAL_REQUIRES_FIRST_APPEAL',
                    'denial_fingerprint' => $fingerprint,
                    'idempotency_key' => hash('sha256', 'safe-t-appeal|' . $safeTId . '|' . $fingerprint),
                ];
            }

            return $this->decision('WAIT', 'SAFE_T_ALREADY_EXISTS', $caseId);
        }

        $initiator = (string)($case['refund_initiator'] ?? SvAmazonRefundInitiators::UNKNOWN);
        if (!SvAmazonRefundInitiators::isValid($initiator) || $initiator === SvAmazonRefundInitiators::UNKNOWN) {
            return $this->decision('BLOCKED_REVIEW', 'REFUND_INITIATOR_UNKNOWN', $caseId);
        }
        if (trim((string)($case['seller_debit_at'] ?? '')) === '') {
            return $this->decision('WAIT', 'SELLER_DEBIT_NOT_CONFIRMED', $caseId);
        }
        if (($policy['state'] ?? null) === SvAmazonReturnStates::POLICY_REVIEW_REQUIRED) {
            return $this->decision('BLOCKED_REVIEW', 'POLICY_REVIEW_REQUIRED', $caseId);
        }
        if (($policy['eligible'] ?? false) !== true) {
            return $this->decision('WAIT', 'NOT_YET_ELIGIBLE', $caseId);
        }

        $policyId = (string)($policy['policy_version_id'] ?? 'unknown');
        $eligibilityAt = (string)($policy['eligibility_at'] ?? 'unknown');
        return [
            'action' => 'SAFE_T_SUBMIT',
            'reason' => 'FIRST_ELIGIBLE_ATTEMPT',
            'idempotency_key' => hash('sha256', 'safe-t-submit|' . $caseId . '|' . $orderId . '|' . $policyId . '|' . $eligibilityAt),
        ];
    }

    /** @return array{action:string,reason:string,case_id:int} */
    private function decision(string $action, string $reason, int $caseId): array
    {
        return ['action' => $action, 'reason' => $reason, 'case_id' => $caseId];
    }

    private function hasRecoveredCredit(array $case): bool
    {
        $expected = (float)($case['expected_reimbursement_amount'] ?? 0);
        $credited = (float)($case['reconciled_credit_amount'] ?? 0);
        return $expected > 0.0 && $credited + 0.00001 >= $expected;
    }

    private function hasActiveSupportCase(array $case): bool
    {
        $id = trim((string)($case['support_case_id'] ?? ''));
        if ($id === '') return false;
        $status = strtoupper(trim((string)($case['support_case_status'] ?? 'OPEN')));
        return !in_array($status, ['CLOSED', 'RESOLVED', 'CANCELLED'], true);
    }
}
