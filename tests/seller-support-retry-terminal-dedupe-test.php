<?php
declare(strict_types=1);
function srtdAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$start=strpos($worker,'async function supportOpen');
$end=$start===false?false:strpos($worker,'async function supportUpdate',$start);
srtdAssert($start!==false&&$end!==false,'supportOpen must remain independently auditable.');
$body=substr($worker,(int)$start,(int)$end-(int)$start);
srtdAssert(str_contains($body,'job.attempt_count'),'A retried support job must distinguish itself from a new recovery episode.');
srtdAssert(str_contains($body,'includeTerminal: true'),'A retried support job must reconcile an exact terminal case created by a prior attempt.');
srtdAssert(str_contains($body,'SUPPORT_RETRY_RECONCILED_TERMINAL_CASE'),'Retry reconciliation must be explicit in the result audit trail.');
echo "seller-support-retry-terminal-dedupe-test: OK\n";
