<?php
declare(strict_types=1);
function gateAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach(['scripts/verify-migration.sh','scripts/verify-live-tenant-foundation.sh'] as $relative){
    $source=(string)file_get_contents(__DIR__.'/../'.$relative);
    gateAssert(str_contains($source,"status='PROCESSING' AND locked_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 300 SECOND)"),$relative.' must block only active PROCESSING leases.');
    gateAssert(str_contains($source,'stale_processing_jobs'),$relative.' must report expired PROCESSING leases separately.');
    gateAssert(str_contains($source,"locked_at IS NULL OR locked_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 300 SECOND)"),$relative.' must classify missing/expired locks as stale.');
}
echo "stale-processing-deploy-gate-test: OK\n";
