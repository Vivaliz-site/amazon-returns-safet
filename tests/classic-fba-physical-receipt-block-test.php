<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';

function cfprSame(mixed $want,mixed $got,string $message):void{
    if($want!==$got)throw new RuntimeException($message.' expected='.json_encode($want).' actual='.json_encode($got));
}

$engine=new SvAmazonSafeTDecisionEngine();
$case=[
    'id'=>11824,'amazon_order_id'=>'702-5144267-2415462','program'=>'FBA','safe_t_id'=>null,
    'state'=>'RECEIVED_OK','physical_status'=>'RECEIVED_OK','quantity_received'=>1,
    'refund_at'=>'2026-08-14 17:33:28','seller_debit_at'=>'2026-08-14 17:33:28',
    'refund_initiator'=>'UNKNOWN','expected_reimbursement_amount'=>'42.82','reconciled_credit_amount'=>'0.00',
];
$timeline=[
    [
        'id'=>2001,'case_id'=>11824,'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','source'=>'SP_API_FINANCES',
        'occurred_at'=>'2026-09-09 14:00:00','payload'=>[
            'refresh_complete'=>true,'outstanding_amount'=>'42.82',
            'ambiguous_reimbursement_transactions'=>0,'unsettled_financial_evidence'=>false,
        ],
    ],
    [
        'id'=>2002,'case_id'=>11824,'event_type'=>'PHYSICAL_RECEIVED','source'=>'WAREHOUSE',
        'occurred_at'=>'2026-09-09 14:31:35','payload'=>['quantity'=>1,'condition'=>'OK'],
    ],
];
$policy=['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'];
$decision=$engine->nextAction(
    $case,$timeline,$policy,
    new DateTimeImmutable('2026-09-09 14:40:00',new DateTimeZone('UTC'))
);
cfprSame('WAIT',$decision['action']??null,'Confirmed physical receipt must block FBA Seller Support opening.');
cfprSame('SELLER_APP_PHYSICAL_RECEIPT_CONFIRMED',$decision['reason']??null,'Receipt block must remain auditable.');
$existingClaim=$case;
$existingClaim['safe_t_id']='87992-58145-2490130';
$existingClaim['state']='SAFE_T_DENIED';
$existingClaimDecision=$engine->nextAction($existingClaim,$timeline,$policy,new DateTimeImmutable('2026-09-09 14:40:00',new DateTimeZone('UTC')));
cfprSame('WAIT',$existingClaimDecision['action']??null,'Confirmed intact receipt must block recovery even when an older SAFE-T already exists.');
cfprSame('SELLER_APP_PHYSICAL_RECEIPT_CONFIRMED',$existingClaimDecision['reason']??null,'Existing claim must not bypass the seller physical-receipt terminal guard.');
$discrepant=$case;
$discrepant['state']='RECEIVED_DISCREPANT';
$discrepant['physical_status']='RECEIVED_DISCREPANT';
$discrepantTimeline=$timeline;
$discrepantTimeline[1]['payload']['condition']='DAMAGED';
$discrepantDecision=$engine->nextAction($discrepant,$discrepantTimeline,$policy,new DateTimeImmutable('2026-09-09 14:40:00',new DateTimeZone('UTC')));
cfprSame('SELLER_SUPPORT_OPEN',$discrepantDecision['action']??null,'Discrepant physical receipt must not be silently superseded as a clean receipt.');
echo "classic-fba-physical-receipt-block-test: OK\n";
