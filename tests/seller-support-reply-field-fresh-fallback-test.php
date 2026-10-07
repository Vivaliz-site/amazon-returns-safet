<?php
declare(strict_types=1);
function ssrfAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');

$updateStart=strpos($worker,'async function supportUpdate');
$updateEnd=$updateStart===false?false:strpos($worker,'async function executeJob',$updateStart);
ssrfAssert($updateStart!==false&&$updateEnd!==false,'supportUpdate must remain independently auditable.');
$update=substr($worker,(int)$updateStart,(int)$updateEnd-(int)$updateStart);
$composer=strpos($update,'if (!composerReady)');
$fieldMissing=strpos($update,"reason: 'SUPPORT_REPLY_FIELD_MISSING'");
ssrfAssert($composer!==false&&$fieldMissing!==false&&$composer<$fieldMissing,'Reply-field-missing branch must remain auditable.');
$composerBlock=substr($update,(int)$composer,(int)$fieldMissing-(int)$composer);
ssrfAssert(str_contains($composerBlock,'supportRouteFor(job)'),'Missing reply field must consult the existing Seller Support route.');
ssrfAssert(str_contains($composerBlock,'supportOpen(cdp, job, { forceFreshCase: true, supportRoute'),'Missing reply field with a known route must fall back to a fresh Seller Support case.');

$openStart=strpos($worker,'async function supportOpen');
$openEnd=$openStart===false?false:strpos($worker,'async function supportUpdate',$openStart);
ssrfAssert($openStart!==false&&$openEnd!==false,'supportOpen must remain independently auditable.');
$open=substr($worker,(int)$openStart,(int)$openEnd-(int)$openStart);
ssrfAssert(str_contains($open,'const reconcileKnownCase = options.forceFreshCase !== true;'),'Fresh-case mode must explicitly disable reconciliation of the known stale thread.');
ssrfAssert(str_contains($open,'if (!reconcileKnownCase'),'Fresh-case lookup must exclude the stale support case ID.');
ssrfAssert(str_contains($open,'excludeCaseIds'),'Fresh-case lookup must exclude the stale support case ID while still reconciling any new sibling.');
ssrfAssert(str_contains($open,'const retryReconciliation = Number(job.attempt_count || 0) > 1;'),'A retry after a potentially submitted fresh-case write must enter reconciliation even when forceFreshCase is true.');
ssrfAssert(str_contains($open,"retryReconciliation ? { includeTerminal: true } : {}"),'Retry reconciliation must include terminal sibling cases so a quickly closed created case cannot be duplicated.');

echo "seller-support-reply-field-fresh-fallback-test: OK\n";
