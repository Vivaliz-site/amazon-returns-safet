<?php
declare(strict_types=1);

function ssloAssert(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
}

$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');

$openStart=strpos($worker,'async function supportOpen');
$updateStart=strpos($worker,'async function supportUpdate');
$executeStart=$updateStart===false?false:strpos($worker,'async function executeJob',$updateStart);
ssloAssert($openStart!==false && $updateStart!==false && $executeStart!==false,'Seller Support open/update flows must remain auditable.');

$open=substr($worker,(int)$openStart,(int)$updateStart-(int)$openStart);
$update=substr($worker,(int)$updateStart,(int)$executeStart-(int)$updateStart);

ssloAssert(
    str_contains($open,'options.supportRoute'),
    'Fresh Seller Support fallback must allow an explicit asynchronous route override.'
);
ssloAssert(
    str_contains($update,"supportOpen(cdp, job, { forceFreshCase: true, supportRoute: 'GENERAL_ORDER_SUPPORT' })"),
    'When an existing support case is live-only, automation must fall back to the asynchronous general-order support route instead of re-entering the live-only FBA route.'
);
ssloAssert(
    !str_contains($update,'clickHillChat(cdp)'),
    'Live-only Seller Support fallback must never start an unattended chat.'
);

echo "seller-support-live-only-async-fallback-test: OK\n";
