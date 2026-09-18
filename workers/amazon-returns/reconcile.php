<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/amazon-returns/FinancialReconciler.php';
require_once __DIR__ . '/../../includes/amazon-returns/FinancialObservations.php';
require_once __DIR__ . '/../../includes/amazon-returns/Projector.php';

final class SvAmazonReturnsReconcileWorker
{
    public function __construct(private ?SvAmazonFinancialReconciler $reconciler = null) { $this->reconciler ??= new SvAmazonFinancialReconciler(); }
    public function reconcileCase(array $case, array $transactions): array { return $this->reconciler->reconcile($case, $transactions); }

    public function shouldUpdateCase(array $case, array $transactions): bool
    {
        return $transactions !== [] || ($case['state'] ?? '') === SvAmazonReturnStates::RECOVERED;
    }

    /** @param list<array<string,mixed>> $events @return array<string,mixed> */
    public static function caseUpdate(
        array $case,
        array $result,
        array $events = [],
        ?DateTimeImmutable $now = null
    ): array {
        $state = trim((string)($result['state'] ?? $case['state'] ?? ''));
        if (!SvAmazonReturnStates::isValid($state)) {
            throw new InvalidArgumentException('Financial reconciliation produced an invalid case state.');
        }
        $credit = (string)($result['credit_amount'] ?? $case['reconciled_credit_amount'] ?? '0.00');
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $existingClosedAt = self::nonEmptyText($case['closed_at'] ?? null);
        $existingReason = self::nonEmptyText($case['terminal_reason'] ?? null);

        if ($state === SvAmazonReturnStates::RECOVERED) {
            return [
                'reconciled_credit_amount'=>$credit,
                'state'=>$state,
                'terminal_reason'=>'FINANCIAL_RECOVERED',
                'closed_at'=>$existingClosedAt ?? $now->format('Y-m-d H:i:s'),
                'next_action_at'=>null,
            ];
        }

        if (in_array($state, [SvAmazonReturnStates::RECEIVED_OK, SvAmazonReturnStates::CLOSED_LOSS], true)) {
            $projected = null;
            if ($state === SvAmazonReturnStates::RECEIVED_OK && $events !== []
                && ($existingClosedAt === null || $existingReason === null)) {
                $candidate = SvAmazonReturnProjector::projectFrom($case, $events);
                if (($candidate['state'] ?? null) === SvAmazonReturnStates::RECEIVED_OK) {
                    $projected = $candidate;
                }
            }
            $closedAt = $existingClosedAt
                ?? self::nonEmptyText($projected['closed_at'] ?? null)
                ?? $now->format('Y-m-d H:i:s');
            $reason = $existingReason
                ?? self::nonEmptyText($projected['terminal_reason'] ?? null)
                ?? ($state === SvAmazonReturnStates::RECEIVED_OK
                    ? 'PHYSICAL_RETURN_RECEIVED'
                    : 'EMAIL_REVIEW_FINAL_DENIAL');
            return [
                'reconciled_credit_amount'=>$credit,
                'state'=>$state,
                'terminal_reason'=>$reason,
                'closed_at'=>$closedAt,
                'next_action_at'=>null,
            ];
        }

        return [
            'reconciled_credit_amount'=>$credit,
            'state'=>$state,
            'terminal_reason'=>null,
            'closed_at'=>null,
            'next_action_at'=>$case['next_action_at'] ?? null,
        ];
    }

    private static function nonEmptyText(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    /** @param list<array<string,mixed>> $events @return list<array<string,mixed>> */
    public function transactionsFromEvents(array $events): array
    {
        $transactions = [];
        foreach (SvAmazonFinancialObservations::latestEvents($events) as $event) {
            $tx = $event['payload']['transaction'];
            $tx['source'] = 'SP_API_FINANCES_V2024';
            $transactions[] = $tx;
        }
        foreach ($events as $event) {
            if (!is_array($event) || ($event['event_type'] ?? '') !== 'SAFE_T_REIMBURSEMENT_OBSERVED') continue;
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $money = is_array($payload['reimbursed_amount'] ?? null) ? $payload['reimbursed_amount'] : [];
            $amount = trim((string)($money['amount'] ?? ''));
            $currency = strtoupper(trim((string)($money['currency'] ?? '')));
            $claimId = trim((string)($payload['safe_t_claim_id'] ?? ''));
            $postedAt = trim((string)($payload['posted_at'] ?? $event['occurred_at'] ?? ''));
            if ($claimId === '' || $postedAt === '' || preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,2})?$/D', $amount) !== 1
                || (float)$amount <= 0 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) continue;
            $transactions[] = [
                'transaction_id'=>'safet-' . hash('sha256', implode('|', [$claimId, $postedAt, number_format((float)$amount, 2, '.', ''), $currency])),
                'transaction_type'=>'SAFE_T_REIMBURSEMENT',
                'transaction_status'=>'RELEASED',
                'source'=>'SP_API_FINANCES_V0',
                'posted_at'=>$postedAt,
                'total_amount'=>['amount'=>$amount, 'currency'=>$currency],
                'related_identifiers'=>[['name'=>'SAFE_T_CLAIM_ID', 'value'=>$claimId]],
            ];
        }
        return $transactions;
    }
}
