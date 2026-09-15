<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ReturnActionRouter.php';

function unknownRefreshSame(mixed $expected,mixed $actual,string $message):void
{
    if($expected!==$actual){
        throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
    }
}

$case=[
    'id'=>13254,
    'amazon_order_id'=>'702-7802983-5785045',
    'safe_t_id'=>'27845-46811-9805451',
    'state'=>'CREDIT_PENDING',
    'refund_at'=>'2026-06-24 16:55:40',
    'refund_initiator'=>'AMAZON_AUTOMATIC',
    'expected_reimbursement_amount'=>'20.78',
    'reconciled_credit_amount'=>'0.00',
    'appeal_deadline_at'=>'2026-08-18 14:41:00',
    'physical_status'=>'NOT_RECEIVED',
    'program'=>'FBA',
];
$timeline=[
    [
        'id'=>100,'case_id'=>13254,'source'=>'SELLER_CENTRAL',
        'event_type'=>'SAFE_T_STATUS_OBSERVED','occurred_at'=>'2026-09-15 12:00:00',
        'payload'=>[
            'safe_t_id'=>'27845-46811-9805451',
            'claim_status'=>'UNKNOWN',
            'appeal_submitted'=>null,
        ],
    ],
    [
        'id'=>101,'case_id'=>13254,'source'=>'SELLER_CENTRAL',
        'event_type'=>'SAFE_T_STATUS_OBSERVED','occurred_at'=>'2026-09-15 13:00:00',
        'payload'=>[
            'safe_t_id'=>'27845-46811-9805451',
            'claim_status'=>'UNKNOWN',
            'appeal_submitted'=>null,
        ],
    ],
];

$decision=SvAmazonReturnActionRouter::decide(
    $case,$timeline,['eligible'=>true],new DateTimeImmutable('2026-09-15 18:00:00',new DateTimeZone('UTC'))
);

unknownRefreshSame('SAFE_T_READ',$decision['action']??null,'All-UNKNOWN SAFE-T observations must trigger a fresh authoritative read.');
unknownRefreshSame('SAFE_T_STATUS_REFRESH_REQUIRED',$decision['reason']??null,'Unknown status must not wait indefinitely.');
if(!is_string($decision['idempotency_key']??null) || strlen($decision['idempotency_key'])!==64){
    throw new RuntimeException('Refresh decision needs a stable idempotency key.');
}

echo "safe-t-all-unknown-refresh-test: OK\n";
