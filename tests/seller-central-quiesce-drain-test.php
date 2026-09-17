<?php
declare(strict_types=1);
function qdAssert(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException($message);
}
$root=dirname(__DIR__);
$helper=$root.'/scripts/quiesce-production-workers.sh';
$tmp=sys_get_temp_dir().'/seller-quiesce-'.bin2hex(random_bytes(5));
mkdir($tmp,0700,true);
$marker=$tmp.'/quiesce.marker';
$counts=$tmp.'/counts';
$log=$tmp.'/events.log';
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
  [[ -e "$MARKER" ]] && printf 'marker_seen\n' >> "$LOG"
  head -n1 "$COUNTS"
  tail -n +2 "$COUNTS" > "$COUNTS.next"
  mv "$COUNTS.next" "$COUNTS"
}
systemctl(){
  printf '%s\n' "$*" >> "$LOG"
  case "$1" in is-active) return 0 ;; *) return 0 ;; esac
}
sleep(){ [[ -e "$MARKER" ]] || { echo marker_missing >&2; return 9; }; }
AMAZON_RETURNS_QUIESCE_MARKER="$MARKER" \
AMAZON_RETURNS_QUIESCE_TIMEOUT_SECONDS=5 \
AMAZON_RETURNS_QUIESCE_POLL_SECONDS=1 \
quiesce_workers amazon_returns_safet shopvivaliz amazon-br-primary
[[ ! -e "$MARKER" ]] || { echo marker_not_cleaned >&2; exit 10; }
BASH;
$command='HELPER='.escapeshellarg($helper)
    .' MARKER='.escapeshellarg($marker)
    .' COUNTS='.escapeshellarg($counts)
    .' LOG='.escapeshellarg($log)
    .' bash -c '.escapeshellarg($bash).' 2>&1';
exec($command,$output,$exitCode);
qdAssert($exitCode===0,"Quiesce marker lifecycle failed:\n".implode("\n",$output));
qdAssert(str_contains((string)@file_get_contents($log),'marker_seen'),'Quiesce must expose a drain marker while PROCESSING drains.');
file_put_contents($marker,"quiesce\n");
$node=trim((string)shell_exec('command -v node'));
qdAssert($node!=='','Node.js is required for Seller Central drain test.');
$token=str_repeat('x',40);
$common='AMAZON_RETURNS_QUIESCE_MARKER='.escapeshellarg($marker).' SELLER_CENTRAL_BRIDGE_TOKEN='.escapeshellarg($token).' ';
$writeCmd=$common.' SELLER_CENTRAL_BRIDGE_ENDPOINT=http://127.0.0.1:1 '.escapeshellarg($node).' '.escapeshellarg($root.'/scripts/amazon-returns/seller-central-bridge-worker.mjs').' --drain 2>&1';
exec($writeCmd,$writeOutput,$writeCode);
qdAssert($writeCode===0,"Bridge drain must stop before pulling a new job during quiesce:\n".implode("\n",$writeOutput));
$statusPort=19000+random_int(100,800);
$readCmd=$common.' SELLER_CENTRAL_STATUS_BRIDGE_ENDPOINT=http://127.0.0.1:1 SELLER_CENTRAL_STATUS_LOCK_PORT='.$statusPort.' '.escapeshellarg($node).' '.escapeshellarg($root.'/scripts/amazon-returns/seller-central-safe-t-read-worker.mjs').' --drain 2>&1';
exec($readCmd,$readOutput,$readCode);
qdAssert($readCode===0,"Status drain must stop before pulling a new job during quiesce:\n".implode("\n",$readOutput));
$timeoutMarker=$tmp.'/timeout.marker';
$timeoutBash=<<<'BASH'
set -euo pipefail
source "$HELPER"
qw_scalar(){ case "$2" in *amazon_return_tenants*) echo 7;; *amazon_return_connections*) echo 11;; *) return 1;; esac; }
qw_processing_count(){ echo 1; }
systemctl(){ case "$1" in is-active) return 0;; *) return 0;; esac; }
AMAZON_RETURNS_QUIESCE_MARKER="$TIMEOUT_MARKER" AMAZON_RETURNS_QUIESCE_TIMEOUT_SECONDS=1 AMAZON_RETURNS_QUIESCE_POLL_SECONDS=1 quiesce_workers amazon_returns_safet shopvivaliz amazon-br-primary
BASH;
$timeoutCmd='HELPER='.escapeshellarg($helper).' TIMEOUT_MARKER='.escapeshellarg($timeoutMarker).' bash -c '.escapeshellarg($timeoutBash).' 2>&1';
exec($timeoutCmd,$timeoutOutput,$timeoutCode);
qdAssert($timeoutCode!==0,'Forced quiesce timeout must fail closed.');
qdAssert(!file_exists($timeoutMarker),'Timed-out quiescence must remove the drain marker.');
@unlink($marker);
@unlink($counts);
@unlink($log);
@rmdir($tmp);
echo "seller-central-quiesce-drain-test: OK\n";
