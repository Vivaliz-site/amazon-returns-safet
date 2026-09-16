<?php
declare(strict_types=1);

function sslpAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$probePath=__DIR__.'/../scripts/amazon-returns/seller-central-support-lookup-probe.mjs';
$probe=is_file($probePath)?(string)file_get_contents($probePath):'';
$runner=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/run-seller-central-daily.sh');
sslpAssert($probe!=='','Dedicated read-only Seller Support lookup probe is missing.');
sslpAssert($runner!=='','Seller Central daily runner source is missing.');

sslpAssert(str_contains($probe,"'/hill/hillservice/mons-api/SearchForCases'"),
    'Probe must exercise the same SearchForCases contract used by production support lookup.');
sslpAssert(str_contains($probe,"'/hill/hillservice/mons-api/ViewCase?caseId='"),
    'Probe must safely exercise the detail lookup contract used by production support lookup.');
sslpAssert(str_contains($probe,'support_lookup_probe'),
    'Probe must emit one structured support_lookup_probe event.');
foreach([
    'http_status','content_type','response_keys','list_is_array','total_is_numeric',
    'row_keys','detail_http_status','detail_content_type','detail_response_keys'
] as $field){
    sslpAssert(str_contains($probe,$field),"Probe must report safe structural field {$field}.");
}
sslpAssert(!str_contains($probe,'response_body'),
    'Probe must never log response bodies.');
sslpAssert(!str_contains($probe,'cookie'),
    'Probe must never log cookies.');
sslpAssert(!str_contains($probe,'authorization'),
    'Probe must never handle authorization headers or bridge tokens.');
sslpAssert(!str_contains($probe,'case_id:'),
    'Probe must never emit Seller Support case identifiers.');

sslpAssert(str_contains($runner,'seller-central-support-lookup-probe.mjs'),
    'Daily browser cycle must execute the dedicated support lookup probe.');
sslpAssert(str_contains($runner,'seller-central-support-lookup-probe.mjs" || true'),
    'Probe must be non-blocking so diagnostics cannot stop normal browser work.');

echo "seller-support-live-probe-contract-test: OK\n";
