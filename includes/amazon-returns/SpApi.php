<?php

declare(strict_types=1);

/**
 * Read/ingestion facade for Amazon Returns recovery.
 *
 * There is intentionally no SAFE-T filing/appeal operation here. Amazon does
 * not expose a verified public SP-API operation for those workflows.
 */
final class SvAmazonReturnsSpApi
{
    private object $client;
    /** @var callable(string):string */
    private $documentTransport;

    public function __construct(?object $client = null, ?callable $documentTransport = null)
    {
        if ($client === null) {
            require_once __DIR__ . '/../marketplace/AmazonPublisher.php';
            $client = new SvAmazonClient();
        }
        foreach (['request', 'marketplaceId'] as $method) {
            if (!method_exists($client, $method)) {
                throw new InvalidArgumentException('Amazon transport missing method: ' . $method);
            }
        }
        $this->client = $client;
        $this->documentTransport = $documentTransport ?? [$this, 'httpDocument'];
    }

    /** @return array<string,mixed> */
    public function syncOrder(string $amazonOrderId): array
    {
        $orderId = self::requiredId($amazonOrderId, 'Amazon order ID');
        $response = $this->client->request(
            'GET',
            '/orders/2026-01-01/orders/' . rawurlencode($orderId),
            ['includedData' => 'FULFILLMENT,PROCEEDS,EXPENSE,PACKAGES']
        );
        self::assertSuccess($response, 'Orders getOrder');
        $data = self::responseData($response);
        $order = self::firstArray($data, ['payload', 'order']) ?? $data;
        $normalizedOrderId = trim((string)($order['orderId'] ?? $order['amazonOrderId'] ?? $orderId));
        $salesChannel = is_array($order['salesChannel'] ?? null) ? $order['salesChannel'] : [];
        $items = is_array($order['orderItems'] ?? null) ? array_values(array_filter($order['orderItems'], 'is_array')) : [];
        $programs = is_array($order['programs'] ?? null) ? array_values(array_map('strval', $order['programs'])) : [];

        return [
            'source' => 'SP_API_ORDERS',
            'request_id' => (string)($response['request_id'] ?? ''),
            'order_id' => $normalizedOrderId,
            'marketplace_id' => trim((string)($salesChannel['marketplaceId'] ?? $order['marketplaceId'] ?? '')),
            'created_at' => self::nullableString($order['createdTime'] ?? $order['purchaseDate'] ?? null),
            'last_updated_at' => self::nullableString($order['lastUpdatedTime'] ?? $order['lastUpdateDate'] ?? null),
            'programs' => $programs,
            'fulfillment' => is_array($order['fulfillment'] ?? null) ? $order['fulfillment'] : [],
            'packages' => is_array($order['packages'] ?? null) ? array_values($order['packages']) : [],
            'order_items' => $items,
        ];
    }

    /** @return array{source:string,financial_truth:bool,request_ids:list<string>,transactions:list<array<string,mixed>>} */
    public function listTransactions(string $amazonOrderId): array
    {
        $orderId = self::requiredId($amazonOrderId, 'Amazon order ID');
        $query = [
            'relatedIdentifierName' => 'ORDER_ID',
            'relatedIdentifierValue' => $orderId,
            'marketplaceId' => (string)$this->client->marketplaceId(),
        ];
        $transactions = [];
        $requestIds = [];
        $nextToken = null;
        $pages = 0;

        do {
            $requestQuery = $query;
            if (is_string($nextToken) && $nextToken !== '') {
                $requestQuery['nextToken'] = $nextToken;
            }
            $response = $this->client->request('GET', '/finances/2024-06-19/transactions', $requestQuery);
            self::assertSuccess($response, 'Finances listTransactions');
            $requestId = trim((string)($response['request_id'] ?? ''));
            if ($requestId !== '') $requestIds[] = $requestId;
            $data = self::responseData($response);
            $payload = self::firstArray($data, ['payload']) ?? $data;
            $pageTransactions = is_array($payload['transactions'] ?? null) ? $payload['transactions'] : [];
            foreach ($pageTransactions as $transaction) {
                if (is_array($transaction)) $transactions[] = self::normalizeTransaction($transaction);
            }
            $nextToken = self::nullableString($payload['nextToken'] ?? $data['nextToken'] ?? null);
            $pages++;
            if ($pages >= 50 && $nextToken !== null) {
                throw new RuntimeException('Finances pagination exceeded safety limit.');
            }
        } while ($nextToken !== null && $nextToken !== '');

        return [
            'source' => 'SP_API_FINANCES',
            'financial_truth' => true,
            'request_ids' => $requestIds,
            'transactions' => $transactions,
        ];
    }

