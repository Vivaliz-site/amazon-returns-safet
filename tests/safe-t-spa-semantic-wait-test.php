<?php
declare(strict_types=1);

function sswAssert(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException($message);
}

$read=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-safe-t-read-worker.mjs');
$bridge=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');

sswAssert(
    str_contains($read,'waitForSafeTClaimDetail'),
    'SAFE-T read worker must wait for a parseable claim detail instead of accepting the first SPA snapshot.'
);
sswAssert(
    str_contains($read,"read.claim_status !== 'UNKNOWN'"),
    'SAFE-T semantic wait must require a parseable status before considering the SPA settled.'
);

$start=strpos($bridge,'async function safeTAppeal');
$end=$start===false?false:strpos($bridge,'async function hillChatReady',$start);
sswAssert($start!==false && $end!==false,'SAFE-T appeal flow must remain auditable.');
$appeal=substr($bridge,(int)$start,(int)$end-(int)$start);
sswAssert(
    str_contains($appeal,"await cdp.waitFor(`Boolean(document.querySelector('kat-textarea.description-textbox'))`"),
    'SAFE-T appeal must wait semantically for the asynchronous appeal field.'
);
sswAssert(
    str_contains($appeal,'SAFE_T_APPEAL_FIELD_UNAVAILABLE'),
    'SAFE-T appeal must still fail closed when the field never becomes available.'
);

echo "safe-t-spa-semantic-wait-test: OK\n";
