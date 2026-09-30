<?php
declare(strict_types=1);

function smcAssert(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException($message);
}

$runner=(string)file_get_contents(dirname(__DIR__).'/scripts/amazon-returns/run-seller-central-daily.sh');

smcAssert(str_contains($runner,'flock -w "$CYCLE_LOCK_WAIT_SECONDS" 9'),
    'Seller Central cycles must serialize before touching the dedicated CDP port.');
smcAssert(str_contains($runner,'managed_orphan_browser_pid'),
    'Runner must distinguish one stale managed browser from an unknown listener.');
smcAssert(str_contains($runner,'[[ "$exe" == "$BROWSER_RESOLVED" ]]'),
    'Managed-orphan cleanup must require the exact resolved browser executable.');
smcAssert(str_contains($runner,'[[ "$ppid" == "1" ]]'),
    'Managed-orphan cleanup must require an orphan adopted by PID 1.');
smcAssert(str_contains($runner,'--remote-debugging-port=$CDP_PORT'),
    'Managed-orphan cleanup must require the exact dedicated CDP port.');
smcAssert(str_contains($runner,'--user-data-dir=$SELLER_CENTRAL_PROFILE'),
    'Managed-orphan cleanup must require the exact Seller Central profile.');
smcAssert(str_contains($runner,'[[ "$has_port" -eq 1 && "$has_profile" -eq 1 && "$has_type" -eq 0 ]]'),
    'Renderer/utility child processes must never qualify as the managed browser root.');
smcAssert(str_contains($runner,'[[ "${#matches[@]}" -eq 1 ]]'),
    'Cleanup must fail closed unless exactly one managed orphan root matches.');
smcAssert(str_contains($runner,'STALE_MANAGED_CDP_RECOVERED'),
    'Successful stale managed CDP recovery must be observable.');
smcAssert(str_contains($runner,'record_auth_failure "PREEXISTING_CDP_UNOWNED"'),
    'Unknown or ambiguous pre-existing CDP listeners must still fail closed.');
smcAssert(!str_contains($runner,'pkill'),
    'Recovery must never use broad process matching or pkill.');

echo "seller-central-stale-managed-cdp-test: OK\n";
