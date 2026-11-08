<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/TenantOutbox.php';
require_once __DIR__ . '/../includes/amazon-returns/RemoteBridge.php';

function safetExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$now=new DateTimeImmutable('2026-11-08T20:35:00Z');
$pending=['id'=>3919266,'kind'=>'SAFE_T_SUBMIT','attempt_count'=>5,
    'payload'=>['deadline_at'=>'2026-11-10T23:59:00Z']];
$historical=SvAmazonTenantReturnsOutbox::retryDecision($pending,$now);
safetExpect($historical['status']==='RETRY',
    'Regression fixture must reproduce recovery-window retry for historical rows.');
$protected=SvAmazonTenantReturnsOutbox::retryDecision($pending,$now,false);
safetExpect($protected['status']==='DEAD_LETTER'
    && $protected['next_at']===null
    && $protected['reason']==='UNSAFE_EXTERNAL_WRITE_RETRY',
    'Ambiguous Amazon post-click outcome must never be resubmitted because of a future deadline.');

$earlier=$pending;
$earlier['attempt_count']=1;
safetExpect(SvAmazonTenantReturnsOutbox::retryDecision($earlier,$now,false)['status']==='DEAD_LETTER',
    'A non-retryable first attempt must be terminal even with attempts remaining.');
safetExpect(SvAmazonTenantReturnsOutbox::retryDecision($earlier,$now,true)['status']==='RETRY',
    'Explicitly safe recoverable failures keep normal retry behavior.');

$ambiguousReasons=[
    ['status'=>'FAILED','reason'=>'SAFE_T_WRITE_WITHOUT_READBACK_ID','retry_safe'=>false],
    ['status'=>'FAILED','reason'=>'UNHANDLED_Error','retry_safe'=>true],
    ['status'=>'FAILED','reason'=>'MULTIPLE_SAFE_T_CLAIMS_AFTER_SUBMIT','retry_safe'=>false],
    ['status'=>'HUMAN_INTERVENTION_REQUIRED','reason'=>'SAFE_T_SUBMIT_READBACK_UNVERIFIED'],
];
foreach($ambiguousReasons as $result){
    safetExpect(SvAmazonReturnsRemoteBridge::safeTSubmitResultRequiresReadback($result),
        'Post-click exception or missing readback must require manual reconciliation.');
    $event=['event_type'=>'SELLER_CENTRAL_ACTION_RESULT','source_event_id'=>'3919266',
        'payload'=>array_merge(['action'=>'SAFE_T_SUBMIT'], $result)];
    safetExpect(SvAmazonReturnsRemoteBridge::safeTSubmitHasAmbiguousHistory($pending,[$event]),
        'A previously attempted outbox must be quarantined regardless of its available_at.');
}
$inputMissing=['status'=>'FAILED','reason'=>'SAFE_T_ORDER_INPUT_MISSING'];
safetExpect(!SvAmazonReturnsRemoteBridge::safeTSubmitResultRequiresReadback($inputMissing),
    'A proven pre-submit failure must not be mislabeled post-click.');
$event=['event_type'=>'SELLER_CENTRAL_ACTION_RESULT','source_event_id'=>'3919266',
    'payload'=>['action'=>'SAFE_T_SUBMIT','status'=>'FAILED','reason'=>'SAFE_T_WRITE_WITHOUT_READBACK_ID']];
safetExpect(!SvAmazonReturnsRemoteBridge::safeTSubmitHasAmbiguousHistory(
    $pending,[array_merge($event,['source_event_id'=>'3919265'])]),
    'An unrelated outbox attempt must never contaminate this outbox.');
safetExpect(!SvAmazonReturnsRemoteBridge::safeTSubmitHasAmbiguousHistory(
    $pending,[array_merge($event,['payload'=>['action'=>'SELLER_SUPPORT_OPEN',
        'status'=>'FAILED','reason'=>'SAFE_T_WRITE_WITHOUT_READBACK_ID']])]),
    'An unrelated action must not quarantine a SAFE-T outbox.');
safetExpect(!SvAmazonReturnsRemoteBridge::safeTSubmitHasAmbiguousHistory(
    array_merge($pending,['kind'=>'SELLER_SUPPORT_OPEN']),[$event]),
    'Only SAFE_T_SUBMIT is quarantined by this rule.');
safetExpect(!SvAmazonReturnsRemoteBridge::safeTSubmitHasAmbiguousHistory(
    $pending,[array_merge($event,['event_type'=>'FINANCIAL_RECONCILIATION_CHECKED'])]),
    'Financial refresh does not prove a post-click SAFE-T attempt.');

$bridge=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/BridgeService.php');
$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
safetExpect(str_contains($bridge,'safeTSubmitHasAmbiguousHistory($row,$prior)'),
    'Polling bridge must quarantine ambiguous prior attempts before dispatch.');
safetExpect(str_contains($daemon,'safeTSubmitHasAmbiguousHistory($row,$prior)'),
    'Direct daemon must apply the same pre-dispatch guard.');
safetExpect(str_contains($bridge, "null,$kind!=='SAFE_T_SUBMIT' || ($result['retry_safe'] ?? false)===true"),
    'Polling result handler must prevent retry of non-retryable SAFE-T attempts.');

echo "SAFE_T_AMBIGUOUS_OUTCOME_NO_RETRY=PASS\n";
