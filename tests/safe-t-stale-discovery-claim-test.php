<?php
declare(strict_types=1);

// Regression: discovery jobs whose refund is older than the 90-day Seller Central
// list window must not be claimed by the general drain. They can never be verified
// by that list and otherwise re-consume the shared cycle budget every run.

function staleAssert(bool $ok, string $msg): void
{
    if (!$ok) throw new RuntimeException($msg);
}

$outbox = (string)file_get_contents(__DIR__ . '/../includes/amazon-returns/TenantOutbox.php');
$bridge = (string)file_get_contents(__DIR__ . '/../includes/amazon-returns/StatusBridgeService.php');

staleAssert(str_contains($outbox, 'bool $excludeStaleDiscovery=false'),
    'claimBatch must expose an opt-in stale-discovery exclusion flag.');
staleAssert(str_contains($outbox, "kind='SAFE_T_DISCOVERY' AND EXISTS"),
    'Stale exclusion must be restricted to SAFE_T_DISCOVERY rows.');
staleAssert(str_contains($outbox, 'sc.refund_at < :stale_cutoff'),
    'Stale exclusion must compare the refund date to the 90-day cutoff.');
staleAssert(str_contains($outbox, "\$params[':stale_cutoff']"),
    'Stale cutoff must be a bound parameter, never interpolated.');
staleAssert(str_contains($outbox, "\$staleSql='';"),
    'Stale exclusion must default to no SQL change when the flag is off.');

// The general drain (not the urgent in-window probe) opts in.
staleAssert(str_contains($bridge, "claimBatch(1,['SAFE_T_DISCOVERY'],null,false,true)"),
    'General discovery fallback must exclude stale refunds.');
staleAssert(str_contains($bridge, "claimBatch(1,['SAFE_T_DISCOVERY'],\$urgentIds,true)"),
    'Urgent in-window discovery must remain unchanged.');

// Other read kinds are never filtered by the stale predicate.
staleAssert(!str_contains($bridge, "claimBatch(1,['SAFE_T_READ','SELLER_SUPPORT_READ'],null,false,true)"),
    'Read-only SAFE-T and support reads must not use the stale exclusion.');

echo "safe-t-stale-discovery-claim-test: OK\n";
