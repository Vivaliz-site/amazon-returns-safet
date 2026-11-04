<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTExternalWindow.php';

function expiredAssert(bool $ok,string $reason):void{
    if(!$ok)throw new RuntimeException('SAFE_T_EXPIRED_OUTBOX: '.$reason);
}

expiredAssert(method_exists(SvAmazonSafeTExternalWindow::class,'openingPermanentlyExpired'),
    'terminal classifier absent');
$case=['program'=>'FBA_ONSITE','refund_at'=>'2026-08-01 12:00:00'];
$expired=SvAmazonSafeTExternalWindow::inspect($case,[],
    new DateTimeImmutable('2026-10-30 12:00:00',new DateTimeZone('UTC')));
$within=SvAmazonSafeTExternalWindow::inspect($case,[],
    new DateTimeImmutable('2026-10-30 11:59:59',new DateTimeZone('UTC')));
$early=SvAmazonSafeTExternalWindow::inspect($case,[],
    new DateTimeImmutable('2026-08-02 12:00:00',new DateTimeZone('UTC')));
$unsupported=SvAmazonSafeTExternalWindow::inspect(['program'=>'STANDARD'],[],
    new DateTimeImmutable('2026-11-04T00:00:00Z'));

expiredAssert(SvAmazonSafeTExternalWindow::openingPermanentlyExpired($expired),
    'D90 expired must close pending write permanently');
foreach(['D89'=>$within,'D1'=>$early,'unsupported'=>$unsupported] as $label=>$gate){
    expiredAssert(!SvAmazonSafeTExternalWindow::openingPermanentlyExpired($gate),
        'must not terminally close '.$label);
}
foreach([
    '../includes/amazon-returns/BridgeService.php'=>'polling',
    '../workers/amazon-returns/daemon.php'=>'direct',
] as $path=>$label){
    $source=(string)file_get_contents(__DIR__.'/'.$path);
    $idx=strpos($source,'$gate=SvAmazonSafeTExternalWindow::inspect(');
    expiredAssert($idx!==false,$label.' must check clock');
    $scope=substr($source,$idx,1400);
    $classify=strpos($scope,'SvAmazonSafeTExternalWindow::openingPermanentlyExpired($gate)');
    $supersede=strpos($scope,'->outbox->markSuperseded(');
    $defer=strpos($scope,'->outbox->defer(');
    expiredAssert($classify!==false && $supersede!==false && $defer!==false
        && $classify<$supersede && $supersede<$defer,
        $label.' must terminally close D90 before retry/defer');
}
echo "safe-t-expired-outbox-terminal-test: OK\n";
