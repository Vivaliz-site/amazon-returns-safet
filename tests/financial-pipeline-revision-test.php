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
$state['decision_stack_revision']='stale-revision';
$state['gmail_evidence_revision']=SvAmazonReturnsRuntime::gmailEvidenceRevision();
$state['outbox_stack_revision']=SvAmazonReturnsRuntime::outboxStackRevision();
$state['gmail_client_revision']=SvAmazonReturnsRuntime::gmailClientRevision();

$currentDecisionRevision=SvAmazonReturnsRuntime::decisionStackRevision();
$due=SvAmazonReturnsRuntime::dueTasks(
    $state,$now,$currentDecisionRevision,$state['gmail_evidence_revision'],
    $state['outbox_stack_revision'],$state['gmail_client_revision']
);
foreach(['sp_api','financial','scheduler'] as $task){
    fprAssert(in_array($task,$due,true),
        "A changed decision/financial pipeline must force {$task} immediately.");
}
$ordered=SvAmazonReturnsRuntime::decisionSafeOrder($due);
fprAssert(array_search('sp_api',$ordered,true)<array_search('financial',$ordered,true),
    'SP-API refresh must run before financial reconciliation.');
fprAssert(array_search('financial',$ordered,true)<array_search('scheduler',$ordered,true),
    'Financial reconciliation must run before scheduler reevaluation.');

$state['decision_stack_revision']=$currentDecisionRevision;
$current=SvAmazonReturnsRuntime::dueTasks(
    $state,$now,$currentDecisionRevision,$state['gmail_evidence_revision'],
    $state['outbox_stack_revision'],$state['gmail_client_revision']
);
fprAssert(!in_array('sp_api',$current,true) && !in_array('financial',$current,true),
    'A current pipeline revision must not repeat a forced refresh by itself.');

fprAssert(SvAmazonReturnsRuntime::financialRefreshContinuationRequired([
    'sp_api'=>['rotation_has_more'=>true,'cycle_failures'=>0],
    'financial'=>['rotation_has_more'=>false],
    'scheduler'=>['financial_checks_requested'=>0],
]),'Once a SP-API refresh starts, an incomplete rotation must continue even without queued checks.');
fprAssert(SvAmazonReturnsRuntime::financialRefreshContinuationRequired([
    'sp_api'=>['rotation_has_more'=>false,'cycle_failures'=>0],
    'financial'=>['rotation_has_more'=>true],
    'scheduler'=>['financial_checks_requested'=>0],
]),'Once financial reconciliation starts, an incomplete rotation must continue even without queued checks.');
fprAssert(!SvAmazonReturnsRuntime::financialRefreshContinuationRequired([
    'sp_api'=>['rotation_has_more'=>false,'cycle_failures'=>0],
    'financial'=>['rotation_has_more'=>false],
    'scheduler'=>['financial_checks_requested'=>0],
]),'Completed successful rotations must return to normal cadence.');

$runtime=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/Runtime.php');
fprAssert(str_contains($runtime,"'financial_pipeline:'.self::financialPipelineRevision()"),
    'Decision stack revision must include the financial pipeline fingerprint so the existing daemon state marker applies it exactly once.');

echo "financial-pipeline-revision-test: OK\n";
