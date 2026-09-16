<?php
declare(strict_types=1);
function swrAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$helper=dirname(__DIR__).'/scripts/quiesce-production-workers.sh';
$source=(string)file_get_contents($helper);
swrAssert(str_contains($source,'qw_release_stale_write_jobs'),'Quiesce must expose a dedicated stale external-write recovery path.');
swrAssert(str_contains($source,"kind IN ('SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE')"),'Only idempotent external-write kinds may be released for reconciliation.');
swrAssert(str_contains($source,"status='PENDING'"),'Expired write leases must return to PENDING for normal worker reconciliation.');
swrAssert(str_contains($source,"last_error='LEASE_EXPIRED_DURING_QUIESCE_RECONCILE'"),'Recovered writes must retain an auditable reconciliation reason.');
swrAssert(!str_contains($source,"LEASE_EXPIRED_DURING_QUIESCE_RECONCILE',attempt_count=GREATEST(attempt_count-1,0)"),'Stale writes must preserve attempt_count so retry reconciliation runs before any new write.');
echo "production-worker-stale-write-recovery-test: OK\n";
