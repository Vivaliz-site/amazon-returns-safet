<?php
declare(strict_types=1);

function srAssert(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException($message);
}
$root=dirname(__DIR__);
$helper=$root.'/scripts/quiesce-production-workers.sh';
$tmp=sys_get_temp_dir().'/qw-stale-'.bin2hex(random_bytes(5));
mkdir($tmp,0700,true);
$counts=$tmp.'/counts';
$log=$tmp.'/calls.log';
file_put_contents($counts,"1\n0\n0\n0\n");
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
qw_release_stale_read_jobs(){
    printf 'release %s %s %s %s\n' "$1" "$2" "$3" "${4:-missing}" >> "$LOG"
    printf '1\n'
}
systemctl(){
    printf 'systemctl %s\n' "$*" >> "$LOG"
    case "$1" in is-active) return 1 ;; *) return 0 ;; esac
}
sleep(){ :; }
AMAZON_RETURNS_QUIESCE_MARKER="$(dirname "$COUNTS")/quiesce.marker" \
AMAZON_RETURNS_QUIESCE_TIMEOUT_SECONDS=5 \
AMAZON_RETURNS_QUIESCE_POLL_SECONDS=1 \
quiesce_workers amazon_returns_safet shopvivaliz amazon-br-primary
BASH;
$command='HELPER='.escapeshellarg($helper)
    .' COUNTS='.escapeshellarg($counts)
    .' LOG='.escapeshellarg($log)
    .' bash -c '.escapeshellarg($bash).' 2>&1';
exec($command,$output,$exitCode);
srAssert($exitCode===0,"Quiescence with stale read recovery failed:\n".implode("\n",$output));
$calls=(string)file_get_contents($log);
srAssert(str_contains($calls,'release amazon_returns_safet 7 11 2'),'A 5-second quiesce must reclaim abandoned read-only leases after half the quiesce budget, before timeout.');
srAssert(str_contains(implode("\n",$output),'worker_quiesce_released_stale_reads=1'),'Recovery must be observable.');
$source=(string)file_get_contents($helper);
srAssert(str_contains($source,"kind IN ('SAFE_T_READ','SAFE_T_DISCOVERY','SELLER_SUPPORT_READ')"),'Recovery must be limited to read-only Seller Central jobs.');
srAssert(str_contains($source,"status='PENDING'"),'Stale read jobs must return to PENDING.');
srAssert(str_contains($source,'attempt_count=GREATEST(attempt_count-1,0)'),'Lease recovery must not charge an abandoned attempt.');
srAssert(str_contains($source,'AMAZON_RETURNS_QUIESCE_READ_RECLAIM_SECONDS'),'Quiesce must expose a bounded read-only reclaim threshold.');
srAssert(str_contains($source,'${4:-300}'),'Direct stale recovery must keep the normal 300-second lease default outside the quiesce override.');
srAssert(str_contains($source,'locked_at IS NULL'),'Malformed stale read leases must also be recoverable.');
@unlink($counts);
@unlink($log);
@rmdir($tmp);
echo "production-worker-stale-read-recovery-test: OK\n";
