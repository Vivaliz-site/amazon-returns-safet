<?php
declare(strict_types=1);
$path=__DIR__.'/../scripts/amazon-returns/run-seller-central-daily.sh';
$src=(string)file_get_contents($path);
function cAssert(bool $value,string $message):void {
    if(!$value)throw new RuntimeException($message);
}
cAssert(str_contains($src,'bridge_drain_failed=1'),
  'Seller Central must record an initial write drain failure without starving reads.');
$first=strpos($src,'seller-central-bridge-worker.mjs" "$bridge_mode"');
$read=strpos($src,'seller-central-safe-t-read-worker.mjs" --drain');
$last=strrpos($src,'seller-central-bridge-worker.mjs" --drain');
$guard=strpos($src,'if [[ "$bridge_drain_failed" -ne 0 ]]');
cAssert($first!==false && $read!==false && $last!==false && $guard!==false,
    'Every required worker phase and the failure gate must be present.');
cAssert($first<$read && $read<$guard && $guard<$last,
    'The read drain must execute after the first failed write and before any post-read write.');
$tail=substr($src,$guard,250);
cAssert(str_contains($tail,'exit 75'),
    'A failed first write drain must still fail the whole cycle after safe reads.');
$authFailure=strpos($src,'record_auth_failure "BRIDGE_DRAIN_FAILED"');
cAssert($authFailure!==false && $read<$authFailure,
    'Bridge failure telemetry must not poison authentication before read-only work.');
echo "seller-central-write-failure-read-recovery-test: OK\n";
