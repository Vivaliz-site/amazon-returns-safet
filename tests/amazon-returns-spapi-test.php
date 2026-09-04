<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/SpApi.php';
require_once __DIR__ . '/../includes/amazon-returns/SpApiEventSink.php';

function spAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function spSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

final class AmazonReturnsFakeClient {
    public array $calls = [];
    public function marketplaceId(): string { return 'A2Q3Y263D00KWC'; }
    public function request(string $method, string $path, array $query = [], ?array $body = null): array {
        $this->calls[] = compact('method', 'path', 'query', 'body');
        if (str_starts_with($path, '/orders/2026-01-01/orders/')) {
            return ['status'=>200,'request_id'=>'req-order-1','data'=>['orderId'=>'702-1234567-1234567','salesChannel'=>['marketplaceId'=>'A2Q3Y263D00KWC'],'programs'=>['DELIVERY_BY_AMAZON'],'orderItems'=>[['orderItemId'=>'item-1','sellerSku'=>'SKU-1','quantityOrdered'=>2]]]];
        }
        if ($path === '/finances/2024-06-19/transactions') {
            return ['status'=>200,'request_id'=>'req-fin-1','data'=>['transactions'=>[['transactionId'=>'txn-1','transactionType'=>'Refund','postedDate'=>'2026-08-01T12:00:00Z','relatedIdentifiers'=>[['name'=>'ORDER_ID','value'=>'702-1234567-1234567']]]]]];
        }
        if ($path === '/reports/2021-06-30/reports' && $method === 'POST') {
            return ['status'=>202,'request_id'=>'req-report-1','data'=>['reportId'=>'RPT-1']];
        }
        if ($path === '/reports/2021-06-30/reports/RPT-1' && $method === 'GET') {
            return ['status'=>200,'request_id'=>'req-report-status-1','data'=>['reportId'=>'RPT-1','processingStatus'=>'DONE','reportDocumentId'=>'DOC-1','dataStartTime'=>'2026-08-01T00:00:00Z','dataEndTime'=>'2026-09-01T00:00:00Z']];
        }
        if ($path === '/reports/2021-06-30/documents/DOC-1' && $method === 'GET') {
            return ['status'=>200,'request_id'=>'req-report-doc-1','data'=>['url'=>'https://signed.example.test/report','compressionAlgorithm'=>'GZIP']];
        }
        throw new RuntimeException('Unexpected fake request: ' . $method . ' ' . $path);
    }
}

$client = new AmazonReturnsFakeClient();
$downloads = [];
$api = new SvAmazonReturnsSpApi($client, static function(string $url) use (&$downloads): string {
    $downloads[] = $url;
    return gzencode("Order ID\tOrder Item ID\n702-1234567-1234567\titem-1\n");
});
$order = $api->syncOrder('702-1234567-1234567');
spSame('/orders/2026-01-01/orders/702-1234567-1234567', $client->calls[0]['path'], 'Orders v2026-01-01 path required.');
spSame('req-order-1', $order['request_id'], 'Order request ID must be retained.');
spSame('702-1234567-1234567', $order['order_id'], 'Order ID must normalize.');
spSame(['DELIVERY_BY_AMAZON'], $order['programs'], 'Order programs must normalize.');

$fin = $api->listTransactions('702-1234567-1234567');
spSame('/finances/2024-06-19/transactions', $client->calls[1]['path'], 'Finances v2024-06-19 path required.');
spSame('ORDER_ID', $client->calls[1]['query']['relatedIdentifierName'] ?? null, 'Finances must filter by ORDER_ID.');
spSame('702-1234567-1234567', $client->calls[1]['query']['relatedIdentifierValue'] ?? null, 'Finances must filter by exact order ID.');
spSame(true, $fin['financial_truth'], 'SP-API Finances must be marked financial truth.');
spSame(['req-fin-1'], $fin['request_ids'], 'Financial request IDs must be retained.');

