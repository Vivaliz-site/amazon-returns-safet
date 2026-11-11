<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Projector.php';
require_once __DIR__.'/../includes/amazon-returns/ReviewContext.php';
function vctxSame(mixed $expected,mixed $got,string $label):void {
    if($expected!==$got)throw new RuntimeException($label.' expected='.json_encode($expected).' got='.json_encode($got));
}
$case=['id'=>101669,'amazon_order_id'=>'701-6883818-0162606','quantity_ordered'=>1,
 'quantity_received'=>1,'quantity_refunded'=>1,'physical_status'=>'RECEIVED_OK','state'=>'RECEIVED_OK',
 'refund_amount'=>'50.94','refund_at'=>'2026-09-18 08:15:44',
 'expected_reimbursement_amount'=>'50.94','reconciled_credit_amount'=>'0.00',
 'safe_t_id'=>'89790-48319-8106439','marketplace_id'=>'A2Q3Y263D00KWC'];
$refund=['id'=>100,'case_id'=>101669,'event_type'=>'REFUND_CONFIRMED','source'=>'SP_API_FINANCES',
 'occurred_at'=>'2026-09-18 08:15:44','payload'=>['refund_at'=>'2026-09-18 08:15:44',
 'quantity_refunded'=>1,'refund_amount'=>'50.94']];
$receipt=['id'=>1267182,'case_id'=>101669,'event_type'=>'PHYSICAL_RECEIVED',
 'source'=>'WAREHOUSE','source_event_id'=>'d3ca914b-9672-44d7-adf9-db6525ef8dd5',
 'occurred_at'=>'2026-11-11 00:48:01',
 'payload'=>['quantity'=>1,'condition'=>'OK','operator_id'=>2585484896]];
$void=['id'=>1269001,'case_id'=>101669,'event_type'=>'PHYSICAL_RECEIPT_VOIDED',
 'source'=>'WAREHOUSE','source_event_id'=>'void-101669-1267182',
 'occurred_at'=>'2026-11-11 03:05:00',
 'payload'=>['receipt_event_id'=>1267182,'operator_id'=>33,
 'reason'=>'OWNER_CORRECTION_NOT_RECEIVED',
 'evidence_ref'=>'https://github.com/Vivaliz-site/amazon-returns-safet/issues/532']];
$timeline=[$refund,$receipt,$void];
$cor=SvAmazonReturnProjector::projectFrom($case,$timeline);
vctxSame(0,$cor['quantity_received'],'Canonical projector excludes corrected physical receipt');
vctxSame('NOT_RECEIVED',$cor['physical_status'],'Source-backed receipt correction');
$context=SvAmazonReviewContext::build(
 array_replace($cor,['refund_initiator'=>'AMAZON_CUSTOMER_SERVICE']),$timeline,
 ['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'],
 ['action'=>'WAIT','reason'=>'PHYSICAL_NOT_RECEIVED']
);
vctxSame(false,$context['facts']['material_conflict'],'Void warehouse receipt must not create false material conflict');
vctxSame('WAREHOUSE_RECEIPT_CORRECTION',$context['signature']['evidence_pattern'],'Review must prefer correction event');
echo "warehouse-receipt-void-context-test: OK\n";
