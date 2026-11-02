<?php
declare(strict_types=1);
require_once __DIR__.'/../workers/amazon-returns/daemon.php';

function cycleAssert(bool $valid,string $message):void{
    if(!$valid)throw new RuntimeException($message);
}
$secret='GMAIL_OAUTH_REFRESH_TOKEN=PRIVATE_TEST_VALUE';
$payment='123-1234567-1234567';
$sample=[
    'status'=>'DEGRADED',
    'at'=>'2000-01-01T00:00:00Z',
    'due'=>['bootstrap','gmail','gmail_refund_reconciliation','scheduler','secret-task',$secret],
    'results'=>[
        'bootstrap'=>['status'=>'OK','case_id'=>$payment],
        'gmail'=>['status'=>'FAILED','error'=>$secret,'order_id'=>$payment],
        'gmail_refund_reconciliation'=>['status'=>'FAILED','error_class'=>'RuntimeException','reason'=>$secret],
        'scheduler'=>['status'=>'OK','decision_audit'=>[['order_id'=>$payment,'auth'=>$secret]]],
        'secret-task'=>['status'=>'FAILED','message'=>$secret],
        'financial'=>['status'=>"TOKEN_".$secret],
    ],
    'headers'=>['Authorization'=>'Bearer '.$secret],
];
$out=sv_amazon_returns_cycle_summary($sample);
$encoded=json_encode($out,JSON_THROW_ON_ERROR);
cycleAssert($out['event']==='cycle_summary','Cycle summary event name.');
cycleAssert($out['status']==='DEGRADED','Overall health status only.');
cycleAssert(($out['task_statuses']['gmail'] ?? null)==='FAILED','Failed Gmail must be observable.');
cycleAssert(($out['task_statuses']['gmail_refund_reconciliation'] ?? null)==='FAILED','Failed reconciliation must be observable.');
cycleAssert(($out['task_statuses']['scheduler'] ?? null)==='OK','Scheduler completion observable.');
cycleAssert(($out['task_statuses']['financial'] ?? null)==='UNKNOWN','Reject dynamic/unbounded status values.');
cycleAssert($out['due']===['bootstrap','gmail','gmail_refund_reconciliation','scheduler'],'Known tasks only.');
foreach([$secret,$payment,'secret-task','2000-01-01','order_id','decision_audit','Authorization','case_id','reason','error'] as $forbidden){
    cycleAssert(!str_contains($encoded,$forbidden),'Summary leaks raw evidence: '.$forbidden);
}
cycleAssert(strlen($encoded)<2048,'Summary must fit under conservative journald line limit.');
$large=$sample;
$large['results']['scheduler']['decision_audit']=array_fill(0,500,['order_id'=>$payment,'auth'=>$secret]);
cycleAssert(strlen(json_encode(sv_amazon_returns_cycle_summary($large),JSON_THROW_ON_ERROR))<2048,
    'Audit chunk growth cannot enlarge the bounded summary.');
$source=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
cycleAssert(str_contains($source,'sv_amazon_returns_cycle_summary($result)'),
    'Operational entrypoint must emit summary before detailed log.');
cycleAssert(strpos($source,'sv_amazon_returns_cycle_summary($result)')
    < strpos($source,'foreach(sv_amazon_returns_decision_audit_chunks($result)'),
    'Compact cycle event must be journaled first.');
echo "daemon-cycle-summary-test: OK\n";
