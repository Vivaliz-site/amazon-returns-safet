<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTExternalWindow.php';

function externalWindowSame(mixed $want,mixed $got,string $message):void{
    if($want!==$got)throw new RuntimeException($message.' expected='.var_export($want,true).' actual='.var_export($got,true));
}
function externalOrder(string $at):array{
    return ['event_type'=>'ORDER_SYNCED','source'=>'SP_API_ORDERS','payload'=>['order_at'=>$at]];
}
foreach([
    ['FBA_ONSITE','2026-08-11 16:13:06','2026-09-07 23:33:01','2026-10-22 23:33:01'],
    ['DELIVERY_BY_AMAZON','2026-08-25 13:55:32','2026-09-09 18:38:59','2026-10-24 18:38:59'],
] as [$program,$order,$refund,$expected]){
    $case=['program'=>$program,'refund_at'=>$refund];
    $due=new DateTimeImmutable($expected,new DateTimeZone('UTC'));
    $before=SvAmazonSafeTExternalWindow::inspect($case,[externalOrder($order)],$due->modify('-1 second'));
    externalWindowSame(false,$before['allowed'],$program.' must not open before D+45');
    externalWindowSame($expected,$before['not_before'],$program.' first opening is refund D+45');
    externalWindowSame('D45_OPENING_PENDING',$before['reason'],'Early opening reason');
    $allowed=SvAmazonSafeTExternalWindow::inspect($case,[externalOrder($order)],$due);
    externalWindowSame(true,$allowed['allowed'],$program.' may begin eligibility check at D+45');
    externalWindowSame('D45_OPENING_REACHED',$allowed['reason'],'Due opening reason');
    $missingOrder=SvAmazonSafeTExternalWindow::inspect($case,[],$due);
    externalWindowSame(true,$missingOrder['allowed'],'Missing order timestamp must not add an unrelated delay');
    externalWindowSame(null,$missingOrder['order_at'],'Missing order timestamp remains unverified');
}
// The owner permits initial openings only within D+45..D+89.
$aging=['program'=>'FBA_ONSITE','refund_at'=>'2026-08-01 12:00:00'];
$d89=SvAmazonSafeTExternalWindow::inspect($aging,[],new DateTimeImmutable('2026-10-30 11:59:59',new DateTimeZone('UTC')));
externalWindowSame(true,$d89['allowed'],'D+89 last second remains within owner opening window');
$d90=SvAmazonSafeTExternalWindow::inspect($aging,[],new DateTimeImmutable('2026-10-30 12:00:00',new DateTimeZone('UTC')));
externalWindowSame(false,$d90['allowed'],'D+90 must not permit a new SAFE-T opening');
externalWindowSame('D90_OPENING_EXPIRED',$d90['reason'],'D+90 must return an explicit non-eligible reason');

$conflicting=SvAmazonSafeTExternalWindow::inspect(
    ['program'=>'FBA_ONSITE','refund_at'=>'2026-09-07 23:33:01'],
    [externalOrder('2026-08-11 16:13:06'),externalOrder('2026-08-12 16:13:06')],
    new DateTimeImmutable('2026-10-30 23:30:00',new DateTimeZone('UTC')));
externalWindowSame(false,$conflicting['allowed'],'Conflicting provenance remains blocked');
externalWindowSame('ORDER_AT_CONFLICTING',$conflicting['reason'],'Conflicting provenance reason');
$invalidOrder=SvAmazonSafeTExternalWindow::inspect(
    ['program'=>'FBA_ONSITE','refund_at'=>'2026-09-07 23:33:01'],
    [externalOrder('2026-99-11 16:13:06')],
    new DateTimeImmutable('2026-10-30 23:30:00',new DateTimeZone('UTC')));
externalWindowSame(false,$invalidOrder['allowed'],'Invalid order timestamp must fail closed');
$bridgeSource=file_get_contents(__DIR__.'/../includes/amazon-returns/BridgeService.php');
externalWindowSame(true,is_string($bridgeSource)
    && str_contains($bridgeSource,'SvAmazonSafeTExternalWindow::inspect')
    && str_contains($bridgeSource,"'order_at','refund_at'"),
    'Server pull must enforce D+45 preflight and preserve order/refund context');
$direct=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
$directStart=strpos($direct,'private function runSellerCentral(): array');
$directEnd=strpos($direct,'private function loadState(): array',$directStart===false?0:$directStart);
$directScope=$directStart===false || $directEnd===false ? '' : substr($direct,$directStart,$directEnd-$directStart);
externalWindowSame(true,str_contains($directScope,'SvAmazonSafeTExternalWindow::inspect')
    && str_contains($directScope,'SAFE_T_EXTERNAL_')
    && str_contains($directScope,'$this->persistence->outbox->defer(')
    && str_contains($directScope,'$actionResult=$worker->execute'),
    'Direct Seller Central dispatcher must gate and defer SAFE-T before external execution');
$badRefund=SvAmazonSafeTExternalWindow::inspect(
    ['program'=>'DELIVERY_BY_AMAZON','refund_at'=>'2026-09-99 23:33:01'],
    [externalOrder('2026-08-11 16:13:06')],
    new DateTimeImmutable('2026-10-30 23:30:00',new DateTimeZone('UTC')));
externalWindowSame(false,$badRefund['allowed'],'Invalid refund must fail closed');
$standard=SvAmazonSafeTExternalWindow::inspect(['program'=>'STANDARD'],[],new DateTimeImmutable('now'));
externalWindowSame(true,$standard['allowed'],'Other programs are not modified');
$unknownProgram=SvAmazonSafeTExternalWindow::inspect(['program'=>'UNKNOWN'],[],new DateTimeImmutable('now'));
externalWindowSame(false,$unknownProgram['allowed'],'Unknown program must fail closed');
echo "safe-t-external-window-test: OK\n";