    /** @return array{source:string,request_id:string,report_id:string,report_type:string} */
    public function requestReturnsReport(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $fromUtc = $from->setTimezone(new DateTimeZone('UTC'));
        $toUtc = $to->setTimezone(new DateTimeZone('UTC'));
        if ($toUtc <= $fromUtc) throw new InvalidArgumentException('Returns report end must be after start.');

        $body = [
            'reportType' => 'GET_FLAT_FILE_RETURNS_DATA_BY_RETURN_DATE',
            'dataStartTime' => $fromUtc->format('Y-m-d\TH:i:s\Z'),
            'dataEndTime' => $toUtc->format('Y-m-d\TH:i:s\Z'),
            'marketplaceIds' => [(string)$this->client->marketplaceId()],
        ];
        $response = $this->client->request('POST', '/reports/2021-06-30/reports', [], $body);
        self::assertSuccess($response, 'Reports createReport');
        $data = self::responseData($response);
        $payload = self::firstArray($data, ['payload']) ?? $data;
        $reportId = trim((string)($payload['reportId'] ?? ''));
        if ($reportId === '') throw new RuntimeException('Amazon Reports did not return reportId.');

        return [
            'source' => 'SP_API_REPORTS',
            'request_id' => (string)($response['request_id'] ?? ''),
            'report_id' => $reportId,
            'report_type' => $body['reportType'],
        ];
    }

    /**
     * Legacy status/document lookup pair, kept for the existing daemon-level
     * fixed-2-day-window path and its dedicated tests. The scheduled
     * ingestion cycle uses getReport()/downloadReportDocument() below, which
     * add strict processing-status validation and a persisted cursor; this
     * pair stays available (and covered) rather than being deleted mid-merge
     * without a follow-up audit of every caller.
     *
     * @return array{report_id:string,processing_status:string,report_document_id:?string}
     */
    public function getReportStatus(string $reportId): array
    {
        $id = self::requiredId($reportId, 'Report ID');
        $response = $this->client->request('GET', '/reports/2021-06-30/reports/' . rawurlencode($id));
        self::assertSuccess($response, 'Reports getReport');
        $data = self::responseData($response);
        $payload = self::firstArray($data, ['payload']) ?? $data;
        return [
            'report_id' => trim((string)($payload['reportId'] ?? $id)),
            'processing_status' => trim((string)($payload['processingStatus'] ?? '')),
            'report_document_id' => self::nullableString($payload['reportDocumentId'] ?? null),
        ];
    }

    /** @return array{url:string,compression_algorithm:?string} */
    public function getReportDocument(string $reportDocumentId): array
    {
        $id = self::requiredId($reportDocumentId, 'Report document ID');
        $response = $this->client->request('GET', '/reports/2021-06-30/documents/' . rawurlencode($id));
        self::assertSuccess($response, 'Reports getReportDocument');
        $data = self::responseData($response);
        $payload = self::firstArray($data, ['payload']) ?? $data;
        $url = trim((string)($payload['url'] ?? ''));
        if ($url === '') throw new RuntimeException('Amazon Reports document did not return a download URL.');
        return [
            'url' => $url,
            'compression_algorithm' => self::nullableString($payload['compressionAlgorithm'] ?? null),
        ];
    }

    public function downloadReturnsReport(string $reportDocumentId): string
    {
        $document = $this->getReportDocument($reportDocumentId);
        $raw = ($this->documentTransport)($document['url']);
        if (!is_string($raw)) throw new RuntimeException('Amazon Reports document transport returned invalid content.');
        if (strtoupper((string)($document['compression_algorithm'] ?? '')) === 'GZIP') {
            $decoded = @gzdecode($raw);
            if (!is_string($decoded)) throw new RuntimeException('Failed to decompress Amazon report document.');
            $raw = $decoded;
        }
        return $raw;
    }

    /**
     * Report status lookup with strict processing-status validation, used by
     * the cursor-based incremental ingestion cycle (daemon.php runReturnsReport).
     *
     * @return array{source:string,request_id:string,report_id:string,processing_status:string,report_document_id:?string,data_start_time:?string,data_end_time:?string}
     */
    public function getReport(string $reportId): array
    {
        $id = self::requiredId($reportId, 'Amazon report ID');
        $response = $this->client->request('GET', '/reports/2021-06-30/reports/' . rawurlencode($id));
        self::assertSuccess($response, 'Reports getReport');
        $data = self::responseData($response);
        $payload = self::firstArray($data, ['payload']) ?? $data;
        $status = strtoupper(trim((string)($payload['processingStatus'] ?? '')));
        if (!in_array($status, ['IN_QUEUE','IN_PROGRESS','CANCELLED','DONE','FATAL'], true)) {
            throw new RuntimeException('Amazon Reports returned an invalid processing status.');
        }
        return [
            'source'=>'SP_API_REPORTS',
            'request_id'=>(string)($response['request_id'] ?? ''),
            'report_id'=>trim((string)($payload['reportId'] ?? $id)),
            'processing_status'=>$status,
            'report_document_id'=>self::nullableString($payload['reportDocumentId'] ?? null),
            'data_start_time'=>self::nullableString($payload['dataStartTime'] ?? null),
            'data_end_time'=>self::nullableString($payload['dataEndTime'] ?? null),
        ];
    }

