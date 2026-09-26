<?php
declare(strict_types=1);

function ssliveAssert(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
}

$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$start=strpos($worker,'async function supportUpdate');
$end=$start===false?false:strpos($worker,'async function executeJob',$start);
ssliveAssert($start!==false && $end!==false,'Seller Support update flow must remain auditable.');
$update=substr($worker,(int)$start,(int)$end-(int)$start);

ssliveAssert(
    str_contains($update,"channels.includes('Chat')") || str_contains($update,"channels.includes('Phone')"),
    'Seller Support update must explicitly recognize live-only reply channels.'
);
ssliveAssert(
    str_contains($update,"supportOpen(cdp, job, { forceFreshCase: true })"),
    'When an existing thread only offers live channels, automation must open a fresh asynchronous support case instead of abandoning the rebuttal.'
);
ssliveAssert(
    !str_contains($update,'clickHillChat(cdp)'),
    'Seller Support update must never start an unattended live chat.'
);

echo "seller-support-live-only-fresh-case-test: OK\n";
