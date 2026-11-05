<?php
declare(strict_types=1);
/**
 * API-first buyer refund gap recovery. A single scoped operational command.
 * Default: DRY RUN. --apply requires exact hash from preflight.
 * Does NOT submit claims, open support tickets, or change external-write gates.
 * The canonical scheduled worker must separately adopt this discovery path.
 */
$root = getenv('AMAZON_RETURNS_RELEASE_ROOT') ?: '/home/ubuntu/amazon-returns-deploy/current';
require_once $root . '/includes/Database.php';
require_once $root . '/includes/amazon-returns/TenantRegistry.php';
require_once $root . '/includes/amazon-returns/TenantPersistence.php';
require_once $root . '/includes/amazon-returns/AmazonSpApiClient.php';
require_once $root . '/includes/amazon-returns/SpApi.php';
require_once $root . '/includes/amazon-returns/SpApiEventSink.php';

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
// Finances rejects postedBefore newer than two minutes ago (InvalidInput).
// Leave a three-minute safety margin; the next scan will catch that gap.
$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-3 minutes');
$start = $now->modify('-' . $days . ' days');
$next = $start;
$stats = ['windows'=>0,'pages'=>0,'refunded_rows'=>0,'refund_orders'=>0,'missing_orders'=>0];
$refunded = [];
while ($next < $now) {
    $from = $next;
    $next = $from->modify('+15 days');
    if ($next > $now) $next = $now;
    $stats['windows']++;
    $token = null;
    $seen = [];
    do {
        $query = [
            'postedAfter'=>$from->format('Y-m-d\TH:i:s\Z'),
            'postedBefore'=>$next->format('Y-m-d\TH:i:s\Z'),
            'marketplaceId'=>$transport->marketplaceId(),
        ];
        if (is_string($token) && $token !== '') $query['nextToken'] = $token;
        $res = $transport->request('GET', '/finances/2024-06-19/transactions', $query);
        if ($res['status'] !== 200) { $errorCodes = []; foreach (($res['data']['errors'] ?? []) as $error) { if (is_array($error)) $errorCodes[] = substr((string)($error['code'] ?? 'UNSPECIFIED'), 0, 45); } throw new RuntimeException('Finances HTTP ' . $res['status'] . ' window=' . $stats['windows'] . ' page=' . ($stats['pages'] + 1) . ' errors=' . implode(',', $errorCodes)); }
        $payload = $res['data']['payload'] ?? $res['data'];
        $rows = $payload['transactions'] ?? null;
        if (!is_array($rows)) throw new UnexpectedValueException('Missing Finances transactions');
        $stats['pages']++;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            if (strtoupper(trim((string)($row['transactionType'] ?? ''))) !== 'REFUND') continue;
            if (!in_array(strtoupper(trim((string)($row['transactionStatus'] ?? ''))), ['RELEASED','DEFERRED_RELEASED'], true)) continue;
            $stats['refunded_rows']++;
            foreach (($row['relatedIdentifiers'] ?? []) as $identifier) {
                if (!is_array($identifier)) continue;
                if (strtoupper(trim((string)($identifier['relatedIdentifierName'] ?? ''))) !== 'ORDER_ID') continue;
                $orderId = trim((string)($identifier['relatedIdentifierValue'] ?? ''));
                if (preg_match('/^\d{3}-\d{7}-\d{7}$/D', $orderId) === 1) $refunded[$orderId] = true;
            }
        }
        $token = $payload['nextToken'] ?? $res['data']['nextToken'] ?? null;
        if ($token !== null && (!is_string($token) || strlen($token) > 2048 || isset($seen[$token]))) {
            throw new UnexpectedValueException('Repeated or malformed Finances cursor');
        }
        if ($token !== null && $token !== '') $seen[$token] = true;
        if ($stats['pages'] > 100) throw new RuntimeException('Page cap exceeded');
        usleep(2100000);
    } while (is_string($token) && $token !== '');
}
$stats['refund_orders'] = count($refunded);
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
