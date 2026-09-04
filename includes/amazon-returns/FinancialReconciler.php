<?php

declare(strict_types=1);

require_once __DIR__ . '/Enums.php';

final class SvAmazonFinancialReconciler
{
    /** @return array{state:string,credit_amount:string,outstanding_amount:string,reopened:bool,transaction_ids:list<string>,unclassified_transactions:int} */
    public function reconcile(array $case, array $transactions): array
    {
        $expected = max(0.0, (float)($case['expected_reimbursement_amount'] ?? 0));
        $credit = 0.0;
        $ids = [];
        $unclassified = 0;
        $lifecycleGroups = [];
        foreach ($transactions as $tx) {
            if (!is_array($tx)) continue;
            $effect = $this->sellerEffect($tx);
            if ($effect === null) { $unclassified++; continue; }
            $id = trim((string)($tx['transaction_id'] ?? ''));
            if ($id !== '') $ids[] = $id;
            $status = strtoupper(trim((string)($tx['transaction_status'] ?? '')));
            $type = strtoupper(trim((string)($tx['transaction_type'] ?? '')));
            if ($type !== '' && in_array($status, ['RELEASED','DEFERRED_RELEASED'], true)) {
                $amountData = is_array($tx['total_amount'] ?? null) ? $tx['total_amount'] : [];
                $currency = strtoupper(trim((string)($amountData['currency'] ?? '')));
                $related = $tx['related_identifiers'] ?? $tx['relatedIdentifiers'] ?? [];
                $relatedKey = is_array($related) ? hash('sha256', json_encode($related, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]') : '';
                $key = $type . '|' . number_format($effect, 2, '.', '') . '|' . $currency . '|' . $relatedKey;
                if (!isset($lifecycleGroups[$key])) $lifecycleGroups[$key] = ['effect'=>$effect,'statuses'=>[]];
                $lifecycleGroups[$key]['statuses'][$status] = (int)($lifecycleGroups[$key]['statuses'][$status] ?? 0) + 1;
                continue;
            }
            $credit += $effect;
        }
        foreach ($lifecycleGroups as $group) {
            $occurrences = max((int)($group['statuses']['RELEASED'] ?? 0), (int)($group['statuses']['DEFERRED_RELEASED'] ?? 0));
            $credit += ((float)$group['effect']) * $occurrences;
        }
        $credit = max(0.0, $credit);
        $outstanding = max(0.0, $expected - $credit);
        $previousState = (string)($case['state'] ?? SvAmazonReturnStates::AWAITING_RETURN);
        $reopened = $previousState === SvAmazonReturnStates::RECOVERED && $outstanding > 0.005;
        if ($expected > 0 && $outstanding <= 0.005) {
            $state = SvAmazonReturnStates::RECOVERED;
        } elseif (in_array($previousState, [SvAmazonReturnStates::SAFE_T_APPROVED, SvAmazonReturnStates::APPEAL_APPROVED, SvAmazonReturnStates::CREDIT_PENDING, SvAmazonReturnStates::RECOVERED], true)) {
            $state = SvAmazonReturnStates::CREDIT_PENDING;
        } else {
            $state = $previousState;
        }
        return [
            'state'=>$state,
            'credit_amount'=>number_format($credit,2,'.',''),
            'outstanding_amount'=>number_format($outstanding,2,'.',''),
            'reopened'=>$reopened,
            'transaction_ids'=>array_values(array_unique($ids)),
            'unclassified_transactions'=>$unclassified,
        ];
    }

    private function sellerEffect(array $tx): ?float
    {
        if (isset($tx['seller_effect_amount']) && is_numeric($tx['seller_effect_amount'])) return (float)$tx['seller_effect_amount'];
        $type = strtoupper(trim((string)($tx['transaction_type'] ?? '')));
        $amountData = is_array($tx['total_amount'] ?? null) ? $tx['total_amount'] : null;
        $amount = $amountData !== null && is_numeric($amountData['amount'] ?? null) ? (float)$amountData['amount'] : null;
        if ($amount === null) return null;
        if (str_contains($type, 'REVERSAL')) return -abs($amount);
        if (str_contains($type, 'REIMBURSE') || str_contains($type, 'COMPENSATION') || str_contains($type, 'SAFE_T')) return abs($amount);
        return null;
    }
}
