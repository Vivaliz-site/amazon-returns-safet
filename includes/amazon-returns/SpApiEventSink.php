<?php

declare(strict_types=1);

require_once __DIR__ . '/Enums.php';
require_once __DIR__ . '/GmailEventSink.php';
require_once __DIR__ . '/ReturnsReportParser.php';
require_once __DIR__ . '/TenantPersistence.php';
require_once __DIR__ . '/FinancialObservations.php';

final class SvAmazonSpApiEventSink
{
    /** @return string */
    public static function programFromOrder(array $order): string
    {
        $programs = array_map(
            static fn(mixed $value): string => strtoupper(trim((string)$value)),
            is_array($order['programs'] ?? null) ? $order['programs'] : []
        );
        foreach ($programs as $program) {
            if (in_array($program, ['DELIVERY_BY_AMAZON','DELIVER_BY_AMAZON','DBA'], true)) {
                return SvAmazonReturnPrograms::DELIVERY_BY_AMAZON;
            }
            if (in_array($program, ['FBA_ONSITE','FBA_ON_SITE'], true)) {
                return SvAmazonReturnPrograms::FBA_ONSITE;
            }
        }
        $fulfillment = is_array($order['fulfillment'] ?? null) ? $order['fulfillment'] : [];
        // 'fulfilledBy' is the real Orders v2026-01-01 field (AMAZON|MERCHANT). AMAZON means
        // standard FBA (Amazon-fulfilled, Amazon-managed reimbursement -- not SAFE-T-managed),
        // which must stay distinct from seller-fulfilled STANDARD. 'channel'/'fulfillmentChannel'
        // are kept as fallbacks for other callers/fixtures that predate the real field name.
        $fulfilledBy = strtoupper(trim((string)($fulfillment['fulfilledBy'] ?? '')));
        if ($fulfilledBy === 'AMAZON') return SvAmazonReturnPrograms::FBA;
        if ($fulfilledBy === 'MERCHANT') return SvAmazonReturnPrograms::STANDARD;
        $channel = strtoupper(trim((string)($fulfillment['channel'] ?? $fulfillment['fulfillmentChannel'] ?? '')));
        if (in_array($channel, ['MERCHANT','MFN','SELLER'], true)) return SvAmazonReturnPrograms::STANDARD;
        return SvAmazonReturnPrograms::UNKNOWN;
    }

    /** @return array{confirmed:bool,tracking_ids:list<string>,carriers:list<string>} */
    public static function customerDeliveryObservation(array $order,array $item,bool $single): array
    {
        $itemId=trim((string)($item['orderItemId']??$item['order_item_id']??''));
        $packages=is_array($order['packages']??null)?array_values(array_filter($order['packages'],'is_array')):[];
        $confirmed=false;$tracking=[];$carriers=[];
        foreach($packages as $package){
            $statusData=is_array($package['packageStatus']??null)?$package['packageStatus']:[];
            $status=strtoupper(trim((string)($statusData['status']??$package['status']??'')));
            if($status!=='DELIVERED')continue;
            $packageItems=is_array($package['packageItems']??null)?array_values(array_filter($package['packageItems'],'is_array')):[];
            $matches=$single && $packageItems===[];
            foreach($packageItems as $packageItem){
                $packageItemId=trim((string)($packageItem['orderItemId']??$packageItem['order_item_id']??''));
                if($itemId!=='' && $packageItemId!=='' && hash_equals($itemId,$packageItemId)){$matches=true;break;}
            }
            if(!$matches)continue;
            $confirmed=true;
            $trackingId=trim((string)($package['trackingNumber']??$package['trackingId']??''));
            if($trackingId!=='')$tracking[]=$trackingId;
            $carrier=trim((string)($package['carrier']??$package['carrierCode']??''));
            if($carrier!=='')$carriers[]=$carrier;
        }
        return ['confirmed'=>$confirmed,'tracking_ids'=>array_values(array_unique($tracking)),'carriers'=>array_values(array_unique($carriers))];
    }

