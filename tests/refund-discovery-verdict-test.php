<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/RefundDiscovery.php';

function discoveryVerdictAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$cases = [
    ['missing' => 0, 'result' => ['failed' => 0, 'skipped_ambiguous' => 0, 'skipped_not_released' => 0],
        'status' => 'OK', 'completed' => true, 'review_required' => 0],
    ['missing' => 1, 'result' => ['failed' => 0, 'skipped_ambiguous' => 1, 'skipped_not_released' => 0],
        'status' => 'REVIEW_REQUIRED', 'completed' => true, 'review_required' => 1],
    ['missing' => 1, 'result' => ['failed' => 0, 'skipped_ambiguous' => 0, 'skipped_not_released' => 1],
        'status' => 'REVIEW_REQUIRED', 'completed' => true, 'review_required' => 1],
    ['missing' => 0, 'result' => ['failed' => 0, 'unlinked_refund_rows' => 1],
        'status' => 'REVIEW_REQUIRED', 'completed' => true, 'review_required' => 1],
    ['missing' => 25, 'result' => ['failed' => 0, 'unlinked_refund_rows' => 3, 'skipped_ambiguous' => 2],
        'status' => 'REVIEW_REQUIRED', 'completed' => true, 'review_required' => 5],
    ['missing' => 25, 'result' => ['failed' => 0, 'skipped_ambiguous' => 2, 'skipped_not_released' => 1],
        'status' => 'REVIEW_REQUIRED', 'completed' => true, 'review_required' => 3],
    ['missing' => 1, 'result' => ['failed' => 1, 'skipped_ambiguous' => 0, 'skipped_not_released' => 0],
        'status' => 'PARTIAL', 'completed' => false, 'review_required' => 0],
    ['missing' => 26, 'result' => ['failed' => 0, 'skipped_ambiguous' => 0, 'skipped_not_released' => 0],
        'status' => 'PARTIAL', 'completed' => false, 'review_required' => 0],
    ['missing' => 26, 'result' => ['failed' => 0, 'skipped_ambiguous' => 1, 'skipped_not_released' => 0],
        'status' => 'PARTIAL', 'completed' => false, 'review_required' => 1],
];

foreach ($cases as $index => $case) {
    $actual = SvAmazonRefundDiscovery::assessDailyCompletion($case['missing'], $case['result']);
    discoveryVerdictAssert($actual['status'] === $case['status'], "Verdict status mismatch at case {$index}.");
    discoveryVerdictAssert($actual['completed'] === $case['completed'], "Daily cursor completion mismatch at case {$index}.");
    discoveryVerdictAssert($actual['review_required'] === $case['review_required'], "Manual review count mismatch at case {$index}.");
}

try {
    SvAmazonRefundDiscovery::assessDailyCompletion(-1, ['failed' => 0]);
    throw new RuntimeException('Negative missing order count must fail closed.');
} catch (InvalidArgumentException $expected) {
    // Expected.
}

$legacy = SvAmazonRefundDiscovery::cachedDailySummary([
    'status' => 'OK', 'skipped_ambiguous' => 1, 'missing_orders' => 1,
]);
discoveryVerdictAssert($legacy === ['last_scan_status' => 'REVIEW_REQUIRED', 'review_required' => 1],
    'Legacy OK cursor with ambiguous order must not hide manual review.');
$partial = SvAmazonRefundDiscovery::cachedDailySummary([
    'status' => 'PARTIAL', 'skipped_ambiguous' => 2, 'review_required' => 1,
]);
discoveryVerdictAssert($partial === ['last_scan_status' => 'PARTIAL', 'review_required' => 2],
    'Incomplete scan must stay partial and retain the greater review count.');
$unlinked = SvAmazonRefundDiscovery::cachedDailySummary([
    'status' => 'OK', 'unlinked_refund_rows' => 2, 'missing_orders' => 0,
]);
discoveryVerdictAssert($unlinked === ['last_scan_status' => 'REVIEW_REQUIRED', 'review_required' => 2],
    'Previously cached OK must not hide released refunds lacking a valid order ID.');
$clean = SvAmazonRefundDiscovery::cachedDailySummary(['status' => 'OK', 'skipped_ambiguous' => 0]);
discoveryVerdictAssert($clean === ['last_scan_status' => 'OK', 'review_required' => 0],
    'Clean daily scan must not invent outstanding reviews.');

echo "refund-discovery-verdict-test: OK\n";
