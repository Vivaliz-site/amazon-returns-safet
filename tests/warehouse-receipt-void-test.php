<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Projector.php';
require_once __DIR__.'/../includes/amazon-returns/ReviewContext.php';

function wvSame(mixed $want,mixed $got,string $why):void {
    if($want!==$got)throw new RuntimeException($why.' want='.json_encode($want).' got='.json_encode($got));
}
$case=[
    'id'=>101669,'amazon_order_id'=>'701-6883818-0162606',
    'quantity_ordered'=>1,'quantity_received'=>1,'quantity_refunded'=>1,
    'state'=>'RECEIVED_OK','physical_status'=>'RECEIVED_OK',
    'refund_amount'=>'50.94','refund_at'=>'2026-09-18 08:15:44',
    'expected_reimbursement_amount'=>'50.94','reconciled_credit_amount'=>'0.00',
    'safe_t_id'=>'89790-48319-8106439',
    'marketplace_id'=>'A2Q3Y263D00KWC',
];
$refund=[
    'id'=>100,'case_id'=>101669,'event_type'=>'REFUND_CONFIRMED','source'=>'SP_API_FINANCES',
    'occurred_at'=>'2026-09-18 08:15:44',
    'payload'=>['refund_at'=>'2026-09-18 08:15:44','quantity_refunded'=>1,'refund_amount'=>'50.94'],
];
$receipt=[
    'id'=>1267182,'case_id'=>101669,'event_type'=>'PHYSICAL_RECEIVED','source'=>'WAREHOUSE',
    'source_event_id'=>'d3ca914b-9672-44d7-adf9-db6525ef8dd5',
    'occurred_at'=>'2026-11-11 00:48:01',
    'payload'=>['quantity'=>1,'condition'=>'OK','operator_id'=>2585484896],
];
$void=[
    'id'=>1269001,'case_id'=>101669,'event_type'=>'WAREHOUSE_RECEIPT_VOIDED','source'=>'ADMIN_AUDIT',
    'source_event_id'=>'101669-1267182-owner-correction',
    'occurred_at'=>'2026-11-11 03:05:00',
    'payload'=>[
        'receipt_event_id'=>1267182,
        'reason'=>'OWNER_CORRECTION_NOT_RECEIVED',
        'evidence_ref'=>'https://github.com/Vivaliz-site/amazon-returns-safet/issues/532',
        'actor'=>'GPT_ON_OWNER_INSTRUCTION',
    ],
];
$base=[$refund,$receipt];
wvSame(1,SvAmazonReturnProjector::projectFrom($case,$base)['quantity_received'],'baseline receipt still effective');
$corrected=SvAmazonReturnProjector::projectFrom($case,[...$base,$void]);
wvSame(0,$corrected['quantity_received'],'corrected intake must not invent warehouse quantity');
wvSame('NOT_RECEIVED',$corrected['physical_status'],'corrected intake must remove receipt status');
wvSame('AWAITING_RETURN',$corrected['state'],'stale received state must return to pending physical');
wvSame(null,$corrected['closed_at'],'stale physical closure must not persist');
$dup=$void;$dup['id']=1269002;
wvSame(0,SvAmazonReturnProjector::projectFrom($case,[...$base,$void,$dup])['quantity_received'],'duplicate correction remains idempotent');
$wrong=$void;$wrong['payload']['receipt_event_id']=1267183;
wvSame(1,SvAmazonReturnProjector::projectFrom($case,[...$base,$wrong])['quantity_received'],'wrong receipt id cannot erase a real receipt');
$wrongSource=$void;$wrongSource['source']='GMAIL';
wvSame(1,SvAmazonReturnProjector::projectFrom($case,[...$base,$wrongSource])['quantity_received'],'mail cannot retract warehouse receipt');
$wrongCase=$void;$wrongCase['case_id']=123;
wvSame(1,SvAmazonReturnProjector::projectFrom($case,[...$base,$wrongCase])['quantity_received'],'foreign case correction cannot erase receipt');
$wrongTime=$void;$wrongTime['id']=1;
wvSame(1,SvAmazonReturnProjector::projectFrom($case,[...$base,$wrongTime])['quantity_received'],'older event cannot erase future receipt');
$policy=['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'];
$review=['action'=>'WAIT','reason'=>'PHYSICAL_NOT_RECEIVED'];
$context=SvAmazonReviewContext::build(
    array_replace($corrected,['refund_initiator'=>'AMAZON_CUSTOMER_SERVICE']),
    [...$base,$void],$policy,$review
);
wvSame(false,$context['facts']['material_conflict'],'superseded intake cannot trigger warehouse contradiction');
wvSame('WAREHOUSE_RECEIPT_CORRECTION',$context['signature']['evidence_pattern'],'latest correction must be explicit evidence');
echo "warehouse-receipt-void-test: OK\n";
