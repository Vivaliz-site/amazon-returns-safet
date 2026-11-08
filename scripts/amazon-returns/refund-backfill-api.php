<?php
declare(strict_types=1);
/**
 * API-first buyer refund gap recovery. A single scoped operational command.
 * Default: DRY RUN. --apply requires exact hash from preflight.
 * Does NOT submit claims, open support tickets, or change external-write gates.
 * Uses the same ambiguity-safe Finances scanner as the existing daily daemon.
 */
$root = getenv('AMAZON_RETURNS_RELEASE_ROOT') ?: '/home/ubuntu/amazon-returns-deploy/current';
require_once $root . '/includes/Database.php';
require_once $root . '/includes/amazon-returns/TenantRegistry.php';
require_once $root . '/includes/amazon-returns/TenantPersistence.php';
require_once $root . '/includes/amazon-returns/AmazonSpApiClient.php';
require_once $root . '/includes/amazon-returns/SpApi.php';
require_once $root . '/includes/amazon-returns/SpApiEventSink.php';
require_once $root . '/includes/amazon-returns/RefundDiscovery.php';

$apply = in_array('--apply', $argv, true);
$days = 90;
$expected = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--days=')) $days = (int)substr($arg, 7);
    if (str_starts_with($arg, '--expected-hash=')) $expected = substr($arg, 16);
}
if ($days < 1 || $days > 90) throw new InvalidArgumentException('Window must be 1 to 90 days');
if ($apply && (!is_string($expected) || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1)) {
    fwrite(STDERR, "Apply requires preflight hash\n"); exit(2);
}
$pdo = amazon_returns_require_pdo();
$config = new SvAmazonReturnsConfig();
$context = SvAmazonTenantRegistry::resolveCurrent($pdo, $config);
$p = SvAmazonTenantPersistence::create($pdo, $context);
$transport = new SvAmazonSpApiClient($config);
$api = new SvAmazonReturnsSpApi($transport);
// Reuse the same pagination and ambiguous ORDER_ID guard as the daily daemon.
// In particular, a single refunded row with conflicting/malformed order IDs
// must never become multiple candidate cases in an operator's preflight.
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$scan = SvAmazonRefundDiscovery::scan($transport, $days, $now);
$stats = [
    'windows' => (int)$scan['windows'],
    'pages' => (int)$scan['pages'],
    'refunded_rows' => (int)$scan['refunded_rows'],
    'unlinked_refund_rows' => (int)$scan['unlinked_refund_rows'],
    'refund_orders' => count($scan['orders']),
    'missing_orders' => 0,
];
$refunded = array_fill_keys($scan['orders'], true);
$missing = [];
foreach (array_keys($refunded) as $id) {
    if ($p->cases->forOrder($id) === []) $missing[] = $id;
}
sort($missing, SORT_STRING);
$stats['missing_orders'] = count($missing);
$hash = hash('sha256', implode("\n", $missing));
if (!$apply) {
    echo json_encode(['mode'=>'dry-run','scope'=>[$context->tenantId(),$context->amazonConnectionId()],
        'stats'=>$stats,'candidate_hash'=>$hash], JSON_THROW_ON_ERROR), PHP_EOL;
    exit(0);
}
if (!hash_equals($expected, $hash)) throw new RuntimeException('Candidate set changed; run a fresh preflight');
if (count($missing) > 75) throw new RuntimeException('Candidate count exceeds scoped canary limit');
$mutex = 'returns-refund-repair-' . $context->tenantId() . '-' . $context->amazonConnectionId();
$lock = $pdo->prepare('SELECT GET_LOCK(?,0)');
$lock->execute([$mutex]);
if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Another refund repair is active');
$result = ['mode'=>'apply','stats'=>$stats,'inserted_cases'=>0,'eligible_single_item'=>0,
    'skipped_existing'=>0,'skipped_ambiguous'=>0,'skipped_not_released'=>0,'failed'=>0];
try {
    foreach ($missing as $i=>$id) {
        try {
            if ($p->cases->forOrder($id) !== []) { $result['skipped_existing']++; continue; }
            $order = $api->syncOrder($id);
            $items = is_array($order['order_items'] ?? null) ? $order['order_items'] : [];
            if (count($items) !== 1 || !is_array($items[0])) { $result['skipped_ambiguous']++; continue; }
            $financial = $api->listTransactions($id);
            if (SvAmazonSpApiEventSink::refundObservation($financial['transactions'] ?? []) === null) {
                $result['skipped_not_released']++; continue;
            }
            $result['eligible_single_item']++;
            $pdo->beginTransaction();
            if ($p->cases->forOrder($id) !== []) {
                $pdo->rollBack(); $result['skipped_existing']++; continue;
            }
            $saved = SvAmazonSpApiEventSink::persist($p, $order, $financial['transactions'] ?? []);
            $pdo->commit();
            $result['inserted_cases'] += count($saved['cases'] ?? []);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $result['failed']++;
            error_log('[amazon-refund-gap-repair] order=' . substr(hash('sha256',$id),0,12) . ' class=' . get_class($e));
        }
        if ($i + 1 < count($missing)) usleep(2200000);
    }
} finally {
    $unlock = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $unlock->execute([$mutex]);
}
echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
exit($result['failed'] ? 1 : 0);
