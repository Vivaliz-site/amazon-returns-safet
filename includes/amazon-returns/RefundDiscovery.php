<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantPersistence.php';
require_once __DIR__ . '/AmazonSpApiClient.php';
require_once __DIR__ . '/SpApi.php';
require_once __DIR__ . '/SpApiEventSink.php';
require_once __DIR__ . '/StatusBridgeService.php';

/**
 * Daily, API-first discovery of customer refunds missing from the case table.
 * Runs inside the existing single-writer daemon; never files SAFE-T or support.
 */
final class SvAmazonRefundDiscovery
{
    private const SOURCE = 'SP_API';
    private const CURSOR = 'buyer_refunds_daily';
    private const LIMIT = 25;

    /**
     * Full 90-day recheck remains mandatory even with an external SAFE-T
     * watermark: late-arriving/older unresolved refunds must never disappear.
     * @return array<string,mixed>
     */
    public static function scan(
        object $transport,
        int $days = 90,
        ?DateTimeImmutable $now = null,
        ?callable $pause = null,
        ?DateTimeImmutable $lastConfirmedSafeTAt = null
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
        $afterLatestSafeT = [];
        $unknownRefundDates = [];
        $lastConfirmedSafeTAt=$lastConfirmedSafeTAt?->setTimezone(new DateTimeZone('UTC'));
        $windows = 0;
        $pages = 0;
        $refundRows = 0;
        $unlinkedRefundRows = 0;
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
                    // A released refund without an unambiguous ORDER_ID still exists
                    // financially. Keep it visible for manual reconciliation rather
                    // than silently reporting complete order-level coverage.
                    $candidateOrders = [];
                    $malformedOrderId = false;
                    $identifiers = is_array($row['relatedIdentifiers'] ?? null)
                        ? $row['relatedIdentifiers'] : [];
                    foreach ($identifiers as $id) {
                        if (!is_array($id)) continue;
                        if (strtoupper(trim((string)($id['relatedIdentifierName'] ?? ''))) !== 'ORDER_ID') continue;
                        $value = $id['relatedIdentifierValue'] ?? null;
                        $order = is_string($value) ? trim($value) : '';
                        if (preg_match('/^\d{3}-\d{7}-\d{7}$/D', $order) !== 1) {
                            $malformedOrderId = true;
                            continue;
                        }
                        $candidateOrders[$order] = true;
                    }
                    // One financial refund must not map to conflicting order IDs.
                    // An invalid identifier alongside a valid ID is also unsafe.
                    if ($malformedOrderId || count($candidateOrders) !== 1) {
                        $unlinkedRefundRows++;
                    } else {
                        $orderId=array_key_first($candidateOrders);
                        $orders[$orderId] = true;
                        if($lastConfirmedSafeTAt===null){
                            $afterLatestSafeT[$orderId]=true;
                        }else{
                            $posted=self::verifiedRefundPostedAt(
                                $row['postedDate']??$row['postedTime']??null
                            );
                            if($posted===null){
                                $unknownRefundDates[$orderId]=true;
                            }elseif($posted>$lastConfirmedSafeTAt){
                                $afterLatestSafeT[$orderId]=true;
                            }
                        }
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
        $after=array_keys($afterLatestSafeT);
        $unknown=array_keys($unknownRefundDates);
        sort($after,SORT_STRING);
        sort($unknown,SORT_STRING);
        return ['orders' => $ids, 'windows' => $windows, 'pages' => $pages, 'refunded_rows' => $refundRows,
            'unlinked_refund_rows' => $unlinkedRefundRows,
            'orders_after_latest_safe_t'=>$after,
            'orders_with_unknown_refund_date'=>$unknown];
    }

    private static function verifiedRefundPostedAt(mixed $value): ?DateTimeImmutable
    {
        if(!is_string($value))return null;
        $raw=trim($value);
        if(preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?(?:Z|[+-]\\d{2}:\\d{2})$/D',$raw)!==1){
            return null;
        }
        try{
            return (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('UTC'));
        }catch(Throwable){
            return null;
        }
    }

    /**
     * A completed daily scan is not necessarily complete refund case coverage.
     * Multi-item or unconfirmed refunds still need manual item-level review,
     * while their daily cursor must advance to avoid a retry storm.
     *
     * @param array<string,int> $result
     * @return array{status:string,completed:bool,review_required:int}
     */
    public static function assessDailyCompletion(int $missing, array $result): array
    {
        if ($missing < 0) throw new InvalidArgumentException('Missing refund count must be nonnegative.');
        $failures = max(0, (int)($result['failed'] ?? 0));
        $reviewRequired = max(0, (int)($result['skipped_ambiguous'] ?? 0))
            + max(0, (int)($result['skipped_not_released'] ?? 0))
            + max(0, (int)($result['unlinked_refund_rows'] ?? 0));
        $completed = $missing <= self::LIMIT && $failures === 0;
        return [
            'status' => !$completed ? 'PARTIAL' : ($reviewRequired > 0 ? 'REVIEW_REQUIRED' : 'OK'),
            'completed' => $completed,
            'review_required' => $reviewRequired,
        ];
    }

    /**
     * Surface pending item-level review even for a legacy daily cursor written
     * as OK before the review-required status existed; do not re-run the API.
     *
     * @param array<string,mixed> $metadata
     * @return array{last_scan_status:string,review_required:int}
     */
    public static function cachedDailySummary(array $metadata): array
    {
        $legacyCount = max(0, (int)($metadata['skipped_ambiguous'] ?? 0))
            + max(0, (int)($metadata['skipped_not_released'] ?? 0))
            + max(0, (int)($metadata['unlinked_refund_rows'] ?? 0));
        $reviewRequired = max($legacyCount, max(0, (int)($metadata['review_required'] ?? 0)));
        $status = strtoupper(trim((string)($metadata['status'] ?? 'UNKNOWN')));
        if ($status === 'OK' && $reviewRequired > 0) $status = 'REVIEW_REQUIRED';
        return ['last_scan_status' => $status, 'review_required' => $reviewRequired];
    }

    /** Respect the established Amazon SP-API per-order pacing in batch repair. */
    public static function delayForOrderMicros(int $index): int
    {
        if ($index < 0) throw new InvalidArgumentException('Order index must be nonnegative.');
        return $index === 0 ? 0 : 2200000;
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
            foreach ($ids as $index => $id) {
                $delay = self::delayForOrderMicros($index);
                if ($delay > 0) usleep($delay);
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
        if (($state['value'] ?? null) === $today) {
            $meta = is_array($state['metadata'] ?? null) ? $state['metadata'] : [];
            return ['status' => 'SKIPPED_TODAY'] + self::cachedDailySummary($meta);
        }
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
            // Only a Seller Central-verified, externally identified SAFE-T can
            // anchor the incremental view. The anchor is OBSERVED_AT, not an
            // invented Amazon creation timestamp or an outbox success.
            $lastClaim=$p->cases->latestConfirmedSafeTObservation();
            $lastClaimAt=$lastClaim===null?null:new DateTimeImmutable(
                $lastClaim['confirmed_at'],new DateTimeZone('UTC')
            );
            if($lastClaimAt!==null && $lastClaimAt>$now){
                throw new UnexpectedValueException('SAFE-T observation is in the future.');
            }
            $transport = new SvAmazonSpApiClient($config);
            $scan = self::scan($transport, 90, $now, null, $lastClaimAt);
            $missing = [];
            foreach ($scan['orders'] as $id) {
                if ($p->cases->forOrder($id) === []) $missing[] = $id;
            }
            if (count($missing) > 75) throw new RuntimeException('Too many missing refunded orders for scoped discovery.');
            $batch = array_slice($missing, 0, self::LIMIT);
            $result = self::ingest($p, new SvAmazonReturnsSpApi($transport), $batch);
            $result['unlinked_refund_rows'] = (int)$scan['unlinked_refund_rows'];
            // Use the existing scoped Seller Central READ outbox/worker. A
            // missing claim locally is not proof of its absence at Amazon.
            // Older unresolved refunds are still swept by ensureJobs().
            $priorityOrders=array_values(array_unique(array_merge(
                $scan['orders_after_latest_safe_t'],
                $scan['orders_with_unknown_refund_date']
            )));
            $claimChecks=(new SvAmazonReturnsStatusBridgeService($p))
                ->ensureRefundClaimChecks($priorityOrders,$now);
            // Finances scan OK does not mean Amazon claims were verified.
            // Only an independent Seller Central claim ID readback can do so.
            $claimReadbackState=$priorityOrders===[]?'NO_POST_ANCHOR_REFUNDS'
                :'PENDING_SELLER_CENTRAL_READBACK';
            $assessment = self::assessDailyCompletion(count($missing), $result);
            $finished = $assessment['completed'];
            $metadata += [
                'status' => $assessment['status'],
                'review_required' => $assessment['review_required'],
                'found_orders' => count($scan['orders']),
                'missing_orders' => count($missing),
                'inserted_cases' => $result['inserted_cases'],
                'skipped_ambiguous' => $result['skipped_ambiguous'],
                'skipped_not_released' => $result['skipped_not_released'],
                'unlinked_refund_rows' => $result['unlinked_refund_rows'],
                'failed' => $result['failed'],
                'latest_confirmed_safe_t_observed_at'=>$lastClaim['confirmed_at']??null,
                'refund_orders_after_latest_safe_t'=>count($scan['orders_after_latest_safe_t']),
                'refund_orders_date_unverified'=>count($scan['orders_with_unknown_refund_date']),
                'seller_central_claim_checks_enqueued'=>$claimChecks['enqueued'],
                'seller_central_claim_checks_already_active'=>$claimChecks['already_active'],
                'seller_central_claim_checks_ambiguous'=>$claimChecks['ambiguous'],
                'seller_central_claim_checks_unmapped'=>$claimChecks['unmapped'],
                'seller_central_claim_verification_status'=>$claimReadbackState,
            ];
            $p->cursors->save(self::SOURCE, self::CURSOR, $finished ? $today : $source, $metadata);
            return [
                'status' => $metadata['status'],
                'windows' => $scan['windows'], 'pages' => $scan['pages'],
                'refund_orders' => count($scan['orders']),
                'missing_orders' => count($missing),
                'review_required' => $assessment['review_required'],
                'latest_confirmed_safe_t_observed_at'=>$lastClaim['confirmed_at']??null,
                'refund_orders_after_latest_safe_t'=>count($scan['orders_after_latest_safe_t']),
                'seller_central_claim_checks'=>$claimChecks,
                'seller_central_claim_verification_status'=>$claimReadbackState,
            ] + $result;
        } catch (Throwable $e) {
            $p->cursors->save(self::SOURCE, self::CURSOR, $source,
                $metadata + ['status' => 'FAILED', 'error_class' => $e::class]);
            return ['status' => 'FAILED', 'error_class' => $e::class];
        }
    }
}
