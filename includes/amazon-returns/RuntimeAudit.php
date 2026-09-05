<?php
declare(strict_types=1);

/** Explicit metadata allowlist for the private daemon journal; never copies message bodies or credentials. */
final class SvAmazonReturnsRuntimeAudit
{
    public static function decision(array $case, array $timeline, array $policy, array $decision): array
    {
        $counts = [];
        foreach ($timeline as $event) {
            if (!is_array($event)) continue;
            $type = (string)($event['event_type'] ?? '');
            if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $type) !== 1) continue;
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        ksort($counts);
        return self::caseFields($case) + [
            'action'=>(string)($decision['action'] ?? 'WAIT'),
            'reason'=>(string)($decision['reason'] ?? ''),
            'operational_mode'=>$decision['operational_mode'] ?? $decision['action'] ?? null,
            'next_action_at'=>array_key_exists('next_action_at',$decision)?$decision['next_action_at']:($case['next_action_at']??null),
            'wait_source_hash'=>$decision['wait_source_hash']??null,
            'resume_scope'=>$decision['resume_scope']??null,
            'eligible'=>(bool)($policy['eligible'] ?? false),
            'eligibility_at'=>array_key_exists('eligibility_at',$policy)?$policy['eligibility_at']:($case['eligibility_at']??null),
            'safe_t_id'=>$case['safe_t_id'] ?? null,
            'support_case_id'=>$case['support_case_id'] ?? null,
            'appeal_deadline_at'=>$case['appeal_deadline_at'] ?? null,
            'event_counts'=>$counts,
        ];
    }

    public static function financial(array $case, array $transactions, array $result, bool $applied): array
    {
        $sources = [];
        foreach ($transactions as $tx) {
            if (!is_array($tx)) continue;
            $source = (string)($tx['source'] ?? 'INTERNAL');
            if (!in_array($source, ['INTERNAL','SP_API_FINANCES_V0','SP_API_FINANCES_V2024'], true)) $source = 'OTHER';
            $sources[$source] = ($sources[$source] ?? 0) + 1;
        }
        return self::caseFields($case) + [
            'calculated_state'=>(string)($result['state'] ?? ''),
            'applied'=>$applied,
            'expected_amount'=>(string)($case['expected_reimbursement_amount'] ?? '0.00'),
            'credit_before'=>(string)($case['reconciled_credit_amount'] ?? '0.00'),
            'credit_amount'=>(string)($result['credit_amount'] ?? '0.00'),
            'outstanding_amount'=>(string)($result['outstanding_amount'] ?? '0.00'),
            'transaction_count'=>count($transactions),
            'transaction_ids'=>array_values($result['transaction_ids'] ?? []),
            'unclassified_transactions'=>(int)($result['unclassified_transactions'] ?? 0),
            'reopened'=>(bool)($result['reopened'] ?? false),
            'sources'=>$sources,
        ];
    }

    private static function caseFields(array $case): array
    {
        return [
            'case_id'=>(int)($case['id'] ?? 0),
            'order_id'=>(string)($case['amazon_order_id'] ?? ''),
            'created_at'=>$case['created_at'] ?? null,
            'program'=>(string)($case['program'] ?? ''),
            'physical_status'=>(string)($case['physical_status'] ?? ''),
            'state'=>(string)($case['state'] ?? ''),
            'refund_at'=>$case['refund_at'] ?? null,
            'seller_debit_at'=>$case['seller_debit_at'] ?? null,
        ];
    }
}
