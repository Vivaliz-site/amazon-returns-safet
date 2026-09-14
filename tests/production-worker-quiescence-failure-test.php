<?php
declare(strict_types=1);
function qfAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$root=dirname(__DIR__);$helper=$root.'/scripts/quiesce-production-workers.sh';
$tmp=sys_get_temp_dir().'/qwf-'.bin2hex(random_bytes(5));mkdir($tmp,0700,true);
$counts=$tmp.'/counts';$log=$tmp.'/systemctl.log';file_put_contents($counts,"0\n0\n1\n");
$bash=<<<'BASH'
set -u
source "$HELPER"
qw_scalar(){ case "$2" in *amazon_return_tenants*) echo 7;; *amazon_return_connections*) echo 11;; *) return 1;; esac; }
qw_processing_count(){ head -n1 "$COUNTS"; tail -n +2 "$COUNTS" > "$COUNTS.next"; mv "$COUNTS.next" "$COUNTS"; }
systemctl(){ printf '%s\n' "$*" >> "$LOG"; return 0; }
sleep(){ :; }
AMAZON_RETURNS_QUIESCE_TIMEOUT_SECONDS=10 AMAZON_RETURNS_QUIESCE_POLL_SECONDS=1 quiesce_workers amazon_returns_safet shopvivaliz amazon-br-primary
BASH;
$command='HELPER='.escapeshellarg($helper).' COUNTS='.escapeshellarg($counts).' LOG='.escapeshellarg($log).' bash -c '.escapeshellarg($bash).' 2>&1';
exec($command,$output,$exitCode);
qfAssert($exitCode!==0,'Post-stop processing race must fail closed.');
$events=(string)file_get_contents($log);
qfAssert(str_contains($events,'start amazon-returns-safet.service'),'Failed post-stop validation must restart the main worker.');
qfAssert(str_contains($events,'start amazon-returns-seller-central-browser.timer'),'Failed post-stop validation must restore the browser timer.');
@unlink($counts);@unlink($log);@rmdir($tmp);
echo "production-worker-quiescence-failure-test: OK\n";
