<?php
declare(strict_types=1);
require_once __DIR__ . '/Enums.php';

final class SvAmazonFinancialReconciler
{
    /** @return array<string,mixed> */
    public function reconcile(array $case, array $transactions): array
    {
        $expected = max(0, self::cents($case['expected_reimbursement_amount'] ?? 0) ?? 0);
        $currency = strtoupper(trim((string)($case['currency'] ?? $case['expected_currency'] ?? '')));
        if ($currency === '' && ($case['marketplace_id'] ?? '') === 'A2Q3Y263D00KWC') $currency = 'BRL';
        $unique = [];
        foreach ($transactions as $tx) {
            if (!is_array($tx)) continue;
            $id = trim((string)($tx['transaction_id'] ?? ''));
            $unique[$id !== '' ? $id : hash('sha256', json_encode($tx, JSON_THROW_ON_ERROR))] = $tx;
        }
        $ids = []; $unclassified = 0; $groups = []; $positive = ['v0'=>0, 'ledger'=>0]; $debits = 0;
        $explicit = ['v0'=>0, 'ledger'=>0];
        $unsettledLedger = false;
        foreach ($unique as $tx) {
            $source = ($tx['source'] ?? '') === 'SP_API_FINANCES_V0' ? 'v0' : 'ledger';
            $money = is_array($tx['total_amount'] ?? null) ? $tx['total_amount'] : [];
            if ($currency === '' && !isset($tx['seller_effect_amount'])) { $unclassified++; continue; }
            $txCurrency = strtoupper(trim((string)($money['currency'] ?? '')));
            if ($currency !== '' && $txCurrency !== '' && $currency !== $txCurrency) { $unclassified++; continue; }
            $status = strtoupper(trim((string)($tx['transaction_status'] ?? '')));
            $type = strtoupper(trim((string)($tx['transaction_type'] ?? '')));
            $explicitReimbursement = $this->isExplicitReimbursement($tx, $source);
            if ($source === 'ledger' && $status !== '' && !in_array($status, ['RELEASED','DEFERRED_RELEASED'], true)
                && $explicitReimbursement) {
                $unsettledLedger = true;
            }
            $effect = $this->sellerEffect($tx);
            if ($effect === null) { $unclassified++; continue; }
            if ($explicitReimbursement) $explicit[$source] += $effect;
            $id = trim((string)($tx['transaction_id'] ?? ''));
            if ($id !== '') $ids[] = $id;
            if ($type !== '' && in_array($status, ['RELEASED','DEFERRED_RELEASED'], true)) {
                $related = $tx['related_identifiers'] ?? $tx['relatedIdentifiers'] ?? [];
                if (is_array($related)) usort($related, static fn($a, $b): int => json_encode($a) <=> json_encode($b));
                $key = implode('|', [$source, $type, $effect, $txCurrency, hash('sha256', json_encode($related, JSON_THROW_ON_ERROR))]);
                $groups[$key] ??= ['effect'=>$effect, 'source'=>$source, 'statuses'=>[]];
                $groups[$key]['statuses'][$status] = ($groups[$key]['statuses'][$status] ?? 0) + 1;
            } elseif ($effect < 0) $debits += $effect;
            else $positive[$source] += $effect;
        }
        foreach ($groups as $group) {
            $count = max($group['statuses']['RELEASED'] ?? 0, $group['statuses']['DEFERRED_RELEASED'] ?? 0);
            $effect = $group['effect'] * $count;
            if ($effect < 0) $debits += $effect;
            else $positive[$group['source']] += $effect;
        }
        // Finances v0 SAFE-T reimbursement events corroborate Amazon's declaration but do not
        // prove that money reached the seller account. Only a released ledger movement counts
        // as actual seller credit. v0 remains available below as corroborating-source metadata.
        $released = $positive['ledger'];
        $credit = max(0, $released + $debits);
        $legacyGap = max(0, $expected - $credit);
        $explicitNet = $explicit['ledger'];
        $ordered = filter_var($case['quantity_ordered'] ?? null, FILTER_VALIDATE_INT);
        $refunded = filter_var($case['quantity_refunded'] ?? null, FILTER_VALIDATE_INT);
        $singleRefundedUnit = $ordered === 1 && $refunded === 1;
        $releasedExplicitCredit = $explicitNet > 0 && !$unsettledLedger;
        $residualToleranceApplied = $releasedExplicitCredit
            && $expected > 0
            && $legacyGap > 0
            && ($legacyGap * 100) < ($expected * 5);
        // A released explicit reimbursement only settles a short payment when the residual
        // is below the approved 5% tolerance. Exactly 5% or more remains recoverable.
        // The historical no-baseline behavior remains limited to a single refunded unit.
        $explicitSettled = $residualToleranceApplied
            || ($singleRefundedUnit && $releasedExplicitCredit && ($expected <= 0 || $legacyGap === 0));
        $outstanding = $explicitSettled ? 0 : $legacyGap;
        $previous = (string)($case['state'] ?? SvAmazonReturnStates::AWAITING_RETURN);
        $state = $previous;
        if (($expected > 0 && $outstanding === 0) || $explicitSettled) $state = SvAmazonReturnStates::RECOVERED;
        elseif (in_array($previous, [SvAmazonReturnStates::SAFE_T_APPROVED, SvAmazonReturnStates::APPEAL_APPROVED, SvAmazonReturnStates::CREDIT_PENDING, SvAmazonReturnStates::RECOVERED], true)) $state = SvAmazonReturnStates::CREDIT_PENDING;
        return [
            'state'=>$state, 'credit_amount'=>self::money($credit), 'outstanding_amount'=>self::money($outstanding),
            'legacy_expected_gap_amount'=>self::money($legacyGap),
            'explicit_reimbursement_settled'=>$explicitSettled,
            'explicit_reimbursement_net_amount'=>self::money(max(0,$explicitNet)),
            'residual_tolerance_applied'=>$residualToleranceApplied,
            'tolerated_residual_amount'=>self::money($residualToleranceApplied ? $legacyGap : 0),
            'reopened'=>$previous === SvAmazonReturnStates::RECOVERED && $outstanding > 0,
            'transaction_ids'=>array_values(array_unique($ids)), 'unclassified_transactions'=>$unclassified,
            'corroborating_sources'=>$positive['v0'] > 0 && $positive['ledger'] > 0,
            'unsettled_financial_evidence'=>$unsettledLedger,
        ];
    }