$report = $api->requestReturnsReport(new DateTimeImmutable('2026-08-01T00:00:00Z'), new DateTimeImmutable('2026-09-01T00:00:00Z'));
spSame('/reports/2021-06-30/reports', $client->calls[2]['path'], 'Reports API v2021-06-30 path required.');
spSame('GET_FLAT_FILE_RETURNS_DATA_BY_RETURN_DATE', $client->calls[2]['body']['reportType'] ?? null, 'Official MFN returns report type required.');
spSame(['A2Q3Y263D00KWC'], $client->calls[2]['body']['marketplaceIds'] ?? null, 'Report must target current marketplace.');
spSame('RPT-1', $report['report_id'], 'Report ID must normalize.');
spSame('req-report-1', $report['request_id'], 'Report request ID must be retained.');

$reportStatus = $api->getReportStatus('RPT-1');
spSame('/reports/2021-06-30/reports/RPT-1', $client->calls[3]['path'], 'Report status must use Reports API v2021-06-30.');
spSame('DONE', $reportStatus['processing_status'], 'Report processing status must normalize.');
spSame('DOC-1', $reportStatus['report_document_id'], 'Report document ID must normalize.');

$reportDocument = $api->getReportDocument('DOC-1');
spSame('/reports/2021-06-30/documents/DOC-1', $client->calls[4]['path'], 'Report document lookup must use Reports API v2021-06-30.');
spSame('GZIP', $reportDocument['compression_algorithm'], 'Report document compression must normalize.');

$gzippedFixture = (string)gzencode("Order ID\tOrder Item ID\n701-1-1\t100-1\n");
$downloaderCalls = [];
$apiWithDownloader = new SvAmazonReturnsSpApi($client, function (string $url) use (&$downloaderCalls, $gzippedFixture): string {
    $downloaderCalls[] = $url;
    return $gzippedFixture;
});
$downloaded = $apiWithDownloader->downloadReturnsReport('DOC-1');
spSame(['https://signed.example.test/report'], $downloaderCalls, 'Legacy report document download must fetch the presigned URL.');
spSame("Order ID\tOrder Item ID\n701-1-1\t100-1\n", $downloaded, 'GZIP report documents must be decompressed transparently (legacy downloadReturnsReport path).');

$status = $api->getReport('RPT-1');
spSame('DONE', $status['processing_status'], 'Report lifecycle must expose terminal DONE status.');
spSame('DOC-1', $status['report_document_id'], 'Completed report must expose its document ID.');
$document = $api->downloadReportDocument('DOC-1');
spSame("Order ID\tOrder Item ID\n702-1234567-1234567\titem-1\n", $document['content'], 'GZIP report document must download and decompress in memory (cursor-based getReport/downloadReportDocument path).');
spSame(['https://signed.example.test/report'], $downloads, 'Signed report URL must be used only by the document transport.');
spAssert(!array_key_exists('url', $document), 'Signed report URL must not escape the transport boundary.');

