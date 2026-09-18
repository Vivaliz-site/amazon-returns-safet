<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Runtime.php';

function rrAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rrSame(mixed $want,mixed $got,string $message):void{if($want!==$got)throw new RuntimeException($message.' expected='.var_export($want,true).' got='.var_export($got,true));}

$pending=['status'=>'PENDING','processing_status'=>'IN_PROGRESS','report_id'=>'r-1'];
rrSame(300,SvAmazonReturnsRuntime::returnsReportRetryDelaySeconds('returns_report',$pending),'Pending Amazon Returns Reports must retry in five minutes.');
rrSame(null,SvAmazonReturnsRuntime::returnsReportRetryDelaySeconds('returns_report',['status'=>'OK','processing_status'=>'DONE']),'Completed report must use normal cadence.');
rrSame(null,SvAmazonReturnsRuntime::returnsReportRetryDelaySeconds('sp_api',$pending),'Retry policy must be scoped to returns_report.');

$at=new DateTimeImmutable('2026-09-18T02:00:00Z');
$marker=SvAmazonReturnsRuntime::taskScheduleMarker('returns_report',$at,300);
$dueAt=$at->modify('+300 seconds');
rrAssert(in_array('returns_report',SvAmazonReturnsRuntime::dueTasks(['returns_report'=>$marker],$dueAt),true),'Pending report must become due again after five minutes.');
rrAssert(!in_array('returns_report',SvAmazonReturnsRuntime::dueTasks(['returns_report'=>$marker],$at->modify('+299 seconds')),true),'Pending report must not hot-loop before five minutes.');

$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
rrAssert(str_contains($daemon,'SvAmazonReturnsRuntime::returnsReportRetryDelaySeconds($task,$results[$task])'),'Daemon must apply returns-report continuation scheduling.');
echo "returns-report-pending-retry-test: OK\n";
