<?php
declare(strict_types=1);

function qpjAssert(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException($message);
}

$root=dirname(__DIR__);
$helper=$root.'/scripts/quiesce-production-workers.sh';
$tmp=sys_get_temp_dir().'/quiesce-pending-job-'.bin2hex(random_bytes(5));
mkdir($tmp,0700,true);
$log=$tmp.'/systemctl.log';
$calls=$tmp.'/job-calls';
file_put_contents($calls,"0\n");

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
qw_processing_count(){ printf '0\n'; }
systemctl(){
    printf '%s\n' "$*" >> "$LOG"
    if [[ "$1" == "show" && "$*" == *"--property=Job"* ]]; then
        n="$(cat "$CALLS")"
        if [[ "$n" == "0" ]]; then printf '249768\n'; else printf '\n'; fi
        printf '%s\n' "$((n+1))" > "$CALLS"
        return 0
    fi
    if [[ "$1" == "show" && "$*" == *"--property=ActiveState"* ]]; then
        printf 'active\n'
        return 0
    fi
    case "$1" in
        is-active) return 0 ;;
        *) return 0 ;;
    esac
}
sleep(){ :; }
AMAZON_RETURNS_QUIESCE_MARKER="$(dirname "$CALLS")/quiesce.marker" \
AMAZON_RETURNS_QUIESCE_TIMEOUT_SECONDS=10 \
AMAZON_RETURNS_QUIESCE_POLL_SECONDS=1 \
quiesce_workers amazon_returns_safet shopvivaliz amazon-br-primary
BASH;

$command='HELPER='.escapeshellarg($helper)
    .' LOG='.escapeshellarg($log)
    .' CALLS='.escapeshellarg($calls)
    .' bash -c '.escapeshellarg($bash).' 2>&1';
exec($command,$output,$exitCode);
qpjAssert($exitCode===0,"Quiesce must wait for a pending Seller Central systemd job instead of failing freeze:\n".implode("\n",$output));
$joined=implode("\n",$output);
qpjAssert(str_contains($joined,'worker_quiesce_waiting_browser_systemd_job=1'),
    'Quiesce must make the pending systemd-job wait observable.');
$events=(string)file_get_contents($log);
qpjAssert(substr_count($events,'show --property=Job --value amazon-returns-seller-central-browser.service')>=2,
    'Quiesce must re-check the Seller Central systemd job after waiting.');
qpjAssert(str_contains($events,'freeze amazon-returns-seller-central-browser.service'),
    'Seller Central may be frozen only after its pending systemd job has cleared.');
qpjAssert(str_contains($joined,'worker_quiesce=stopped processing_jobs=0'),
    'Quiesce must still reach the normal stopped/zero-processing terminal state.');
@unlink($calls);
@unlink($log);
@rmdir($tmp);
echo "production-worker-pending-systemd-job-test: OK\n";