spSame('DELIVERY_BY_AMAZON', SvAmazonSpApiEventSink::programFromOrder($order), 'DBA program must normalize deterministically.');
spSame('STANDARD', SvAmazonSpApiEventSink::programFromOrder(['programs'=>[],'fulfillment'=>['channel'=>'MERCHANT']]), 'Merchant fulfillment (fixture field name) maps to STANDARD.');
spSame('FBA', SvAmazonSpApiEventSink::programFromOrder(['programs'=>[],'fulfillment'=>['fulfilledBy'=>'AMAZON']]), 'Orders v2026 AMAZON fulfillment (standard FBA) maps to FBA, distinct from seller-fulfilled STANDARD.');
spSame('STANDARD', SvAmazonSpApiEventSink::programFromOrder(['programs'=>[],'fulfillment'=>['fulfilledBy'=>'MERCHANT']]), 'Orders v2026 MERCHANT fulfillment maps to STANDARD.');
spSame('DELIVERY_BY_AMAZON', SvAmazonSpApiEventSink::programFromOrder(['programs'=>['DELIVERY_BY_AMAZON'],'fulfillment'=>['fulfilledBy'=>'AMAZON']]), 'Explicit DBA program takes precedence over fulfilledBy.');
spSame('UNKNOWN', SvAmazonSpApiEventSink::programFromOrder(['programs'=>[],'fulfillment'=>[]]), 'Missing fulfillment data must stay UNKNOWN rather than guessing.');
$refundFact = SvAmazonSpApiEventSink::refundObservation([[
    'transaction_id'=>'txn-refund','transaction_type'=>'Refund','transaction_status'=>'RELEASED',
    'posted_at'=>'2026-08-01T12:00:00Z','total_amount'=>['amount'=>'-128.25','currency'=>'BRL'],
]]);
spSame('128.25', $refundFact['refund_amount'], 'Released negative refund is official seller debit evidence.');
spSame('2026-08-01 12:00:00', $refundFact['seller_debit_at'], 'Seller debit date must normalize to UTC.');
spSame('UNKNOWN', $refundFact['refund_initiator'], 'SP-API transaction alone must not invent refund initiator.');
$duplicateLifecycle = SvAmazonSpApiEventSink::refundObservation([
    ['transaction_id'=>'refund-deferred','transaction_type'=>'Refund','transaction_status'=>'DEFERRED_RELEASED','posted_at'=>'2026-06-21T21:20:33Z','total_amount'=>['amount'=>'-266.70','currency'=>'BRL']],
    ['transaction_id'=>'refund-released','transaction_type'=>'Refund','transaction_status'=>'RELEASED','posted_at'=>'2026-06-27T18:59:09Z','total_amount'=>['amount'=>'-266.70','currency'=>'BRL']],
]);
spSame('266.70', $duplicateLifecycle['refund_amount'], 'Deferred->released lifecycle representations of one refund must count once economically.');
spSame('2026-06-21 21:20:33', $duplicateLifecycle['seller_debit_at'], 'Economic refund keeps earliest seller exposure timestamp.');
spSame(['refund-deferred','refund-released'], $duplicateLifecycle['transaction_ids'], 'Both lifecycle transaction IDs remain evidence even when economic amount is deduplicated.');
spSame(null, SvAmazonSpApiEventSink::refundObservation([[
    'transaction_id'=>'txn-pending','transaction_type'=>'Refund','transaction_status'=>'DEFERRED',
    'posted_at'=>'2026-08-01T12:00:00Z','total_amount'=>['amount'=>'-10.00','currency'=>'BRL'],
]]), 'Deferred refund must not be treated as confirmed debit.');

$notification = $api->consumeTransactionUpdate([
    'notificationType' => 'TRANSACTION_UPDATE',
    'notificationId' => 'notif-1',
    'eventTime' => '2026-09-01T12:00:00Z',
    'payload' => ['transaction' => ['transactionId'=>'txn-9','transactionType'=>'Refund','relatedIdentifiers'=>[['name'=>'ORDER_ID','value'=>'702-1234567-1234567']]]],
]);
spSame('SP_API_TRANSACTION_UPDATE', $notification['source'], 'Notification source must normalize.');
spSame('notif-1', $notification['source_event_id'], 'Notification ID must be retained.');
spSame('702-1234567-1234567', $notification['order_id'], 'ORDER_ID must be extracted from related identifiers.');
spSame('txn-9', $notification['transaction_id'], 'Transaction ID must normalize.');

$methods = array_map(static fn(ReflectionMethod $m): string => strtolower($m->getName()), (new ReflectionClass(SvAmazonReturnsSpApi::class))->getMethods(ReflectionMethod::IS_PUBLIC));
foreach ($methods as $method) {
    spAssert(!str_contains($method, 'safe') && !str_contains($method, 'claim') && !str_contains($method, 'appeal'), 'SP-API facade must not expose invented SAFE-T/claim/appeal endpoints.');
}
$serialized = json_encode([$order,$fin,$report,$notification,$reportStatus,$reportDocument], JSON_THROW_ON_ERROR);
foreach (['access_token','refresh_token','client_secret','x-amz-access-token'] as $secretKey) {
    spAssert(!str_contains(strtolower($serialized), $secretKey), 'Normalized outputs must not expose secrets: ' . $secretKey);
}

