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
    spAssert(
        preg_match('/submit|appeal|create.*claim|update.*claim|reply/',$method)!==1,
        'SP-API facade must not expose invented SAFE-T claim or appeal writes.'
    );
}
$serialized = json_encode([$order,$fin,$report,$notification,$reportStatus,$reportDocument], JSON_THROW_ON_ERROR);
foreach (['access_token','refresh_token','client_secret','x-amz-access-token'] as $secretKey) {
    spAssert(!str_contains(strtolower($serialized), $secretKey), 'Normalized outputs must not expose secrets: ' . $secretKey);
}

// persistReturnsReportRow: matches only an owned case and remains idempotent.
final class SpApiReturnsReportMemoryPdo extends PDO {
    /** @var array<int,array<string,mixed>> */
    public array $cases=[];
    /** @var array<string,int> */
    public array $eventIds=[];
    /** @var list<array<string,mixed>> */
    public array $eventRows=[];
    public int $nextEventId=1;
    public int $lastId=0;
    public function __construct() {}
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        return new SpApiReturnsReportMemoryStatement($this,$query);
    }
    public function lastInsertId(?string $name=null):string|false { return (string)$this->lastId; }
}
final class SpApiReturnsReportMemoryStatement extends PDOStatement {
    private array $rows=[];
    public function __construct(private SpApiReturnsReportMemoryPdo $db,private string $query) {}
    public function execute(?array $params=null):bool {
        $params??=[];
        $sql=strtoupper($this->query);
        $this->rows=[];
        if(str_contains($sql,'FROM AMAZON_RETURN_CASES')){
            foreach($this->db->cases as $id=>$case){
                if((int)($case['tenant_id']??0)!==(int)($params[':tenant_id']??0))continue;
                if((int)($case['amazon_connection_id']??0)!==(int)($params[':amazon_connection_id']??0))continue;
                if(isset($params[':case_id']) && $id!==(int)$params[':case_id'])continue;
                if(isset($params[':id']) && $id!==(int)$params[':id'])continue;
                if(isset($params[':order_id']) && $case['amazon_order_id']!==$params[':order_id'])continue;
                if(isset($params[':item_id']) && $case['amazon_order_item_id']!==$params[':item_id'])continue;
                $this->rows[]=$case+['id'=>$id];
            }
            return true;
        }
        if(str_starts_with(ltrim($sql),'INSERT INTO AMAZON_RETURN_EVENTS')){
            $key=(string)$params[':idempotency_key'];
            if(isset($this->db->eventIds[$key]))throw new PDOException('Duplicate entry',23000);
            $id=$this->db->nextEventId++;
            $this->db->eventIds[$key]=$id;
            $this->db->eventRows[]=$params;
            $this->db->lastId=$id;
            return true;
        }
        if(str_contains($sql,'FROM AMAZON_RETURN_EVENTS') && str_contains($sql,'IDEMPOTENCY_KEY')){
            $key=(string)($params[':idempotency_key']??$params[':key']??'');
            if(isset($this->db->eventIds[$key]))$this->rows[]=['id'=>$this->db->eventIds[$key]];
            return true;
        }
        throw new LogicException('Unexpected scoped report SQL: '.$this->query);
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed {
        return array_shift($this->rows)??false;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array { return $this->rows; }
    public function fetchColumn(int $column=0):mixed {
        $row=array_shift($this->rows);
        return is_array($row)?(array_values($row)[$column]??false):false;
    }
}

$reportDb=new SpApiReturnsReportMemoryPdo();
$reportDb->cases[501]=[
    'tenant_id'=>1,'amazon_connection_id'=>10,
    'amazon_order_id'=>'701-9999999-1111111','amazon_order_item_id'=>'item-a',
    'safe_t_id'=>'98143-99485-9285859',
];
$reportDb->cases[502]=[
    'tenant_id'=>2,'amazon_connection_id'=>20,
    'amazon_order_id'=>'701-9999999-1111111','amazon_order_item_id'=>'item-a',
    'safe_t_id'=>'98143-99485-9285859',
];
$reportPersistence=SvAmazonTenantPersistence::create(
    $reportDb,new SvAmazonTenantContext(1,10)
);
$autoScanRow=[
    'Order ID'=>'701-9999999-1111111','Order Item ID'=>'item-a',
    'A-to-Z Claim'=>'N','Resolution'=>'RefundAtFirstScan',
    'Invoice number'=>'123456','Amazon RMA ID'=>'AMZ-RMA-501','Merchant RMA ID'=>'MER-RMA-501',
    'Tracking ID'=>'TBR420500501','Return carrier'=>'Amazon Logistics BR','Return delivery date'=>'09-Sep-2026',
];
$outcome=SvAmazonSpApiEventSink::persistReturnsReportRow(
    $reportPersistence,$autoScanRow,'RPT-1'
);
spSame(true,$outcome['matched'],'Owned order+item must match.');
spSame(true,$outcome['applied'],'Confidently classified row must apply.');
spSame(1,count($reportDb->eventIds),'Matched row appends one scoped event.');
$reportPayload=json_decode((string)($reportDb->eventRows[0][':payload_json']??''),true,512,JSON_THROW_ON_ERROR);
spSame('123456',$reportPayload['invoice_number']??null,'Official return invoice must be preserved for search.');
spSame('AMZ-RMA-501',$reportPayload['amazon_rma_id']??null,'Amazon RMA must be preserved for search.');
spSame('TBR420500501',$reportPayload['tracking_id']??null,'Official return tracking must be preserved.');
spSame('09-Sep-2026',$reportPayload['return_delivery_date']??null,'Official return delivery date must be preserved.');
$again=SvAmazonSpApiEventSink::persistReturnsReportRow(
    $reportPersistence,$autoScanRow,'RPT-1'
);
spSame(true,$again['matched'],'Repeated owned row remains matched.');
spSame(1,count($reportDb->eventIds),'Repeated row remains idempotent.');
$unmatched=[
    'Order ID'=>'701-0000000-0000000','Order Item ID'=>'item-x',
    'A-to-Z Claim'=>'N','Resolution'=>'RefundAtFirstScan',
];
spSame(false,SvAmazonSpApiEventSink::persistReturnsReportRow(
    $reportPersistence,$unmatched,'RPT-1'
)['matched'],'Unknown order must not match.');
$ambiguous=[
    'Order ID'=>'701-9999999-1111111','Order Item ID'=>'item-a',
    'A-to-Z Claim'=>'N','Resolution'=>'ManualRefund',
];
spSame(false,SvAmazonSpApiEventSink::persistReturnsReportRow(
    $reportPersistence,$ambiguous,'RPT-1'
)['applied'],'Ambiguous initiator must not apply.');
spSame(1,count($reportDb->eventIds),'Ambiguous row cannot append an event.');

final class SafeTFinanceFakeClient {
    public array $calls=[];
    public function marketplaceId():string{return 'A2Q3Y263D00KWC';}
    public function request(string $method,string $path,array $query=[],?array $body=null):array {
        $this->calls[]=compact('method','path','query','body');
        if(($query['NextToken']??null)==='page-2'){
            return ['status'=>200,'request_id'=>'req-safet-2','data'=>['payload'=>[
                'FinancialEvents'=>['SAFETReimbursementEventList'=>[]],
            ]]];
        }
        return ['status'=>200,'request_id'=>'req-safet-1','data'=>['payload'=>[
            'FinancialEvents'=>['SAFETReimbursementEventList'=>[[
                'PostedDate'=>'2026-08-05T14:30:00Z',
                'SAFETClaimId'=>'98143-99485-9285859',
                'ReimbursedAmount'=>['CurrencyCode'=>'BRL','CurrencyAmount'=>'68.29'],
                'ReasonCode'=>'SAFE_T_REIMBURSEMENT',
                'SAFETReimbursementItemList'=>[['orderItemId'=>'item-a','quantity'=>1]],
            ]]],
            'NextToken'=>'page-2',
        ]]];
    }
}
$safeTClient=new SafeTFinanceFakeClient();
$safeTApi=new SvAmazonReturnsSpApi($safeTClient);
$safeTResult=$safeTApi->listSafeTReimbursements('701-9999999-1111111');
spSame('/finances/v0/orders/701-9999999-1111111/financialEvents',$safeTClient->calls[0]['path'],'SAFE-T reimbursement read must use the documented Finances v0 order endpoint.');
spSame('page-2',$safeTClient->calls[1]['query']['NextToken']??null,'Finances v0 pagination must use NextToken.');
spSame(['req-safet-1','req-safet-2'],$safeTResult['request_ids'],'Finances v0 request IDs must be retained.');
spSame(2,count($safeTResult['response_sha256']??[]),'Each Finances v0 page must retain a response evidence hash.');
spSame('98143-99485-9285859',$safeTResult['events'][0]['safe_t_claim_id']??null,'SAFE-T claim ID must normalize from Finances v0.');
spSame('68.29',$safeTResult['events'][0]['reimbursed_amount']['amount']??null,'SAFE-T reimbursement amount must normalize exactly.');
spSame('req-safet-1',$safeTResult['events'][0]['request_id']??null,'SAFE-T reimbursement must retain its page request ID.');
spSame($safeTResult['response_sha256'][0]??null,$safeTResult['events'][0]['response_sha256']??null,'SAFE-T reimbursement must retain its exact response-page evidence hash.');

$normalizedEvent=[
    'posted_at'=>'2026-08-05T14:30:00Z',
    'safe_t_claim_id'=>'98143-99485-9285859',
    'reimbursed_amount'=>['amount'=>'68.29','currency'=>'BRL'],
    'reason_code'=>'SAFE_T_REIMBURSEMENT',
    'items'=>[['orderItemId'=>'item-a','quantity'=>1]],
];
$persisted=SvAmazonSpApiEventSink::persistSafeTReimbursements(
    $reportPersistence,'701-9999999-1111111',[$normalizedEvent],['req-safet-1'],[str_repeat('a',64)]
);
spSame(['matched'=>1,'persisted'=>1,'unmatched'=>0],$persisted,'Owned SAFE-T reimbursement must persist once.');
$stored=$reportDb->eventRows[array_key_last($reportDb->eventRows)];
spSame(1,$stored[':tenant_id']??null,'SAFE-T reimbursement event must inherit tenant ownership.');
spSame(10,$stored[':amazon_connection_id']??null,'SAFE-T reimbursement event must inherit connection ownership.');
spSame('SAFE_T_REIMBURSEMENT_OBSERVED',$stored[':event_type']??null,'SAFE-T reimbursement must use an immutable financial observation event.');
$again=SvAmazonSpApiEventSink::persistSafeTReimbursements(
    $reportPersistence,'701-9999999-1111111',[$normalizedEvent],['req-safet-1'],[str_repeat('a',64)]
);
spSame(1,count(array_filter($reportDb->eventRows,static fn(array $row):bool=>($row[':event_type']??'')==='SAFE_T_REIMBURSEMENT_OBSERVED')),'Repeated SAFE-T reimbursement must be idempotent.');
spSame(['matched'=>0,'persisted'=>0,'unmatched'=>0],SvAmazonSpApiEventSink::persistSafeTReimbursements(
    $reportPersistence,'701-9999999-1111111',[],['req-empty'],[str_repeat('b',64)]
),'Empty SAFE-T reimbursement list is a successful no-op, never a denial.');

echo "amazon-returns-spapi-test: OK\n";
