<?php
declare(strict_types=1);

function qwAssert(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException($message);
}

$root=dirname(__DIR__);
$helper=$root.'/scripts/quiesce-production-workers.sh';
$provision=$root.'/scripts/provision-production.sh';
qwAssert(is_file($helper),'Production deploy must provide a worker quiescence helper.');
$helperSource=(string)file_get_contents($helper);
$provisionSource=(string)file_get_contents($provision);
foreach(['amazon-returns-safet.service','amazon-returns-seller-central-browser.service','amazon-returns-seller-central-browser.timer','systemctl freeze','systemctl thaw',"status='PROCESSING'"] as $needle){
    qwAssert(str_contains($helperSource,$needle),'Quiescence helper missing '.$needle);
}
qwAssert(str_contains($provisionSource,'quiesce-production-workers.sh'),'Provisioning must quiesce workers before live verification.');
$q=strpos($provisionSource,'quiesce-production-workers.sh');
$v=strpos($provisionSource,'verify-live-tenant-foundation.sh');
$s=strpos($provisionSource,'ln -sfn "releases/$(basename "$release")"');
qwAssert($q!==false && $v!==false && $s!==false && $q<$v && $v<$s,'Quiescence must precede live verification and cutover.');
qwAssert(str_contains($provisionSource,'restore_previous_release_on_failure'),'Failed activation must restore the previous release and worker.');
qwAssert(str_contains($provisionSource,'quiesce_started=1'),'Rollback protection must arm before invoking the quiescence helper.');
$restoreStart=strpos($provisionSource,'restore_previous_release_on_failure()');
$restoreEnd=$restoreStart===false?false:strpos($provisionSource,"\n}\ntrap restore_previous_release_on_failure",$restoreStart);
$restoreBody=($restoreStart!==false&&$restoreEnd!==false)?substr($provisionSource,$restoreStart,$restoreEnd-$restoreStart):'';
qwAssert(str_contains($restoreBody,'systemctl thaw'),'Rollback must thaw units before restarting services.');
qwAssert(str_contains($restoreBody,'systemctl restart amazon-returns-safet.service'),'Activated release rollback must restart the daemon against the restored symlink.');
qwAssert(str_contains($helperSource,'active|activating|reloading|deactivating'),'Quiescence must treat an activating oneshot browser worker as running.');
qwAssert(str_contains($provisionSource,'flock -n'),'Provisioning must reject concurrent deploys with an exclusive lock.');
$identityValidation=strpos($provisionSource,"invalid tenant slug before provisioning");
$dbMutation=strpos($provisionSource,'CREATE DATABASE IF NOT EXISTS');
qwAssert($identityValidation!==false&&$dbMutation!==false&&$identityValidation<$dbMutation,'Tenant identity must be validated before database mutation.');
echo "production-worker-quiescence-test: OK\n";
