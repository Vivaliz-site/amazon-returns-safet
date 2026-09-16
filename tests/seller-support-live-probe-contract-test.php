<?php
declare(strict_types=1);

function sslpAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$runner=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/run-seller-central-daily.sh');
sslpAssert($worker!=='','Seller Central bridge worker source is missing.');
sslpAssert($runner!=='','Seller Central daily runner source is missing.');

sslpAssert(str_contains($worker,'async function probeSupportCaseLookup'),
    'Worker must expose a read-only Seller Support lookup probe.');
sslpAssert(str_contains($worker,"process.argv.includes('--support-lookup-probe')"),
    'Worker CLI must support --support-lookup-probe.');
sslpAssert(str_contains($worker,"log('support_lookup_probe'"),
    'Probe must emit one structured support_lookup_probe event.');
sslpAssert(str_contains($worker,"'/hill/hillservice/mons-api/SearchForCases'"),
    'Probe must exercise the same SearchForCases contract used by production support lookup.');
foreach(['http_status','content_type','response_keys','list_is_array','total_is_numeric'] as $field){
    sslpAssert(str_contains($worker,$field),"Probe must report safe structural field {$field}.");
}
sslpAssert(!str_contains($worker,'probe_response_body'),
    'Probe must never log response bodies.');
sslpAssert(!str_contains($worker,'probe_cookie'),
    'Probe must never log cookies.');

sslpAssert(str_contains($runner,'--support-lookup-probe'),
    'Daily browser cycle must execute the support lookup probe.');
sslpAssert(str_contains($runner,'--support-lookup-probe || true'),
    'Probe must be non-blocking so diagnostics cannot stop normal browser work.');

echo "seller-support-live-probe-contract-test: OK\n";
