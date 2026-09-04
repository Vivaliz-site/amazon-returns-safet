<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/ShadowAudit.php';

function shSame(mixed $expected,mixed $actual,string $message):void {
    if($expected!==$actual) throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true));
}

$source=['id'=>2,'amazon_order_id'=>'702-1','state'=>'SAFE_T_DENIED','refund_amount'=>'68.29','updated_at'=>'2026-09-04 10:00:00'];
$target=$source;
$target['updated_at']='2026-09-04 11:00:00';
shSame([],SvAmazonReturnsShadowAudit::caseDiff($source,$target),'Operational timestamps must not create shadow mismatches.');

$target['refund_amount']='136.58';
$diff=SvAmazonReturnsShadowAudit::caseDiff($source,$target);
shSame(['refund_amount'=>['source'=>'68.29','target'=>'136.58']],$diff,'Economic amount differences must be reported.');

shSame([],SvAmazonReturnsShadowAudit::decisionDiff(['action'=>'WAIT','reason'=>'X'],['action'=>'WAIT','reason'=>'X']),'Equal decisions must match.');
$dd=SvAmazonReturnsShadowAudit::decisionDiff(['action'=>'SAFE_T_APPEAL','reason'=>'DENIED'],['action'=>'WAIT','reason'=>'SAFE_T_ALREADY_EXISTS']);
shSame('SAFE_T_APPEAL',$dd['action']['source'] ?? null,'Decision action mismatch must be explicit.');

$script=__DIR__.'/../scripts/shadow-audit.php';
shSame(true,is_file($script),'Shadow audit CLI must exist.');
$scriptText=(string)file_get_contents($script);
shSame(true,str_contains($scriptText,'shopvivaliz'),'Shadow audit must compare the source database.');
shSame(true,str_contains($scriptText,'amazon_returns_safet'),'Shadow audit must compare the isolated database.');
shSame(true,str_contains($scriptText,'SvAmazonSafeTDecisionEngine'),'Shadow audit must compare real decision outputs.');

echo "shadow-audit-test: OK\n";
