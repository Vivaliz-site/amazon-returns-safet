<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantPersistence.php';
require_once __DIR__ . '/AmazonSpApiClient.php';
require_once __DIR__ . '/SpApi.php';
require_once __DIR__ . '/SpApiEventSink.php';

/**
 * Daily, API-first discovery of customer refunds missing from the case table.
 * Runs inside the existing single-writer daemon; never files SAFE-T or support.
 */
final class SvAmazonRefundDiscovery
{
    private const SOURCE = 'SP_API';
    private const CURSOR = 'buyer_refunds_daily';
    private const LIMIT = 25;

    /** @return array{orders:list<string>,windows:int,pages:int,refunded_rows:int} */
    public static function scan(
        object $transport,
        int $days = 90,
        ?DateTimeImmutable $now = null,
        ?callable $pause = null
    ): array {
        if ($days < 1 || $days > 90) throw new InvalidArgumentException('Refund scan days must be 1..90.');
        if (!method_exists($transport, 'marketplaceId') || !method_exists($transport, 'request')) {
            throw new InvalidArgumentException('Invalid Finances transport.');
        }
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        // Finances rejects postedBefore boundaries within roughly two minutes of now.
        $end = $now->modify('-3 minutes');
        $next = $end->modify('-' . $days . ' days');
        $orders = [];
        $windows = 0;
        $pages = 0;
        $refundRows = 0;
        $marketplace = (string)$transport->marketplaceId();

        while ($next < $end) {
            $from = $next;
            $next = $from->modify('+15 days');
            if ($next > $end) $next = $end;
            $windows++;
            $token = null;
            $seen = [];
            do {
                $query = [
                    'postedAfter' => $from->format('Y-m-d\TH:i:s\Z'),
                    'postedBefore' => $next->format('Y-m-d\TH:i:s\Z'),
                    'marketplaceId' => $marketplace,
                ];
                if (is_string($token) && $token !== '') $query['nextToken'] = $token;
                $response = $transport->request('GET', '/finances/2024-06-19/transactions', $query);
                if ((int)($response['status'] ?? 0) !== 200) {
                    throw new RuntimeException('Finances refund discovery HTTP failure in window ' . $windows);
                }
                $data = $response['data'] ?? null;
                if (!is_array($data)) throw new UnexpectedValueException('Finances data is missing.');
                $payload = is_array($data['payload'] ?? null) ? $data['payload'] : $data;
                $rows = $payload['transactions'] ?? null;
                if (!is_array($rows)) throw new UnexpectedValueException('Finances transactions are missing.');
                $pages++;
                if ($pages > 100) throw new RuntimeException('Refund discovery page cap exceeded.');
                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    if (strtoupper(trim((string)($row['transactionType'] ?? ''))) !== 'REFUND') continue;
                    if (!in_array(strtoupper(trim((string)($row['transactionStatus'] ?? ''))),
                        ['RELEASED', 'DEFERRED_RELEASED'], true)) continue;
                    $refundRows++;
                    foreach (($row['relatedIdentifiers'] ?? []) as $id) {
                        if (!is_array($id)) continue;
                        if (strtoupper(trim((string)($id['relatedIdentifierName'] ?? ''))) !== 'ORDER_ID') continue;
                        $order = trim((string)($id['relatedIdentifierValue'] ?? ''));
                        if (preg_match('/^\d{3}-\d{7}-\d{7}$/D', $order) === 1) $orders[$order] = true;
                    }
                }
                $token = $payload['nextToken'] ?? $data['nextToken'] ?? null;
                if ($token !== null && (!is_string($token) || strlen($token) > 2048 || isset($seen[$token]))) {
                    throw new UnexpectedValueException('Invalid or repeated Finances cursor.');
                }
                if (is_string($token) && $token !== '') $seen[$token] = true;
                if ($pause !== null) $pause(); else usleep(2100000);
            } while (is_string($token) && $token !== '');
        }

