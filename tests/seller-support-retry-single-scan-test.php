<?php
declare(strict_types=1);
function srssAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$start=strpos($worker,'async function supportOpen');
$end=$start===false?false:strpos($worker,'async function supportUpdate',$start);
srssAssert($start!==false&&$end!==false,'supportOpen must remain independently auditable.');
$body=substr($worker,(int)$start,(int)$end-(int)$start);
$count=substr_count($body,'findSupportCase(cdp, job');
srssAssert($count===1,'A Seller Support job must perform one deep history scan, including retry reconciliation, to avoid ViewCase rate-limit amplification.');
srssAssert(str_contains($body,"retryReconciliation ? { includeTerminal: true } : {}"),'Retries must include terminal history in the same scan.');
srssAssert(str_contains($body,'SUPPORT_RETRY_RECONCILED_TERMINAL_CASE'),'Retry reconciliation must remain explicit in the audit trail.');
echo "seller-support-retry-single-scan-test: OK\n";