// persistReturnsReportRow: matches an existing case by (order_id, order_item_id)
// and only appends an event when the initiator is confidently derived.
final class SpApiReturnsReportMemoryPdo extends PDO {
    /** @var array<int,array{amazon_order_id:string,amazon_order_item_id:string}> */
    public array $cases = [];
    /** @var array<string,true> */
    public array $eventIdempotencyKeys = [];
    public int $nextEventId = 1;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new SpApiReturnsReportMemoryStatement($this, $query); }
    public function lastInsertId(?string $name = null): string|false { return (string)($this->nextEventId - 1); }
}
final class SpApiReturnsReportMemoryStatement extends PDOStatement {
    private array $result = [];
    public function __construct(private SpApiReturnsReportMemoryPdo $db, private string $query) {}
    public function execute(?array $params = null): bool {
        $params ??= [];
        $sql = strtoupper($this->query);
        if (str_contains($sql, 'SELECT ID FROM AMAZON_RETURN_CASES')) {
            $this->result = [];
            foreach ($this->db->cases as $id => $case) {
                if ($case['amazon_order_id'] !== $params[':order_id']) continue;
                if (isset($params[':item_id']) && $case['amazon_order_item_id'] !== $params[':item_id']) continue;
                $this->result[] = $id;
            }
            return true;
        }
        if (str_starts_with(ltrim($sql), 'INSERT INTO `AMAZON_RETURN_EVENTS`')) {
            $key = $params[':idempotency_key'];
            if (isset($this->db->eventIdempotencyKeys[$key])) throw new PDOException('Duplicate entry', 23000);
            $this->db->eventIdempotencyKeys[$key] = true;
            $this->db->nextEventId++;
            return true;
        }
        if (str_contains($sql, 'SELECT `ID` FROM `AMAZON_RETURN_EVENTS`') && str_contains($sql, 'IDEMPOTENCY_KEY')) {
            $key = $params[':idempotency_key'];
            $this->result = isset($this->db->eventIdempotencyKeys[$key]) ? [1] : [];
            return true;
        }
        throw new LogicException('Unexpected SQL in persistReturnsReportRow test: ' . $this->query);
    }
    public function fetchColumn(int $column = 0): mixed {
        return $this->result === [] ? false : $this->result[0];
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return $this->result === [] ? false : ['id' => $this->result[0]];
    }
}

$reportDb = new SpApiReturnsReportMemoryPdo();
$reportDb->cases[501] = ['amazon_order_id'=>'701-9999999-1111111','amazon_order_item_id'=>'item-a'];

$autoScanRow = ['Order ID'=>'701-9999999-1111111','Order Item ID'=>'item-a','A-to-Z Claim'=>'N','Resolution'=>'RefundAtFirstScan'];
$outcome = SvAmazonSpApiEventSink::persistReturnsReportRow($reportDb, $autoScanRow, 'RPT-1');
spSame(true, $outcome['matched'], 'A matching order+item must be reported as matched.');
spSame(true, $outcome['applied'], 'A confidently classified row must be applied.');
spSame(1, count($reportDb->eventIdempotencyKeys), 'Matched row must append exactly one event.');

$again = SvAmazonSpApiEventSink::persistReturnsReportRow($reportDb, $autoScanRow, 'RPT-1');
spSame(true, $again['matched'], 'Re-ingesting the same row must still report matched.');
spSame(1, count($reportDb->eventIdempotencyKeys), 'Re-ingesting the same row must be idempotent (no duplicate event).');

$unmatchedRow = ['Order ID'=>'701-0000000-0000000','Order Item ID'=>'item-x','A-to-Z Claim'=>'N','Resolution'=>'RefundAtFirstScan'];
$unmatchedOutcome = SvAmazonSpApiEventSink::persistReturnsReportRow($reportDb, $unmatchedRow, 'RPT-1');
spSame(false, $unmatchedOutcome['matched'], 'A row for an unknown order must not match any case.');
spSame(1, count($reportDb->eventIdempotencyKeys), 'An unmatched row must not append an event.');

$ambiguousRow = ['Order ID'=>'701-9999999-1111111','Order Item ID'=>'item-a','A-to-Z Claim'=>'N','Resolution'=>'ManualRefund'];
$ambiguousOutcome = SvAmazonSpApiEventSink::persistReturnsReportRow($reportDb, $ambiguousRow, 'RPT-1');
spSame(false, $ambiguousOutcome['applied'], 'An ambiguous (unclassifiable) initiator must not be applied, even for a matching case.');
spSame(1, count($reportDb->eventIdempotencyKeys), 'An ambiguous row must not append an event.');

echo "amazon-returns-spapi-test: OK\n";
