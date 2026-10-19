<?php
declare(strict_types=1);
function ssprcAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
ssprcAssert(substr_count($worker,"reason: 'SUPPORT_CASE_LOOKUP_UNAVAILABLE_AFTER_WRITE'")===2,'Both post-write support creation paths must preserve reconciliation handling.');
ssprcAssert(substr_count($worker,"lookup_phase: 'POST_WRITE_RECONCILIATION'")===2,'Post-write lookup failures must be explicitly classified as reconciliation, never as a fresh write.');
$needle="bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CASE_LOOKUP_UNAVAILABLE_AFTER_WRITE'";
ssprcAssert(substr_count($worker,$needle)===2,'Post-write lookup unavailability must defer through UI_DRIFT so the existing outbox row can be reconciled later.');
ssprcAssert(substr_count($worker,"submitted: true, retry_safe: true")>=2,'Post-write uncertainty must record that the write may already have happened and remain eligible only for guarded reconciliation.');
ssprcAssert(!str_contains($worker,"bridgeResult('FAILED', { reason: 'SUPPORT_CASE_LOOKUP_UNAVAILABLE_AFTER_WRITE'"),'Post-write lookup unavailability must never terminally orphan a potentially submitted support case.');
$openStart=strpos($worker,'async function supportOpen');
$openEnd=$openStart===false?false:strpos($worker,'async function supportUpdate',$openStart);
ssprcAssert($openStart!==false&&$openEnd!==false,'supportOpen must remain auditable.');
$open=substr($worker,(int)$openStart,(int)$openEnd-(int)$openStart);
ssprcAssert(str_contains($open,'const retryReconciliation = job.retry_reconciliation === true'),'Only a server-authorized retry episode may enter reconciliation mode before any support write.');
ssprcAssert(!str_contains($open,'job.attempt_count'),'Post-write reconciliation must not infer episode identity from the raw attempt counter.');
ssprcAssert(strpos($open,'findSupportCase(cdp, job') < strpos($open,'await cdp.navigate(HELP_URL'),'Retry reconciliation must execute before navigating into a fresh support write flow.');
echo "seller-support-postwrite-reconciliation-test: OK\n";
