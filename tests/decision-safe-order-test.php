<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Runtime.php';
function dsAssert(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
// Execute the actual daemon task-loop body with instrumented task dispatch and cursor persistence.
$source=file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
$start=strpos($source,'        foreach($due as $task){');
$end=strpos($source,"        if(\n            \$decisionStackChanged",$start);

$loop=substr($source,$start,$end-$start);
$harness=new class {
    public array $calls=[];
    public ?bool $incomplete=true;
    public object $config;
    public object $persistence;
    public function __construct(){
        $this->config=new class { function enabled(){return false;} };
        $this->persistence=(object)['cursors'=>new class { function save(...$args){} }];
    }
    public function runTask($task,$now){$this->calls[]=$task;return $task==='gmail'?($this->incomplete===null?['status'=>'FAILED']:['status'=>'OK','has_more'=>$this->incomplete]):['status'=>'OK'];}
};
$execute=function($loop,$incomplete,$state=[],$gmailDue=true) {
    $this->incomplete=$incomplete;$this->calls=[];
    $due=SvAmazonReturnsRuntime::decisionSafeOrder(['gmail','known_action_wake','scheduler','seller_central','review_operations','erp_sales_returns','sp_api','financial','health']);
    if(!$gmailDue)$due=array_values(array_filter($due,fn($t)=>$t!=='gmail'));
    $fixedNow=true;$now=new DateTimeImmutable('2026-09-16T12:00:00Z');$results=[];
    eval($loop);
    return [$this->calls,$results,$state];
};
foreach([true,false] as $incomplete){
    [$calls,$results,$state]=$execute->call($harness,$loop,$incomplete);
    foreach(['scheduler','seller_central','review_operations','erp_sales_returns'] as $task){
        dsAssert(in_array($task,$calls,true)!==$incomplete,'Incomplete evidence must gate '.$task);
        if($incomplete) dsAssert(($results[$task]['reason']??null)==='GMAIL_CATCHUP_INCOMPLETE','Explicit skip reason for '.$task);
    }
    foreach(['sp_api','financial','health'] as $task)dsAssert(in_array($task,$calls,true),'Independent read must continue: '.$task);
    dsAssert(count(array_filter($calls,fn($t)=>$t==='gmail'))===($incomplete?1:2),'Known-date second Gmail pass must not bypass incomplete-cycle gate.');
    if(!$incomplete) dsAssert(array_search('financial',$calls)<array_search('scheduler',$calls),'Complete evidence preserves ordering.');
}
foreach([false,true] as $gmailDue){
    [$calls,$results,$state]=$execute->call($harness,$loop,null,['gmail_catchup_pending'=>'1'],$gmailDue);
    dsAssert(!in_array('scheduler',$calls,true),'Pending catch-up remains gated between retries and after failed retry.');
    dsAssert(!isset($state['scheduler']),'Skipped scheduler must not consume known deadline.');
}
[$calls,$results,$state]=$execute->call($harness,$loop,false,['gmail_catchup_pending'=>'1']);
dsAssert(in_array('scheduler',$calls,true) && $state['gmail_catchup_pending']==='0','Completion clears pending gate.');

// A quota/error retry while a prior catch-up is pending must fail closed even when the failed Gmail result has no has_more key.
[$failedCalls,$failedResults]=$execute->call($harness,$loop,null,['gmail_catchup_pending'=>'1']);
foreach(['scheduler','seller_central','review_operations','erp_sales_returns'] as $task){
    dsAssert(!in_array($task,$failedCalls,true),'Pending catch-up must remain authoritative across Gmail failure: '.$task);
    dsAssert(($failedResults[$task]['reason']??null)==='GMAIL_CATCHUP_INCOMPLETE','Failed retry must retain explicit incomplete reason for '.$task);
}
foreach(['sp_api','financial','health'] as $task)dsAssert(in_array($task,$failedCalls,true),'Read-side work must continue after Gmail quota failure: '.$task);

echo "decision-safe-order-test: OK\n";
