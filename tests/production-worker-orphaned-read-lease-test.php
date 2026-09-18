<?php
declare(strict_types=1);

function qorAssert(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException($message);
}

$helper=__DIR__.'/../scripts/quiesce-production-workers.sh';
$source=(string)file_get_contents($helper);
$start=strpos($source,'qw_release_orphaned_read_jobs() {');
qorAssert($start!==false,'Read-lease recovery helper must exist.');
$end=strpos($source,"\n}\n",$start);
qorAssert($end!==false,'Read-lease recovery helper body must be bounded.');
$body=substr($source,$start,$end-$start);

qorAssert(
    !str_contains($body,'INTERVAL 300 SECOND'),
    'When the Seller Central browser worker is inactive, read-only PROCESSING leases must be released immediately instead of waiting past the 180s deploy timeout.'
);
qorAssert(
    str_contains($body,"last_error='WORKER_INACTIVE_DURING_QUIESCE'"),
    'Immediate orphaned read recovery must leave an auditable reason.'
);
qorAssert(
    str_contains($body,"kind IN ('SAFE_T_READ','SAFE_T_DISCOVERY','SELLER_SUPPORT_READ')"),
    'Immediate recovery must remain limited to read-only Seller Central jobs.'
);
qorAssert(
    str_contains($body,'attempt_count=GREATEST(attempt_count-1,0)'),
    'An abandoned read-only lease must not consume an attempt.'
);

echo "production-worker-orphaned-read-lease-test: OK\n";
