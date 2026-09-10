<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/amazon-returns/Runtime.php';

function gerAssert(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
}
function gerSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.json_encode($expected).' actual='.json_encode($actual));
}

if(!method_exists(SvAmazonReturnsRuntime::class,'gmailEvidenceRevision')){
    throw new RuntimeException('Runtime must fingerprint Gmail parser/sink revisions.');
}
$revision=SvAmazonReturnsRuntime::gmailEvidenceRevision();
gerAssert(preg_match('/^[a-f0-9]{64}$/',$revision)===1,'Gmail evidence revision must be SHA-256.');

$now=new DateTimeImmutable('2026-09-09T15:00:00Z');
$recent=$now->modify('-60 seconds')->format(DATE_ATOM);
$state=[];
foreach(SvAmazonReturnsRuntime::cadences() as $task=>$seconds)$state[$task]=$recent;
$state['decision_stack_revision']=SvAmazonReturnsRuntime::decisionStackRevision();
$state['gmail_evidence_revision']='stale-revision';
$due=SvAmazonReturnsRuntime::dueTasks(
    $state,$now,$state['decision_stack_revision'],$revision
);
gerAssert(in_array('gmail_refund_reconciliation',$due,true),
    'A Gmail evidence code revision must force one reconciliation immediately.');

$state['gmail_evidence_revision']=$revision;
$dueCurrent=SvAmazonReturnsRuntime::dueTasks(
    $state,$now,$state['decision_stack_revision'],$revision
);
gerAssert(!in_array('gmail_refund_reconciliation',$dueCurrent,true),
    'A current Gmail evidence revision must respect the normal 12-hour cadence.');

$ordered=SvAmazonReturnsRuntime::decisionSafeOrder($due);
$gmailPos=array_search('gmail_refund_reconciliation',$ordered,true);
$schedulerPos=array_search('scheduler',$ordered,true);
gerAssert(is_int($gmailPos) && is_int($schedulerPos) && $gmailPos<$schedulerPos,
    'Forced Gmail reconciliation must run before scheduler reevaluation.');

$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
gerAssert(str_contains($daemon,"gmail_evidence_revision"),
    'Daemon must persist the successfully applied Gmail evidence revision.');
echo "gmail-evidence-revision-reconciliation-test: OK\n";
