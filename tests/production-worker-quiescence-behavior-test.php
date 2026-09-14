<?php
declare(strict_types=1);

function qbAssert(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException($message);
}
$root=dirname(__DIR__);
$helper=$root.'/scripts/quiesce-production-workers.sh';
$tmp=sys_get_temp_dir().'/qw-'.bin2hex(random_bytes(5));
mkdir($tmp,0700,true);
$counts=$tmp.'/counts';
$log=$tmp.'/systemctl.log';
file_put_contents($counts,"0\n1\n0\n0\n0\n");
$bash=<<<'BASH'
set -euo pipefail
source "$HELPER"
qw_scalar(){
    case "$2" in
        *amazon_return_tenants*) printf '7\n' ;;
        *amazon_return_connections*) printf '11\n' ;;
        *) return 1 ;;
    esac
}
qw_processing_count(){
    head -n1 "$COUNTS"
    tail -n +2 "$COUNTS" > "$COUNTS.next"
    mv "$COUNTS.next" "$COUNTS"
}
BASH;
$bash.="\n";
$bash.=<<<'BASH'
systemctl(){
    printf '%s\n' "$*" >> "$LOG"
    case "$1" in
        is-active) return 0 ;;
        *) return 0 ;;
    esac
}
sleep(){ :; }
AMAZON_RETURNS_QUIESCE_TIMEOUT_SECONDS=10 \
AMAZON_RETURNS_QUIESCE_POLL_SECONDS=1 \
quiesce_workers amazon_returns_safet shopvivaliz amazon-br-primary
BASH;
$command='HELPER='.escapeshellarg($helper)
    .' COUNTS='.escapeshellarg($counts)
    .' LOG='.escapeshellarg($log)
    .' bash -c '.escapeshellarg($bash).' 2>&1';
exec($command,$output,$exitCode);
qbAssert($exitCode===0,"Quiescence simulation failed:\n".implode("\n",$output));
$events=(string)file_get_contents($log);
qbAssert(substr_count($events,'freeze amazon-returns-safet.service')===2,'Main worker must freeze twice across the simulated race.');
qbAssert(substr_count($events,'freeze amazon-returns-seller-central-browser.service')===2,'Browser worker must freeze twice across the simulated race.');
qbAssert(substr_count($events,'thaw amazon-returns-safet.service')===1,'Race detection must thaw the main worker only when a job appears after freeze.');
qbAssert(substr_count($events,'thaw amazon-returns-seller-central-browser.service')===1,'Race detection must thaw the browser worker only when a job appears after freeze.');
qbAssert(substr_count($events,'stop amazon-returns-safet.service')===1,'Confirmed idle main worker must stop once while still frozen.');
qbAssert(substr_count($events,'stop amazon-returns-seller-central-browser.service')===1,'Confirmed idle browser worker must stop once while still frozen.');
qbAssert(str_contains(implode("\n",$output),'worker_quiesce=stopped processing_jobs=0'),'Successful quiescence must report zero processing jobs.');
@unlink($counts);
@unlink($log);
@rmdir($tmp);
echo "production-worker-quiescence-behavior-test: OK\n";
