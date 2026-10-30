<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTExternalWindow.php';

function externalWindowSame(mixed $want,mixed $got,string $message):void{
    if($want!==$got)throw new RuntimeException($message.' expected='.var_export($want,true).' actual='.var_export($got,true));
}
function externalOrder(string $at):array{
    return ['event_type'=>'ORDER_SYNCED','source'=>'SP_API_ORDERS','payload'=>['order_at'=>$at]];
}
$now=new DateTimeImmutable('2026-10-30 23:30:00',new DateTimeZone('UTC'));
foreach([
    ['FBA_ONSITE','2026-08-11 16:13:06','2026-09-07 23:33:01','2026-11-06 23:33:01'],
    ['DELIVERY_BY_AMAZON','2026-08-25 13:55:32','2026-09-09 18:38:59','2026-11-08 18:38:59'],
] as [$program,$order,$refund,$expected]){
    $case=['program'=>$program,'refund_at'=>$refund];
    $blocked=SvAmazonSafeTExternalWindow::inspect($case,[externalOrder($order)],$now);
    externalWindowSame(false,$blocked['allowed'],$program.' may not submit at D+45');
    externalWindowSame($expected,$blocked['not_before'],$program.' must use refund D+60');
    $allowed=SvAmazonSafeTExternalWindow::inspect(
        $case,[externalOrder($order)],new DateTimeImmutable($expected,new DateTimeZone('UTC')));
    externalWindowSame(true,$allowed['allowed'],$program.' may pass temporal gate at D+60');
}
$legacy=SvAmazonSafeTExternalWindow::inspect(
    ['program'=>'FBA_ONSITE','refund_at'=>'2026-09-07 23:33:01'],
    [externalOrder('2026-04-20 23:59:59')],$now);
externalWindowSame('2026-10-22 23:33:01',$legacy['not_before'],'Pre-cutoff D+45 survives');
externalWindowSame(true,$legacy['allowed'],'Pre-cutoff date may pass after D+45');
$unknown=SvAmazonSafeTExternalWindow::inspect(
    ['program'=>'FBA_ONSITE','refund_at'=>'2026-09-07 23:33:01'],[],$now);
externalWindowSame(false,$unknown['allowed'],'Missing ORDER_SYNCED date must fail closed');
$conflicting=SvAmazonSafeTExternalWindow::inspect(
    ['program'=>'FBA_ONSITE','refund_at'=>'2026-09-07 23:33:01'],
    [externalOrder('2026-08-11 16:13:06'),externalOrder('2026-08-12 16:13:06')],$now);
externalWindowSame(false,$conflicting['allowed'],'Conflicting order dates must fail closed');
$badRefund=SvAmazonSafeTExternalWindow::inspect(
    ['program'=>'DELIVERY_BY_AMAZON','refund_at'=>'2026-09-99 23:33:01'],
    [externalOrder('2026-08-11 16:13:06')],$now);
externalWindowSame(false,$badRefund['allowed'],'Invalid refund time must fail closed');
$standard=SvAmazonSafeTExternalWindow::inspect(['program'=>'STANDARD'],[],$now);
externalWindowSame(true,$standard['allowed'],'The D60 exception must not change other programs');
$unknownProgram=SvAmazonSafeTExternalWindow::inspect(['program'=>'UNKNOWN'],[],$now);
externalWindowSame(false,$unknownProgram['allowed'],'Unknown program must fail closed');
echo "safe-t-external-window-test: OK\n";
