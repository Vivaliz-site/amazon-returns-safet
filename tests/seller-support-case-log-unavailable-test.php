<?php
declare(strict_types=1);

function ssclAssert(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
}

$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$start=strpos($worker,'async function supportUpdate');
$end=$start===false?false:strpos($worker,"\nasync function ",$start+1);
ssclAssert($start!==false && $end!==false,'supportUpdate must remain auditable.');
$update=substr($worker,(int)$start,(int)$end-(int)$start);

ssclAssert(
    str_contains($worker,'function sellerSupportCaseLogUnavailable'),
    'Seller Support runtime must recognize the Case Log temporary-unavailable page.'
);
ssclAssert(
    str_contains($worker,'case log system is currently unavailable'),
    'Runtime must recognize the observed English Case Log outage message.'
);
ssclAssert(
    str_contains($update,'sellerSupportCaseLogUnavailable'),
    'supportUpdate must check Case Log availability before classifying missing composer as UI drift.'
);
$unavailable=strpos($update,'sellerSupportCaseLogUnavailable');
$fieldMissing=strpos($update,'SUPPORT_REPLY_FIELD_MISSING');
ssclAssert(
    $unavailable!==false && $fieldMissing!==false && $unavailable<$fieldMissing,
    'Case Log outage handling must run before SUPPORT_REPLY_FIELD_MISSING.'
);
ssclAssert(
    str_contains($update,'sellerSupportUnavailableResult()'),
    'Temporary Case Log outage must remain retryable through the standard Seller Support unavailable result.'
);

echo "seller-support-case-log-unavailable-test: OK\n";
