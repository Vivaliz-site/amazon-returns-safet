<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/amazon-returns/Runtime.php';

function fprAssert(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
}

fprAssert(method_exists(SvAmazonReturnsRuntime::class,'financialPipelineRevision'),
    'Runtime must fingerprint the financial reconciliation pipeline.');
$revision=SvAmazonReturnsRuntime::financialPipelineRevision();
fprAssert(preg_match('/^[a-f0-9]{64}$/',$revision)===1,
    'Financial pipeline revision must be SHA-256.');

$now=new DateTimeImmutable('2026-09-16T07:30:00Z');
$recent=$now->modify('-60 seconds')->format(DATE_ATOM);
$state=[];
foreach(SvAmazonReturnsRuntime::cadences() as $task=>$seconds)$state[$task]=$recent;
$state['decision_stack_revision']=SvAmazonReturnsRuntime::decisionStackRevision();
$state['gmail_evidence_revision']=SvAmazonReturnsRuntime::gmailEvidenceRevision();
$state['outbox_stack_revision']=SvAmazonReturnsRuntime::outboxStackRevision();
$state['gmail_client_revision']=SvAmazonReturnsRuntime::gmailClientRevision();
$state['financial_pipeline_revision']='stale-revision';

$due=SvAmazonReturnsRuntime::dueTasks(
    $state,$now,$state['decision_stack_revision'],$state['gmail_evidence_revision'],
    $state['outbox_stack_revision'],$state['gmail_client_revision'],$revision
);
foreach(['sp_api','financial','scheduler'] as $task){
    fprAssert(in_array($task,$due,true),
        "A changed financial pipeline must force {$task} immediately.");
}
$ordered=SvAmazonReturnsRuntime::decisionSafeOrder($due);
fprAssert(array_search('sp_api',$ordered,true)<array_search('financial',$ordered,true),
    'SP-API refresh must run before financial reconciliation.');
fprAssert(array_search('financial',$ordered,true)<array_search('scheduler',$ordered,true),
    'Financial reconciliation must run before scheduler reevaluation.');

$state['financial_pipeline_revision']=$revision;
$current=SvAmazonReturnsRuntime::dueTasks(
    $state,$now,$state['decision_stack_revision'],$state['gmail_evidence_revision'],
    $state['outbox_stack_revision'],$state['gmail_client_revision'],$revision
);
fprAssert(!in_array('sp_api',$current,true) && !in_array('financial',$current,true),
    'A current financial pipeline revision must not repeat the forced refresh.');

$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
fprAssert(str_contains($daemon,'financial_pipeline_revision'),
    'Daemon must persist the financial pipeline revision after a complete successful refresh.');
fprAssert(str_contains($daemon,"($results['sp_api']['rotation_has_more'] ?? true)===false"),
    'Daemon must not acknowledge the revision while the SP-API rotation is incomplete.');
fprAssert(str_contains($daemon,"($results['financial']['rotation_has_more'] ?? true)===false"),
    'Daemon must not acknowledge the revision while financial reconciliation rotation is incomplete.');

echo "financial-pipeline-revision-test: OK\n";
