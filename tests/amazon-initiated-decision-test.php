<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';

$case=[
    'id'=>13223,'amazon_order_id'=>'702-1830738-4884230','marketplace_id'=>'A2Q3Y263D00KWC',
    'program'=>'STANDARD','state'=>'POLICY_REVIEW_REQUIRED','physical_status'=>'NOT_RECEIVED',
    'refund_at'=>'2026-08-12 00:47:36','seller_debit_at'=>'2026-08-12 00:47:36',
    'refund_initiator'=>'AMAZON_INITIATED','quantity_ordered'=>1,'quantity_refunded'=>1,'quantity_received'=>0,
    'expected_reimbursement_amount'=>'57.09','reconciled_credit_amount'=>'0.00','safe_t_id'=>null,
];
$policy=['eligible'=>true,'state'=>'SAFE_T_ELIGIBLE','policy_version_id'=>7441,'eligibility_at'=>'2026-09-26 00:47:36'];
$decision=(new SvAmazonSafeTDecisionEngine())->nextAction($case,[],$policy,new DateTimeImmutable('2026-09-27 00:00:00',new DateTimeZone('UTC')));
if(($decision['action']??null)!=='SAFE_T_SUBMIT'){
    throw new RuntimeException('Verified Amazon-initiated refund must follow the normal automated recovery path: '.json_encode($decision));
}
$guard=(new SvAmazonSafeTDecisionEngine())->guardLearnedEffect(['action'=>'SAFE_T_SUBMIT'],$case,[],$policy,new DateTimeImmutable('2026-09-27 00:00:00',new DateTimeZone('UTC')));
if(($guard['action']??null)!=='SAFE_T_SUBMIT'){
    throw new RuntimeException('Learned-rule guard must recognize verified generic Amazon initiator: '.json_encode($guard));
}
echo "amazon-initiated-decision-test: OK\n";
