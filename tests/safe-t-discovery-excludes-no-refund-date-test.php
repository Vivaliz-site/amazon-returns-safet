<?php
declare(strict_types=1);

// Owner decision 12/11/2026: SAFE-T discovery excludes cases without a refund date
// and cases refunded more than 90 days ago. Pending discovery jobs for them are
// superseded (never read, never written externally). Monitoring of existing
// claims (casesWithSafeTId) is unchanged.

function exclAssert(bool $ok, string $msg): void
{
    if (!$ok) throw new RuntimeException($msg);
}

$cases = (string)file_get_contents(__DIR__ . '/../includes/amazon-returns/CaseRepository.php');
$outbox = (string)file_get_contents(__DIR__ . '/../includes/amazon-returns/TenantOutbox.php');
$bridge = (string)file_get_contents(__DIR__ . '/../includes/amazon-returns/StatusBridgeService.php');

// Isolate casesWithoutSafeTId so the unchanged known-claim query is not matched.
$start = strpos($cases, 'public function casesWithoutSafeTId(');
$end = strpos($cases, 'public function countSupportCaseCrossOrderDuplicateGroups(', (int)$start);
exclAssert($start !== false && $end !== false && $end > $start, 'casesWithoutSafeTId must exist.');
$discovery = substr($cases, $start, $end - $start);
exclAssert(str_contains($discovery, 'refund_at IS NOT NULL AND refund_at>=:refund_cutoff'),
    'Discovery enqueue must require a known refund date inside the 90-day window.');
exclAssert(!str_contains($discovery, 'refund_at IS NULL'),
    'Discovery enqueue must not include cases without a refund date.');

// Known-claim monitoring still keeps cases with an unknown refund date.
$kStart = strpos($cases, 'public function casesWithSafeTId(');
$kEnd = strpos($cases, 'public function ', (int)$kStart + 10);
exclAssert($kStart !== false && $kEnd !== false, 'casesWithSafeTId must exist.');
exclAssert(str_contains(substr($cases, $kStart, $kEnd - $kStart), 'refund_at IS NULL'),
    'Existing SAFE-T claim monitoring must keep unknown refund dates.');

// Sweep supersedes pending discovery for no-date or over-90-day cases only.
exclAssert(str_contains($outbox, 'function supersedeDiscoveryWithoutRefundWindow(string $reason): int'),
    'Outbox must expose the discovery exclusion sweep.');
exclAssert(str_contains($outbox, "c.refund_at IS NULL OR c.refund_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)"),
    'Sweep must cover missing refund dates and refunds older than 90 days.');
exclAssert(str_contains($outbox, "o.status='PENDING' AND o.kind='SAFE_T_DISCOVERY'"),
    'Sweep must touch only pending SAFE_T_DISCOVERY rows.');
exclAssert(str_contains($outbox, "SET o.status='SUPERSEDED'"),
    'Sweep must supersede, never mark succeeded or write externally.');

// The sweep runs on every ensureJobs pass.
exclAssert(str_contains($bridge, "supersedeDiscoveryWithoutRefundWindow('DISCOVERY_NO_REFUND_DATE_OR_OVER_90_DAYS')"),
    'ensureJobs must run the discovery exclusion sweep.');

echo "safe-t-discovery-excludes-no-refund-date-test: OK\n";
