<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/BridgeLiveness.php';

function blSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}
function blAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$now=new DateTimeImmutable('2026-09-08T12:00:00Z');
$fresh=[
    'value'=>'vm-a1-safe-t-status',
    'metadata'=>['status'=>'AUTHENTICATED'],
    'observed_at'=>'2026-09-07 13:00:00',
];
$process=[
    'value'=>'vm-a1-safe-t-status',
    'metadata'=>['status'=>'ALIVE','role'=>'READ'],
    'observed_at'=>'2026-09-08 11:59:00',
];
$ok=SvAmazonBridgeLiveness::evaluate($fresh,$now,true,$process);
blSame('OK',$ok['status'],'Authenticated browser observation inside 30 hours must be healthy.');
blSame('vm-a1-safe-t-status',$ok['worker_id'],'Health must identify the active browser worker without exposing secrets.');
blAssert(($ok['age_seconds']??0)>0,'Health must expose heartbeat age.');

$stale=$fresh;$stale['observed_at']='2026-09-07 05:00:00';
$staleResult=SvAmazonBridgeLiveness::evaluate($stale,$now,true,$process);
blSame('DEGRADED',$staleResult['status'],'Browser authentication older than 30 hours must degrade health.');
blSame('STALE_BROWSER_AUTH',$staleResult['reason'],'Stale auth must have an explicit reason.');

$failed=$fresh;$failed['metadata']['status']='TOTP_UNAVAILABLE';$failed['observed_at']='2026-09-08 11:55:00';
$failedResult=SvAmazonBridgeLiveness::evaluate($failed,$now,true,$process);
blSame('DEGRADED',$failedResult['status'],'A fresh failed MFA/auth check must degrade health.');
blSame('TOTP_UNAVAILABLE',$failedResult['reason'],'Health must retain the non-secret auth failure reason.');

$missing=SvAmazonBridgeLiveness::evaluate(null,$now,true,$process);
blSame('DEGRADED',$missing['status'],'Configured Seller Central bridge without auth evidence must not report healthy.');
blSame('NO_BROWSER_AUTH_OBSERVATION',$missing['reason'],'Missing auth evidence must be explicit.');

$staleProcess=$process;$staleProcess['observed_at']='2026-09-07 05:00:00';
$staleProcessResult=SvAmazonBridgeLiveness::evaluate($fresh,$now,true,$staleProcess);
blSame('DEGRADED',$staleProcessResult['status'],'Stale read-process heartbeat must degrade health even when browser auth is fresh.');
blSame('STALE_READ_PROCESS_HEARTBEAT',$staleProcessResult['reason'],'Stale read-process heartbeat must be explicit.');
$missingProcess=SvAmazonBridgeLiveness::evaluate($fresh,$now,true,null);
blSame('DEGRADED',$missingProcess['status'],'Required bridge without process heartbeat must degrade health.');
blSame('NO_READ_PROCESS_HEARTBEAT',$missingProcess['reason'],'Missing process heartbeat must be explicit.');

$optional=SvAmazonBridgeLiveness::evaluate(null,$now,false);
blSame('NOT_REQUIRED',$optional['status'],'Absent optional bridge must not degrade API-only operation.');

echo "bridge-liveness-health-test: OK\n";
