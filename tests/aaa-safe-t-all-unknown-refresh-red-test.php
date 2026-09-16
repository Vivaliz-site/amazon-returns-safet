<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ReturnActionRouter.php';

$case=[
    'id'=>13254,'amazon_order_id'=>'702-7802983-5785045','safe_t_id'=>'27845-46811-9805451',
    'state'=>'CREDIT_PENDING','refund_at'=>'2026-06-24 16:55:40','refund_initiator'=>'AMAZON_AUTOMATIC',
    'expected_reimbursement_amount'=>'20.78','reconciled_credit_amount'=>'0.00',
    'appeal_deadline_at'=>'2026-08-18 14:41:00','physical_status'=>'NOT_RECEIVED','program'=>'FBA',
];
$timeline=[
    ['id'=>100,'case_id'=>13254,'source'=>'SELLER_CENTRAL','event_type'=>'SAFE_T_STATUS_OBSERVED','occurred_at'=>'2026-09-15 12:00:00','payload'=>['safe_t_id'=>'27845-46811-9805451','claim_status'=>'UNKNOWN','appeal_submitted'=>null]],
    ['id'=>101,'case_id'=>13254,'source'=>'SELLER_CENTRAL','event_type'=>'SAFE_T_STATUS_OBSERVED','occurred_at'=>'2026-09-15 13:00:00','payload'=>['safe_t_id'=>'27845-46811-9805451','claim_status'=>'UNKNOWN','appeal_submitted'=>null]],
];
$decision=SvAmazonReturnActionRouter::decide($case,$timeline,['eligible'=>true],new DateTimeImmutable('2026-09-15 18:00:00',new DateTimeZone('UTC')));
if(($decision['action']??null)!=='SAFE_T_READ'){
    throw new RuntimeException('Expected SAFE_T_READ for all-UNKNOWN SAFE-T history, got '.var_export($decision['action']??null,true));
}
if(($decision['reason']??null)!=='SAFE_T_STATUS_REFRESH_REQUIRED'){
    throw new RuntimeException('Expected SAFE_T_STATUS_REFRESH_REQUIRED.');
}
echo "aaa-safe-t-all-unknown-refresh-red-test: OK\n";
