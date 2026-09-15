<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ReturnActionRouter.php';

function usufSame(mixed $expected,mixed $actual,string $message):void
{
    if($expected!==$actual){
        throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
    }
}

$case=[
    'id'=>9001,
    'amazon_order_id'=>'702-0000000-0000001',
    'safe_t_id'=>'12345-67890-1234567',
    'state'=>'CREDIT_PENDING',
    'refund_at'=>'2026-07-20 12:00:00',
    'refund_initiator'=>'AMAZON_AUTOMATIC',
    'expected_reimbursement_amount'=>'100.00',
    'reconciled_credit_amount'=>'50.00',
    'appeal_deadline_at'=>'2026-09-20 23:59:59',
    'physical_status'=>'AWAITING_RETURN',
    'program'=>'STANDARD',
];
$timeline=[
    [
        'id'=>1,'case_id'=>9001,'source'=>'SELLER_CENTRAL',
        'event_type'=>'SAFE_T_STATUS_OBSERVED','occurred_at'=>'2026-09-15 12:00:00',
        'payload'=>[
            'claim_status'=>'APPROVED','appeal_submitted'=>false,
            'appeal_deadline_at'=>'2026-09-20 23:59:59',
        ],
    ],
    [
        'id'=>2,'case_id'=>9001,'source'=>'SELLER_CENTRAL',
        'event_type'=>'SAFE_T_STATUS_OBSERVED','occurred_at'=>'2026-09-15 13:00:00',
        'payload'=>['claim_status'=>'UNKNOWN','appeal_submitted'=>null],
    ],
    [
        'id'=>3,'case_id'=>9001,'source'=>'SP_API_FINANCES',
        'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','occurred_at'=>'2026-09-15 14:30:00',
        'payload'=>[
            'refresh_complete'=>true,'outstanding_amount'=>'50.00',
            'ambiguous_reimbursement_transactions'=>0,
            'unsettled_financial_evidence'=>false,
        ],
    ],
];
$decision=SvAmazonReturnActionRouter::decide(
    $case,$timeline,['eligible'=>true],new DateTimeImmutable('2026-09-15 15:00:00',new DateTimeZone('UTC'))
);

usufSame('SAFE_T_APPEAL',$decision['action']??null,'A later UNKNOWN observation must not erase the prior definitive APPROVED status.');
usufSame('PARTIAL_REIMBURSEMENT_BALANCE_APPEAL_REQUIRED',$decision['reason']??null,'The partial unpaid balance must remain actionable.');

echo "safe-t-unknown-status-fallback-test: OK\n";
