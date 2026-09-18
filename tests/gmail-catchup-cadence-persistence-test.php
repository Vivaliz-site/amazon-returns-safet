<?php
declare(strict_types=1);
require_once __DIR__.'/../workers/amazon-returns/daemon.php';
function gccpAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function gccpSame(mixed $expected,mixed $actual,string $why):void{
    if($expected!==$actual)throw new RuntimeException($why.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}
final class GmailCadencePdo extends PDO { public function __construct(){} }
$base=tempnam(sys_get_temp_dir(),'gmail-cadence-state-');
@unlink($base);
$config=new SvAmazonReturnsConfig(['AMAZON_RETURNS_RUNTIME_STATE_FILE'=>$base]);
$daemon=new SvAmazonReturnsDaemon(new GmailCadencePdo(),new SvAmazonTenantContext(1,1),$config);
$save=new ReflectionMethod($daemon,'saveState');$save->setAccessible(true);
$load=new ReflectionMethod($daemon,'loadState');$load->setAccessible(true);
$now=new DateTimeImmutable('2026-09-16T12:00:00+00:00');
$retry=SvAmazonReturnsRuntime::gmailCatchupRetryDelaySeconds('gmail',['has_more'=>true]);
$pendingMarker=SvAmazonReturnsRuntime::taskScheduleMarker('gmail',$now,$retry);
gccpSame('2026-09-16T00:05:00+00:00',$pendingMarker,'Five-minute retry must be encoded into persisted Gmail state.');
$save->invoke($daemon,['gmail'=>$pendingMarker,'gmail_catchup_pending'=>'1']);
$persisted=$load->invoke($daemon);
gccpSame($pendingMarker,$persisted['gmail']??null,'Persisted catch-up marker must round-trip unchanged.');
gccpAssert(!in_array('gmail',SvAmazonReturnsRuntime::dueTasks($persisted,$now->modify('+299 seconds')),true),'Gmail must not become due before five minutes.');
gccpAssert(in_array('gmail',SvAmazonReturnsRuntime::dueTasks($persisted,$now->modify('+300 seconds')),true),'Gmail must become due at exactly five minutes.');
$completeMarker=SvAmazonReturnsRuntime::taskScheduleMarker('gmail',$now,null);
gccpSame('2026-09-16T12:00:00+00:00',$completeMarker,'Completed catch-up must persist the real completion time.');
$save->invoke($daemon,['gmail'=>$completeMarker,'gmail_catchup_pending'=>'0']);
$persisted=$load->invoke($daemon);
gccpAssert(!in_array('gmail',SvAmazonReturnsRuntime::dueTasks($persisted,$now->modify('+43199 seconds')),true),'Completed Gmail must not be due before twelve hours.');
gccpAssert(in_array('gmail',SvAmazonReturnsRuntime::dueTasks($persisted,$now->modify('+43200 seconds')),true),'Completed Gmail must resume at exactly twelve hours.');
$pendingCursor=['metadata'=>['has_more'=>true],'observed_at'=>'2026-09-16 12:00:00'];
$recentAttempt=['metadata'=>['status'=>'FAILED','quota_error'=>'RATE_LIMITED'],'observed_at'=>'2026-09-16 12:04:00'];
$scheduled=SvAmazonReturnsRuntime::reconcileGmailCatchupSchedule(['bootstrap','gmail'],$pendingCursor,$recentAttempt,$now->modify('+539 seconds'));
gccpAssert(!in_array('gmail',$scheduled,true),'Persisted catch-up must suppress an early Gmail rerun after a newer attempt, including HTTP 429.');
$scheduled=SvAmazonReturnsRuntime::reconcileGmailCatchupSchedule(['bootstrap'],$pendingCursor,$recentAttempt,$now->modify('+540 seconds'));
gccpAssert(in_array('gmail',$scheduled,true),'Persisted catch-up must become due five minutes after the latest Gmail attempt even if runtime-state lost its retry marker.');
$scheduled=SvAmazonReturnsRuntime::reconcileGmailCatchupSchedule(['bootstrap'],['metadata'=>['has_more'=>false],'observed_at'=>'2026-09-16 12:00:00'],null,$now->modify('+86400 seconds'));
gccpAssert(!in_array('gmail',$scheduled,true),'A drained persisted Gmail cursor must not create an extra catch-up run.');
$daemonSource=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
gccpAssert(str_contains($daemonSource,'taskScheduleMarker'),'Daemon must use the tested persisted schedule marker path.');
gccpAssert(str_contains($daemonSource,'reconcileGmailCatchupSchedule'),'Daemon must reconcile catch-up cadence from persisted cursors before task ordering.');
@unlink($daemon->stateFilePath());
echo "gmail-catchup-cadence-persistence-test: OK\n";
