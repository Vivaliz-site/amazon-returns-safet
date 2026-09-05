<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/amazon-returns/FinancialReconciler.php';
require_once __DIR__ . '/../../includes/amazon-returns/FinancialObservations.php';

final class SvAmazonReturnsReconcileWorker
{
    public function __construct(private ?SvAmazonFinancialReconciler $reconciler = null) { $this->reconciler ??= new SvAmazonFinancialReconciler(); }
    public function reconcileCase(array $case, array $transactions): array { return $this->reconciler->reconcile($case, $transactions); }

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