    /** @return array<string,mixed>|null */
    public static function refundObservation(array $transactions): ?array
    {
        $groups = [];
        $dates = [];
        $ids = [];

        foreach ($transactions as $tx) {
            if (!is_array($tx)) continue;
            $type = strtoupper(trim((string)($tx['transaction_type'] ?? '')));
            $status = strtoupper(trim((string)($tx['transaction_status'] ?? '')));
            if (!str_contains($type, 'REFUND') || !in_array($status, ['RELEASED','DEFERRED_RELEASED'], true)) continue;
            $money = is_array($tx['total_amount'] ?? null) ? $tx['total_amount'] : [];
            $raw = $money['amount'] ?? null;
            if (!is_numeric($raw) || (float)$raw >= 0) continue;
            $currency = strtoupper(trim((string)($money['currency'] ?? '')));
            $unitAmount = number_format(abs((float)$raw), 2, '.', '');
            $related = $tx['related_identifiers'] ?? $tx['relatedIdentifiers'] ?? [];
            $relatedKey = is_array($related) ? hash('sha256', json_encode($related, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]') : '';
            $key = $type . '|' . $unitAmount . '|' . $currency . '|' . $relatedKey;
            if (!isset($groups[$key])) $groups[$key] = ['amount'=>(float)$unitAmount,'statuses'=>[]];
            $groups[$key]['statuses'][$status] = (int)($groups[$key]['statuses'][$status] ?? 0) + 1;
            $date = self::utcSql($tx['posted_at'] ?? null);
            if ($date !== null) $dates[] = $date;
            $id = trim((string)($tx['transaction_id'] ?? ''));
            if ($id !== '') $ids[] = $id;
        }
        if ($groups === [] || $dates === []) return null;
        $amount = 0.0;
        foreach ($groups as $group) {
            $released = (int)($group['statuses']['RELEASED'] ?? 0);
            $deferredReleased = (int)($group['statuses']['DEFERRED_RELEASED'] ?? 0);
            $economicOccurrences = max($released, $deferredReleased);
            $amount += ((float)$group['amount']) * $economicOccurrences;
        }
        if ($amount <= 0.00001) return null;
        sort($dates, SORT_STRING);
        return [
            'seller_debit_at'=>$dates[0],
            'refund_at'=>$dates[0],
            'refund_amount'=>number_format($amount, 2, '.', ''),
            'refund_initiator'=>SvAmazonRefundInitiators::UNKNOWN,
            'transaction_ids'=>array_values(array_unique($ids)),
        ];
    }

    /** @return array{cases:list<int>,single_item:bool,refund_event_id:?int} */
    public static function persist(
        SvAmazonTenantPersistence $p,
        array $order,
        array $transactions
    ): array {
        $orderId = trim((string)($order['order_id'] ?? ''));
        if ($orderId === '') throw new InvalidArgumentException('SP-API order_id is required.');
        $items = is_array($order['order_items'] ?? null)
            ? array_values(array_filter($order['order_items'], 'is_array')) : [];
        if ($items === []) return ['cases'=>[],'single_item'=>false,'refund_event_id'=>null];

        $single = count($items) === 1;
        $caseIds = [];
        foreach ($items as $item) {
            $caseId = self::upsertCaseScoped($p, $order, $item, $single);
            $caseIds[] = $caseId;
            self::appendOrderEventScoped($p->events, $caseId, $order, $item, $single);
        }
        foreach ($caseIds as $caseId) {
            self::appendTransactionsScoped($p->events, $caseId, $transactions, $single);
        }

        $refundEventId = null;
        $refund = self::refundObservation($transactions);
        $quantity = $single
            ? max(1, (int)($items[0]['quantityOrdered'] ?? $items[0]['quantity'] ?? 1)) : 0;
        if ($single && $quantity === 1 && $refund !== null && $caseIds !== []) {
            $refundEventId = $p->events->append([
                'case_id'=>$caseIds[0],
                'event_type'=>'REFUND_CONFIRMED',
                'source'=>'SP_API_FINANCES',
                'source_event_id'=>implode(',', $refund['transaction_ids']) ?: null,
                'idempotency_key'=>hash(
                    'sha256',
                    'spapi-refund|' . $p->context()->scopeKey() . '|' . $orderId . '|'
                    . implode(',', $refund['transaction_ids'])
                ),
                'occurred_at'=>$refund['seller_debit_at'],
                'payload'=>[
                    'quantity_refunded'=>$quantity,
                    'refund_initiator'=>$refund['refund_initiator'],
                    'program'=>self::programFromOrder($order),
                    'seller_debit_at'=>$refund['seller_debit_at'],
                    'refund_at'=>$refund['refund_at'],
                    'refund_amount'=>$refund['refund_amount'],
                    'financial_truth'=>true,
                ],
                'evidence_sha256'=>null,
            ]);
            $p->cases->update($caseIds[0], [
                'expected_reimbursement_amount'=>$refund['refund_amount'],
            ]);
        }
        return ['cases'=>$caseIds,'single_item'=>$single,'refund_event_id'=>$refundEventId];
    }

    /**
     * @param list<array<string,mixed>> $events
     * @param list<string> $requestIds
     * @param list<string> $responseHashes
     * @return array{matched:int,persisted:int,unmatched:int}
     */
    public static function persistSafeTReimbursements(
        SvAmazonTenantPersistence $p,
        string $orderId,
        array $events,
        array $requestIds = [],
        array $responseHashes = []
    ): array {
        $orderId = trim($orderId);
        if ($orderId === '') throw new InvalidArgumentException('SAFE-T reimbursement order ID is required.');
        if ($events === []) return ['matched'=>0,'persisted'=>0,'unmatched'=>0];

        $cases = $p->cases->forOrder($orderId);
        $matched = 0;
        $persisted = 0;
        $unmatched = 0;
        $requestIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string=>trim((string)$value),
            $requestIds
        ))));
        $responseHashes = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string=>strtolower(trim((string)$value)),
            $responseHashes
        ), static fn(string $value): bool=>preg_match('/^[a-f0-9]{64}$/', $value) === 1)));

        foreach ($events as $event) {
            if (!is_array($event)) throw new InvalidArgumentException('SAFE-T reimbursement event must be an array.');
            $claimId = trim((string)($event['safe_t_claim_id'] ?? ''));
            $postedAt = self::utcSql($event['posted_at'] ?? null);
            $money = is_array($event['reimbursed_amount'] ?? null)
                ? $event['reimbursed_amount'] : [];
            $amount = trim((string)($money['amount'] ?? ''));
            $currency = strtoupper(trim((string)($money['currency'] ?? '')));
            if ($claimId === '' || $postedAt === null || !is_numeric($amount)
                || (float)$amount <= 0 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new InvalidArgumentException('SAFE-T reimbursement event is incomplete.');
            }

            $candidates = array_values(array_filter(
                $cases,
                static fn(array $case): bool=>hash_equals(
                    $claimId,
                    trim((string)($case['safe_t_id'] ?? ''))
                )
            ));
            $items = is_array($event['items'] ?? null)
                ? array_values(array_filter($event['items'], 'is_array')) : [];
            $itemIds = [];
            foreach ($items as $item) {
                $itemId = trim((string)(
                    $item['orderItemId'] ?? $item['OrderItemId']
                    ?? $item['amazonOrderItemId'] ?? $item['AmazonOrderItemId'] ?? ''
                ));
                if ($itemId !== '') $itemIds[] = $itemId;
            }
            $itemIds = array_values(array_unique($itemIds));
            if (count($candidates) > 1 && $itemIds !== []) {
                $candidates = array_values(array_filter(
                    $candidates,
                    static fn(array $case): bool=>in_array(
                        (string)($case['amazon_order_item_id'] ?? ''),
                        $itemIds,
                        true
                    )
                ));
            }
            if (count($candidates) !== 1) {
                $unmatched++;
                continue;
            }

            $matched++;
            $caseId = (int)($candidates[0]['id'] ?? 0);
            $reason = isset($event['reason_code']) ? trim((string)$event['reason_code']) : null;
            $eventRequestId = trim((string)($event['request_id'] ?? ''));
            $eventResponseHash = strtolower(trim((string)($event['response_sha256'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $eventResponseHash) !== 1) {
                $eventResponseHash = $responseHashes[0] ?? '';
            }
            $fingerprint = hash('sha256', json_encode([
                'scope'=>$p->context()->scopeKey(),
                'order_id'=>$orderId,
                'safe_t_claim_id'=>$claimId,
                'posted_at'=>$postedAt,
                'amount'=>number_format((float)$amount, 2, '.', ''),
                'currency'=>$currency,
                'reason_code'=>$reason,
                'items'=>$items,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $p->events->append([
                'case_id'=>$caseId,
                'event_type'=>'SAFE_T_REIMBURSEMENT_OBSERVED',
                'source'=>'SP_API_FINANCES_V0',
                'source_event_id'=>$claimId,
                'idempotency_key'=>$fingerprint,
                'occurred_at'=>$postedAt,
                'payload'=>[
                    'order_id'=>$orderId,
                    'safe_t_claim_id'=>$claimId,
                    'posted_at'=>$postedAt,
                    'reimbursed_amount'=>[
                        'amount'=>number_format((float)$amount, 2, '.', ''),
                        'currency'=>$currency,
                    ],
                    'reason_code'=>$reason,
                    'items'=>$items,
                    'request_ids'=>array_values(array_unique(array_filter(
                        array_merge($requestIds, [$eventRequestId])
                    ))),
                    'response_sha256'=>array_values(array_unique(array_filter(
                        array_merge($responseHashes, [$eventResponseHash])
                    ))),
                    'financial_truth'=>true,
                ],
                'evidence_sha256'=>$eventResponseHash !== '' ? $eventResponseHash : null,
            ]);
            $persisted++;
        }
        return ['matched'=>$matched,'persisted'=>$persisted,'unmatched'=>$unmatched];
    }

    private static function upsertCaseScoped(
        SvAmazonTenantPersistence $p,
        array $order,
        array $item,
        bool $single
    ): int {
        $orderId = trim((string)$order['order_id']);
        $itemId = trim((string)($item['orderItemId'] ?? $item['order_item_id'] ?? ''));
        if ($itemId === '') throw new InvalidArgumentException('SP-API order item ID is required.');
        if ($single) {
            $p->cases->resolvePlaceholder($orderId, SvAmazonGmailEventSink::UNRESOLVED_ITEM_ID, $itemId);
        }
        $marketplace = trim((string)($order['marketplace_id'] ?? ''));
        if ($marketplace === '') $marketplace = $p->cases->marketplaceId();
        return $p->cases->upsertOrderItem([
            'amazon_order_id'=>$orderId,
            'amazon_order_item_id'=>$itemId,
            'marketplace_id'=>$marketplace,
            'sku'=>self::nullable($item['sellerSku'] ?? $item['sku'] ?? null),
            'asin'=>self::nullable($item['asin'] ?? null),
            'quantity_ordered'=>max(1, (int)($item['quantityOrdered'] ?? $item['quantity'] ?? 1)),
            'program'=>self::programFromOrder($order),
            'refund_initiator'=>SvAmazonRefundInitiators::UNKNOWN,
            'physical_status'=>SvAmazonReturnPhysicalStatuses::NOT_RECEIVED,
            'state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,
        ]);
    }

    private static function appendOrderEventScoped(
        SvAmazonTenantReturnEventStore $events,
        int $caseId,
        array $order,
        array $item,
        bool $single
    ): int {
        $orderId = trim((string)$order['order_id']);
        $itemId = trim((string)($item['orderItemId'] ?? $item['order_item_id'] ?? ''));
        $occurred = self::utcSql($order['created_at'] ?? null) ?? gmdate('Y-m-d H:i:s');
        $requestId = trim((string)($order['request_id'] ?? ''));
        $delivery=self::customerDeliveryObservation($order,$item,$single);
        return $events->append([
            'case_id'=>$caseId,
            'event_type'=>'ORDER_SYNCED',
            'source'=>'SP_API_ORDERS',
            'source_event_id'=>$requestId !== '' ? $requestId : null,
            'idempotency_key'=>hash(
                'sha256',
                'spapi-order|' . $orderId . '|' . $itemId . '|'
                . ($order['last_updated_at'] ?? $order['created_at'] ?? 'unknown')
            ),
            'occurred_at'=>$occurred,
            'payload'=>[
                'order_at'=>$occurred,
                'quantity_ordered'=>max(1, (int)($item['quantityOrdered'] ?? $item['quantity'] ?? 1)),
                'program'=>self::programFromOrder($order),
                'marketplace_id'=>$order['marketplace_id'] ?? null,
                'customer_delivery_confirmed'=>$delivery['confirmed'],
                'customer_tracking_ids'=>$delivery['tracking_ids'],
                'customer_delivery_carriers'=>$delivery['carriers'],
                'customer_delivery_evidence_source'=>'SP_API_ORDERS_PACKAGES',
                'financial_truth'=>false,
            ],
            'evidence_sha256'=>null,
        ]);
    }

    public static function financialObservationKey(int $caseId, array $transaction, int $previousEventId = 0): string
    {
        return SvAmazonFinancialObservations::key($caseId, $transaction, $previousEventId);
    }

    private static function appendTransactionsScoped(
        SvAmazonTenantReturnEventStore $events,
        int $caseId,
        array $transactions,
        bool $single
    ): void {
        if (!$single) return;
        $latest = SvAmazonFinancialObservations::latestEvents($events->eventsForCase($caseId));
        foreach ($transactions as $index=>$tx) {
            if (!is_array($tx)) continue;
            $txId = trim((string)($tx['transaction_id'] ?? ''));
            $identity = SvAmazonFinancialObservations::identity($tx);
            $previous = $latest[$identity] ?? null;
            if (is_array($previous) && SvAmazonFinancialObservations::signature($previous['payload']['transaction']) === SvAmazonFinancialObservations::signature($tx)) continue;
            $previousId = is_array($previous) ? (int)($previous['id'] ?? 0) : 0;
            $occurred = self::utcSql($tx['posted_at'] ?? null) ?? gmdate('Y-m-d H:i:s');
            $eventId = $events->append([
                'case_id'=>$caseId,
                'event_type'=>'FINANCIAL_TRANSACTION_OBSERVED',
                'source'=>'SP_API_FINANCES',
                'source_event_id'=>$txId !== '' ? $txId : null,
                'idempotency_key'=>self::financialObservationKey($caseId, $tx, $previousId),
                'occurred_at'=>$occurred,
                'payload'=>['transaction'=>$tx,'financial_truth'=>true],
                'evidence_sha256'=>null,
            ]);
            $latest[$identity] = ['id'=>$eventId, 'payload'=>['transaction'=>$tx]];
        }
    }

    /** @return array{matched:bool,applied:bool} */
    public static function persistReturnsReportRow(
        SvAmazonTenantPersistence $target,
        array $row,
        string $reportId
    ): array {
        $orderId = SvAmazonReturnsReportParser::orderId($row);
        $itemId = SvAmazonReturnsReportParser::orderItemId($row);
        if ($orderId === '') return ['matched'=>false,'applied'=>false];
        $initiator = SvAmazonReturnsReportParser::refundInitiatorFromRow($row);
        if ($initiator === SvAmazonRefundInitiators::UNKNOWN) {
            return ['matched'=>false,'applied'=>false];
        }
        $case = $itemId !== ''
            ? $target->cases->findByOrderItem($orderId, $itemId)
            : $target->cases->findSingleByOrder($orderId);
        $caseId = (int)($case['id'] ?? 0);
        if ($caseId < 1) return ['matched'=>false,'applied'=>false];
        $target->events->append([
            'case_id'=>$caseId,
            'event_type'=>'RETURNS_REPORT_MATCHED',
            'source'=>'SP_API_REPORTS',
            'source_event_id'=>trim($reportId) !== '' ? trim($reportId) : null,
            'idempotency_key'=>hash(
                'sha256',
                'spapi-returns-report|' . $target->context()->scopeKey() . '|'
                . $reportId . '|' . $orderId . '|' . $itemId . '|' . $initiator
            ),
            'occurred_at'=>gmdate('Y-m-d H:i:s'),
            'payload'=>[
                'refund_initiator'=>$initiator,
                'financial_truth'=>false,
                'return_reason'=>self::nullable($row['Return Reason'] ?? null),
            ],
            'evidence_sha256'=>null,
        ]);
        return ['matched'=>true,'applied'=>true];
    }

    private static function utcSql(mixed $value): ?string
    {
        if (!is_scalar($value) || trim((string)$value) === '') return null;
        try {
            return (new DateTimeImmutable((string)$value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private static function nullable(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}