    /** @return array{source:string,request_id:string,document_id:string,content:string,content_sha256:string,compression:?string} */
    public function downloadReportDocument(string $documentId): array
    {
        $id = self::requiredId($documentId, 'Amazon report document ID');
        $response = $this->client->request('GET', '/reports/2021-06-30/documents/' . rawurlencode($id));
        self::assertSuccess($response, 'Reports getReportDocument');
        $data = self::responseData($response);
        $payload = self::firstArray($data, ['payload']) ?? $data;
        $url = trim((string)($payload['url'] ?? ''));
        if ($url === '' || !str_starts_with($url, 'https://')) throw new RuntimeException('Amazon Reports document URL is invalid.');
        $content = ($this->documentTransport)($url);
        if (!is_string($content)) throw new RuntimeException('Amazon Reports document transport returned invalid content.');
        $compression = self::nullableString($payload['compressionAlgorithm'] ?? null);
        if (strtoupper((string)$compression) === 'GZIP') {
            $decoded = gzdecode($content);
            if (!is_string($decoded)) throw new RuntimeException('Amazon Reports document could not be decompressed.');
            $content = $decoded;
        }
        return ['source'=>'SP_API_REPORTS','request_id'=>(string)($response['request_id'] ?? ''),'document_id'=>$id,'content'=>$content,'content_sha256'=>hash('sha256',$content),'compression'=>$compression];
    }

    /** @return array<string,mixed> */
    public function consumeTransactionUpdate(array $notification): array
    {
        $type = strtoupper(trim((string)($notification['notificationType'] ?? $notification['NotificationType'] ?? '')));
        if ($type !== 'TRANSACTION_UPDATE') {
            throw new InvalidArgumentException('Expected TRANSACTION_UPDATE notification.');
        }
        $payload = is_array($notification['payload'] ?? null) ? $notification['payload'] : [];
        $transaction = is_array($payload['transaction'] ?? null)
            ? $payload['transaction']
            : (is_array($payload['Transaction'] ?? null) ? $payload['Transaction'] : $payload);
        $normalized = self::normalizeTransaction($transaction);

        return [
            'source' => 'SP_API_TRANSACTION_UPDATE',
            'source_event_id' => trim((string)($notification['notificationId'] ?? $notification['notificationMetadata']['notificationId'] ?? '')),
            'occurred_at' => self::nullableString($notification['eventTime'] ?? $notification['EventTime'] ?? null),
            'transaction_id' => $normalized['transaction_id'],
            'transaction_type' => $normalized['transaction_type'],
            'order_id' => $normalized['order_id'],
            'transaction' => $normalized,
        ];
    }

    /** @return array<string,mixed> */
    private static function normalizeTransaction(array $transaction): array
    {
        $orderId = '';
        $related = $transaction['relatedIdentifiers'] ?? $transaction['relatedIdentifier'] ?? [];
        if (is_array($related)) {
            foreach ($related as $identifier) {
                if (!is_array($identifier)) continue;
                $name = strtoupper(trim((string)($identifier['name'] ?? $identifier['type'] ?? '')));
                if ($name === 'ORDER_ID') {
                    $orderId = trim((string)($identifier['value'] ?? $identifier['id'] ?? ''));
                    break;
                }
            }
        }
        return [
            'transaction_id' => trim((string)($transaction['transactionId'] ?? $transaction['id'] ?? '')),
            'transaction_type' => trim((string)($transaction['transactionType'] ?? $transaction['type'] ?? '')),
            'transaction_status' => self::nullableString($transaction['transactionStatus'] ?? $transaction['status'] ?? null),
            'posted_at' => self::nullableString($transaction['postedDate'] ?? $transaction['postedTime'] ?? null),
            'order_id' => $orderId,
            'total_amount' => self::moneyOnly($transaction['totalAmount'] ?? $transaction['amount'] ?? null),
        ];
    }

    /** @return array{amount:string,currency:string}|null */
    private static function moneyOnly(mixed $value): ?array
    {
        if (!is_array($value)) return null;
        $amount = trim((string)($value['amount'] ?? $value['currencyAmount'] ?? ''));
        $currency = trim((string)($value['currencyCode'] ?? $value['currency'] ?? ''));
        if ($amount === '' && $currency === '') return null;
        return ['amount' => $amount, 'currency' => $currency];
    }

    private static function requiredId(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 191) throw new InvalidArgumentException($label . ' is invalid.');
        return $value;
    }

    /** @param array<string,mixed> $response */
    private static function assertSuccess(array $response, string $operation): void
    {
        $status = (int)($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($operation . ' failed with HTTP ' . $status . '.');
        }
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    private static function responseData(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }

    /** @param array<string,mixed> $data @param list<string> $keys @return array<string,mixed>|null */
    private static function firstArray(array $data, array $keys): ?array
    {
        foreach ($keys as $key) {
            if (is_array($data[$key] ?? null)) return $data[$key];
        }
        return null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function httpDocument(string $url): string
    {
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('Unable to initialize Amazon report download.');
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>120,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        $raw = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($raw)) throw new RuntimeException('Amazon report download failed: ' . $error);
        if ($status < 200 || $status >= 300) throw new RuntimeException('Amazon report download failed with HTTP ' . $status . '.');
        return $raw;
    }
}