        $ids = array_keys($orders);
        sort($ids, SORT_STRING);
        return ['orders' => $ids, 'windows' => $windows, 'pages' => $pages, 'refunded_rows' => $refundRows];
    }

    /** @param list<string> $ids @return array<string,int> */
    public static function ingest(
        SvAmazonTenantPersistence $p,
        SvAmazonReturnsSpApi $api,
        array $ids
    ): array {
        if (count($ids) > self::LIMIT) throw new InvalidArgumentException('Refund discovery apply exceeds daily batch.');
        foreach ($ids as $id) {
            if (!is_string($id) || preg_match('/^\d{3}-\d{7}-\d{7}$/D', $id) !== 1) {
                throw new InvalidArgumentException('Invalid refund discovery order.');
            }
        }
        $result = [
            'inserted_cases' => 0, 'skipped_existing' => 0,
            'skipped_ambiguous' => 0, 'skipped_not_released' => 0, 'failed' => 0,
        ];
        if ($ids === []) return $result;
        $db = $p->db();
        $scope = $p->context();
        // Same mutex as the emergency one-off recovery CLI.
        $mutex = 'returns-refund-repair-' . $scope->tenantId() . '-' . $scope->amazonConnectionId();
        $lock = $db->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute([$mutex]);
        if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Refund recovery is already running.');
        try {
            foreach ($ids as $id) {
                try {
                    if ($p->cases->forOrder($id) !== []) { $result['skipped_existing']++; continue; }
                    $order = $api->syncOrder($id);
                    $items = is_array($order['order_items'] ?? null) ? $order['order_items'] : [];
                    if (count($items) !== 1 || !is_array($items[0])) {
                        $result['skipped_ambiguous']++;
                        continue; // Never invent an item identity for multi-item refunds.
                    }
                    $finance = $api->listTransactions($id);
                    if (SvAmazonSpApiEventSink::refundObservation($finance['transactions'] ?? []) === null) {
                        $result['skipped_not_released']++;
                        continue;
                    }
                    $db->beginTransaction();
                    if ($p->cases->forOrder($id) !== []) {
                        $db->rollBack();
                        $result['skipped_existing']++;
                        continue;
                    }
                    $saved = SvAmazonSpApiEventSink::persist($p, $order, $finance['transactions'] ?? []);
                    $db->commit();
                    $result['inserted_cases'] += count($saved['cases'] ?? []);
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $result['failed']++;
                    error_log('[amazon-refund-discovery] order_hash=' . substr(hash('sha256', $id), 0, 12)
                        . ' class=' . $e::class);
                }
            }
        } finally {
            $unlock = $db->prepare('SELECT RELEASE_LOCK(?)');
            $unlock->execute([$mutex]);
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public static function runDue(
        SvAmazonTenantPersistence $p,
        SvAmazonReturnsConfig $config,
        ?DateTimeImmutable $now = null
    ): array {
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $today = $now->format('Y-m-d');
        $state = $p->cursors->load(self::SOURCE, self::CURSOR);
        if (($state['value'] ?? null) === $today) return ['status' => 'SKIPPED_TODAY'];
        $last = $state['metadata']['last_attempt_at'] ?? null;
        if (is_string($last) && $last !== '') {
            try {
                $attempt = new DateTimeImmutable($last);
                if ($attempt->modify('+30 minutes') > $now) {
                    return ['status' => 'SKIPPED_BACKOFF'];
                }
            } catch (Throwable) {
                // Ignore malformed historical metadata and retry through the normal gate.
            }
        }
        $source = (string)($state['value'] ?? '1970-01-01');
        $metadata = ['last_attempt_at' => $now->format(DATE_ATOM)];
        try {
            $transport = new SvAmazonSpApiClient($config);
            $scan = self::scan($transport, 90, $now);
            $missing = [];
            foreach ($scan['orders'] as $id) {
                if ($p->cases->forOrder($id) === []) $missing[] = $id;
            }
            if (count($missing) > 75) throw new RuntimeException('Too many missing refunded orders for scoped discovery.');
            $batch = array_slice($missing, 0, self::LIMIT);
            $result = self::ingest($p, new SvAmazonReturnsSpApi($transport), $batch);
            $finished = count($missing) <= self::LIMIT && $result['failed'] === 0;
            $metadata += [
                'status' => $finished ? 'OK' : 'PARTIAL',
                'found_orders' => count($scan['orders']),
                'missing_orders' => count($missing),
                'inserted_cases' => $result['inserted_cases'],
                'skipped_ambiguous' => $result['skipped_ambiguous'],
                'failed' => $result['failed'],
            ];
            $p->cursors->save(self::SOURCE, self::CURSOR, $finished ? $today : $source, $metadata);
            return [
                'status' => $metadata['status'],
                'windows' => $scan['windows'], 'pages' => $scan['pages'],
                'refund_orders' => count($scan['orders']),
                'missing_orders' => count($missing),
            ] + $result;
        } catch (Throwable $e) {
            $p->cursors->save(self::SOURCE, self::CURSOR, $source,
                $metadata + ['status' => 'FAILED', 'error_class' => $e::class]);
            return ['status' => 'FAILED', 'error_class' => $e::class];
        }
    }
}