    private function sellerEffect(array $tx): ?int
    {
        $status = strtoupper(trim((string)($tx['transaction_status'] ?? '')));
        if ($status !== '' && !in_array($status, ['RELEASED','DEFERRED_RELEASED'], true)) return null;
        // Explicit effects are an existing trusted internal contract, not produced from raw API text.
        if (isset($tx['seller_effect_amount'])) return self::cents($tx['seller_effect_amount']);
        if ($status === '' || trim((string)($tx['transaction_id'] ?? '')) === '') return null;
        $type = strtoupper(trim((string)($tx['transaction_type'] ?? '')));
        if (!str_contains($type, 'REIMBURSE') && !str_contains($type, 'COMPENSATION') && !str_contains($type, 'SAFE_T')
            && !$this->isReimbursementAdjustment($tx)) return null;
        $money = is_array($tx['total_amount'] ?? null) ? $tx['total_amount'] : [];
        if (preg_match('/^[A-Z]{3}$/', strtoupper((string)($money['currency'] ?? ''))) !== 1) return null;
        $amount = self::cents($money['amount'] ?? null);
        if ($amount === null) return null;
        return str_contains($type, 'REVERSAL') ? -abs($amount) : $amount;
    }

    private function isExplicitReimbursement(array $tx,string $source): bool
    {
        if ($source === 'v0') return true;
        $type = strtoupper(trim((string)($tx['transaction_type'] ?? '')));
        return str_contains($type, 'REIMBURSE') || str_contains($type, 'COMPENSATION')
            || str_contains($type, 'SAFE_T') || $this->isReimbursementAdjustment($tx);
    }

    private function isReimbursementAdjustment(array $tx): bool
    {
        if (strtoupper(trim((string)($tx['transaction_type'] ?? ''))) !== 'ADJUSTMENT') return false;
        $description = strtoupper(trim((string)($tx['description'] ?? '')));
        if (str_contains($description, 'REIMBURSEMENT')) return true;
        $breakdowns = $tx['breakdowns'] ?? [];
        if (!is_array($breakdowns)) return false;
        foreach ($breakdowns as $breakdown) {
            if (!is_array($breakdown)) continue;
            $type = strtoupper(trim((string)($breakdown['breakdown_type'] ?? $breakdown['breakdownType'] ?? '')));
            if ($type === 'REIMBURSEMENTS') return true;
        }
        return false;
    }

    private static function cents(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) return null;
        if (preg_match('/^(-?)([0-9]{1,12})(?:\.([0-9]{1,2}))?$/D', trim((string)$value), $m) !== 1) return null;
        $minor = (int)$m[2] * 100 + (int)str_pad($m[3] ?? '', 2, '0');
        return $m[1] === '-' ? -$minor : $minor;
    }

    private static function money(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
