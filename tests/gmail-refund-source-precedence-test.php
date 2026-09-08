<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/GmailEventSink.php';
require_once __DIR__.'/../includes/amazon-returns/Projector.php';

function gpSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}
function gpAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$gmail=['event_type'=>'REFUND_ISSUED_EMAIL','occurred_at'=>'2026-09-01 17:30:27','amount'=>'128.25'];
$existing=['refund_at'=>'2026-09-01 12:00:00','refund_amount'=>'100.00'];
$patch=SvAmazonGmailEventSink::casePatch($gmail,$existing);
gpAssert(!array_key_exists('refund_at',$patch),'Gmail evidence must not overwrite an existing API refund timestamp.');
gpAssert(!array_key_exists('refund_amount',$patch),'Gmail evidence must not overwrite an existing API refund amount.');

$projected=SvAmazonReturnProjector::projectFrom([
    'id'=>1,'quantity_ordered'=>1,'physical_status'=>'NOT_RECEIVED','state'=>'POLICY_REVIEW_REQUIRED',
], [
    ['case_id'=>1,'event_type'=>'REFUND_CONFIRMED','source'=>'SP_API','occurred_at'=>'2026-09-01 12:00:00','payload'=>[
        'refund_at'=>'2026-09-01 12:00:00','refund_amount'=>'100.00','quantity_refunded'=>1,
    ]],
    ['case_id'=>1,'event_type'=>'REFUND_ISSUED_EMAIL','source'=>'GMAIL','occurred_at'=>'2026-09-01 17:30:27','payload'=>[
        'refund_at'=>'2026-09-01 17:30:27','refund_amount'=>'128.25','financial_truth'=>false,
    ]],
]);
gpSame('2026-09-01 12:00:00',$projected['refund_at'],'Later Gmail evidence must not replace authoritative SP-API refund time.');
gpSame('100.00',$projected['refund_amount'],'Later Gmail evidence must not replace authoritative SP-API refund amount.');
echo "gmail-refund-source-precedence-test: OK\n";
