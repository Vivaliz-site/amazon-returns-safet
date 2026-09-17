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
if(!method_exists(SvAmazonReturnsRuntime::class,'gmailClientRevision')){
    throw new RuntimeException('Runtime must fingerprint the Gmail API client revision.');
}
$revision=SvAmazonReturnsRuntime::gmailEvidenceRevision();
gerAssert(preg_match('/^[a-f0-9]{64}$/',$revision)===1,'Gmail evidence revision must be SHA-256.');
$clientRevision=SvAmazonReturnsRuntime::gmailClientRevision();
gerAssert(preg_match('/^[a-f0-9]{64}$/',$clientRevision)===1,'Gmail client revision must be SHA-256.');
$legacyClientRevision=hash_file('sha256',__DIR__.'/../includes/amazon-returns/GmailApi.php');
gerAssert(is_string($legacyClientRevision) && $legacyClientRevision!==$clientRevision,
    'The history-probe contract must advance the legacy GmailApi-only revision exactly once.');
$probeV1Revision=is_string($legacyClientRevision)?hash('sha256','history-probe-v1|'.$legacyClientRevision):'';
$probeV2Revision=is_string($legacyClientRevision)?hash('sha256','history-probe-v2|'.$legacyClientRevision):'';
$probeV3Revision=is_string($legacyClientRevision)?hash('sha256','history-probe-v3|'.$legacyClientRevision):'';
gerAssert($clientRevision!==$probeV2Revision,
    'The rate-limit backoff v3 contract must advance the already persisted v2 revision exactly once.');
gerAssert($clientRevision!==$probeV3Revision,
    'The bounded catch-up contract must advance the already persisted rate-limit v3 revision exactly once.');
gerAssert($clientRevision!==$probeV1Revision,
    'The rate-limit backoff v3 contract must remain distinct from the persisted v1 revision.');
gerAssert(method_exists(SvAmazonReturnsRuntime::class,'operationalTaskMetadata'),
    'Runtime must expose safe operational task metadata.');
$probeMeta=SvAmazonReturnsRuntime::operationalTaskMetadata('gmail_history_probe',[
    'status'=>'FAILED','error_class'=>'RuntimeException',
    'error'=>'Gmail API HTTP 403 reason=insufficientPermissions.',
]);
gerSame(['status'=>'FAILED','error_class'=>'RuntimeException','error'=>'Gmail API HTTP 403 reason=insufficientPermissions.'],$probeMeta,
    'Gmail history probe metadata must retain only the already-sanitized failure cause.');
$gmailMeta=SvAmazonReturnsRuntime::operationalTaskMetadata('gmail',[
    'status'=>'OK','has_more'=>true,'messages'=>25,'events'=>7,'checkpoint_advanced'=>true,
    'subject'=>'SECRET SUBJECT','body_text'=>'SECRET BODY','to'=>'secret@example.com','access_token'=>'secret',
]);
gerSame(['status'=>'OK','has_more'=>true,'messages'=>25,'events'=>7,'checkpoint_advanced'=>true],$gmailMeta,
    'Gmail catch-up metadata must persist only bounded progress fields.');
$gmailRateMeta=SvAmazonReturnsRuntime::operationalTaskMetadata('gmail',[
    'status'=>'FAILED','error_class'=>'RuntimeException','error'=>'Gmail API HTTP 403 reason=rateLimitExceeded.',
    'raw_response'=>'secret raw body','authorization'=>'Bearer secret',
]);
gerSame(['status'=>'FAILED','quota_error'=>'RATE_LIMITED'],$gmailRateMeta,
    'Gmail quota failure metadata must retain only safe classification, never raw error content.');
foreach(['subject','body_text','to','access_token','raw_response','authorization','error','error_class'] as $forbidden){
    gerAssert(!array_key_exists($forbidden,$gmailMeta) && !array_key_exists($forbidden,$gmailRateMeta),
        'Catch-up metadata must not persist sensitive field '.$forbidden);
}
$utf8Meta=SvAmazonReturnsRuntime::operationalTaskMetadata('gmail_history_probe',[
    'status'=>'FAILED','error'=>str_repeat('a',599).'á'.'z',
]);
gerAssert(isset($utf8Meta['error']) && strlen($utf8Meta['error'])<=600 && mb_check_encoding($utf8Meta['error'],'UTF-8'),
    'Probe metadata truncation must preserve valid UTF-8 within the 600-byte bound.');

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
$state['gmail_client_revision']='stale-client-revision';
$dueClient=SvAmazonReturnsRuntime::dueTasks(
    $state,$now,$state['decision_stack_revision'],$revision,null,$clientRevision
);
gerAssert(in_array('gmail_history_probe',$dueClient,true),
    'A Gmail API client revision must force exactly one read-only incremental-history probe.');
$state['gmail_client_revision']=$clientRevision;
$dueCurrent=SvAmazonReturnsRuntime::dueTasks(
    $state,$now,$state['decision_stack_revision'],$revision,null,$clientRevision
);
gerAssert(!in_array('gmail_history_probe',$dueCurrent,true),
    'A current Gmail client revision must not repeat the probe.');

$ordered=SvAmazonReturnsRuntime::decisionSafeOrder($due);
$gmailPos=array_search('gmail_refund_reconciliation',$ordered,true);
$schedulerPos=array_search('scheduler',$ordered,true);
gerAssert(is_int($gmailPos) && is_int($schedulerPos) && $gmailPos<$schedulerPos,
    'Forced Gmail reconciliation must run before scheduler reevaluation.');

$orderedClient=SvAmazonReturnsRuntime::decisionSafeOrder($dueClient);
gerAssert(in_array('gmail_history_probe',$orderedClient,true),
    'The read-only Gmail history probe must survive task ordering.');
gerAssert(!in_array('scheduler',$orderedClient,true),
    'A diagnostic Gmail probe must not force scheduler reevaluation because it persists no new evidence.');

$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
gerAssert(str_contains($daemon,"gmail_evidence_revision"),
    'Daemon must persist the successfully applied Gmail evidence revision.');
gerAssert(str_contains($daemon,"gmail_client_revision"),
    'Daemon must persist a Gmail client revision after one read attempt so failures respect normal cadence.');

gerAssert(str_contains($daemon,"'gmail_history_probe'=>\$this->runGmailHistoryProbe()"),
    'Daemon must expose a dedicated Gmail history probe task.');
$probeStart=strpos($daemon,'private function runGmailHistoryProbe');
$probeEnd=$probeStart===false?false:strpos($daemon,'private function runGmail',$probeStart+10);
gerAssert($probeStart!==false && $probeEnd!==false,'Gmail history probe must remain independently auditable.');
$probeBody=substr($daemon,(int)$probeStart,(int)$probeEnd-(int)$probeStart);
gerAssert(str_contains($probeBody,'->pullIncrementalBatch($cursor,1,self::GMAIL_CATCHUP_MESSAGE_LIMIT)'),'Probe must execute the same incremental Gmail pull path with a bounded bootstrap.');
gerAssert(!str_contains($probeBody,'->send') && !str_contains($probeBody,'saveCursor'),'Probe must not send email or advance the production Gmail cursor.');
echo "gmail-evidence-revision-reconciliation-test: OK\n";
