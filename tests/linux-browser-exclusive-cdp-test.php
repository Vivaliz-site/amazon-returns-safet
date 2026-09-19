<?php
declare(strict_types=1);
$runner=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/run-seller-central-daily.sh');
if(!str_contains($runner,'PREEXISTING_CDP_UNOWNED')){
    throw new RuntimeException('Daily browser runner must fail closed instead of reusing a pre-existing CDP session.');
}
if(!preg_match('/curl[^\n]+json\/version[^\n]*;\s*then(?:(?!setsid).)*PREEXISTING_CDP_UNOWNED/s',$runner)){
    throw new RuntimeException('Pre-existing CDP check must fail before the runner launches or drains browser workers.');
}
if(!str_contains($runner,'record_auth_failure "PREEXISTING_CDP_UNOWNED"')){
    throw new RuntimeException('Pre-existing CDP conflicts must publish a fresh failed browser-auth observation before exiting.');
}
if(!str_contains($runner,'record_auth_failure "BROWSER_STARTUP_FAILED"')){
    throw new RuntimeException('Browser startup failures must publish a fresh failed browser-auth observation.');
}
if(!str_contains($runner,'record_auth_failure "CDP_PRUNE_FAILED"')){
    throw new RuntimeException('Repeated CDP prune failures must publish a failed browser-auth observation.');
}
if(!preg_match('/for attempt in 1 2 3; do.*prune-seller-central-cdp-targets\.mjs.*CDP_PRUNE_FAILED/s',$runner)){
    throw new RuntimeException('CDP target pruning must use bounded retries before failing the browser cycle.');
}
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-safe-t-read-worker.mjs');
if(!str_contains($worker,'--heartbeat-auth-status') || !str_contains($worker,'auth_status: authStatus')){
    throw new RuntimeException('Status worker must support publishing explicit failed auth heartbeats.');
}
if(str_contains($runner,'pkill')){
    throw new RuntimeException('CDP exclusivity must not be implemented by killing unrelated browser processes.');
}
echo "linux-browser-exclusive-cdp-test: OK\n";
