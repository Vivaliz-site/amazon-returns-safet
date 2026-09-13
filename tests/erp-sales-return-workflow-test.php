<?php
declare(strict_types=1);

$servicePath=__DIR__.'/../includes/amazon-returns/ErpSalesReturnService.php';
$gatewayPath=__DIR__.'/../includes/amazon-returns/ErpSalesReturnGateway.php';
$repositoryPath=__DIR__.'/../includes/amazon-returns/ErpSalesReturnRepository.php';
if(!is_file($servicePath) || !is_file($gatewayPath))throw new RuntimeException('ERP sales return workflow implementation is missing.');
require_once $repositoryPath;
require_once $gatewayPath;
require_once $servicePath;

function erpWorkflowAssert(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}
function erpWorkflowSame(mixed $expected,mixed $actual,string $message): void {
    if($expected!==$actual)throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true));
}

final class FakeErpSalesReturnStore implements SvAmazonErpSalesReturnStore
{
    /** @var array<string,array<string,mixed>> */
    public array $rows=[];

    public function findByOrder(string $orderId): ?array { return $this->rows[$orderId]??null; }
    public function ensureWorkflow(array $data): array {
        $orderId=(string)$data['amazon_order_id'];
        $this->rows[$orderId]=$this->rows[$orderId]??[
            'amazon_order_id'=>$orderId,
            'status'=>'PENDING',
            'original_invoice_id'=>$data['original_invoice_id']??null,
            'original_invoice_number'=>$data['original_invoice_number']??null,
            'original_invoice_key'=>$data['original_invoice_key']??null,
            'erp_sales_return_id'=>null,
            'return_invoice_id'=>null,
            'last_error_code'=>null,
            'last_error_message'=>null,
        ];
        return $this->rows[$orderId];
    }
    public function markReady(string $orderId): array { $this->rows[$orderId]['status']='READY_TO_CREATE'; return $this->rows[$orderId]; }
    public function markReturnCreated(string $orderId,string $erpSalesReturnId): array { $this->rows[$orderId]['status']='RETURN_CREATED_WAITING_INVOICE';$this->rows[$orderId]['erp_sales_return_id']=$erpSalesReturnId;return $this->rows[$orderId]; }
    public function linkReturnInvoice(string $orderId,array $invoice): array { $this->rows[$orderId]['status']='RETURN_INVOICE_EXISTS';$this->rows[$orderId]['return_invoice_id']=$invoice['invoice_id']??null;$this->rows[$orderId]['return_invoice_number']=$invoice['invoice_number']??null;return $this->rows[$orderId]; }
    public function markBlocked(string $orderId,string $code,string $message): array { $this->rows[$orderId]['status']='BLOCKED';$this->rows[$orderId]['last_error_code']=$code;$this->rows[$orderId]['last_error_message']=$message;return $this->rows[$orderId]; }
}

final class FakeErpSalesReturnGateway implements SvAmazonErpSalesReturnGateway
{
    public int $createCalls=0;
    public int $readBackCalls=0;
    /** @param array<string,mixed> $createResult @param array<string,mixed>|null $readBack */
    public function __construct(private array $createResult=['ok'=>true,'id'=>'RET-1'],private ?array $readBack=['id'=>'RET-1']) {}
    public function create(array $command): array { $this->createCalls++;return $this->createResult; }
    public function readBack(string $erpSalesReturnId,string $amazonOrderId): ?array { $this->readBackCalls++;return $this->readBack; }
}

function workflowCases(string $orderId): array {
    return [[
        'id'=>11,'amazon_order_id'=>$orderId,'amazon_order_item_id'=>'item-1','sku'=>'SKU-1',
        'quantity_ordered'=>2,'quantity_refunded'=>1,'refund_at'=>'2026-09-12 12:00:00',
    ]];
}
function workflowSale(string $orderId): array {
    return ['invoice_id'=>'500','invoice_number'=>'1001','access_key'=>'SALEKEY1001','order_id'=>$orderId];
}
/** @param list<array<string,mixed>|null> $queue */
function makeWorkflowService(FakeErpSalesReturnStore $store,FakeErpSalesReturnGateway $gateway,array &$queue,bool $writeEnabled,int &$lookupCalls): SvAmazonErpSalesReturnService {
    $cases=static fn(string $orderId): array=>workflowCases($orderId);
    $sale=static fn(string $orderId): ?array=>workflowSale($orderId);
    $returnLookup=static function(string $orderId) use (&$queue,&$lookupCalls): ?array {
        $lookupCalls++;
        return array_shift($queue);
    };
    return new SvAmazonErpSalesReturnService($store,$gateway,$cases,$sale,$returnLookup,$writeEnabled);
}

$order='702-1111111-2222222';
$existingInvoice=['invoice_id'=>'901','invoice_number'=>'301','access_key'=>'RETKEY301','status'=>'6','issued_at'=>'2026-09-12T13:00:00-03:00','order_id'=>$order];

// Existing return NF must suppress any ERP sales-return write.
$store=new FakeErpSalesReturnStore();$gateway=new FakeErpSalesReturnGateway();$queue=[$existingInvoice];$lookupCalls=0;
$result=makeWorkflowService($store,$gateway,$queue,true,$lookupCalls)->reconcileOrder($order);
erpWorkflowSame('RETURN_INVOICE_EXISTS',$result['status']??null,'Existing return NF must win immediately.');
erpWorkflowSame(0,$gateway->createCalls,'Existing return NF must suppress ERP sales-return creation.');
erpWorkflowSame(1,$lookupCalls,'Existing return NF should stop after the first preflight.');

// Gate-off mode must persist readiness without writing.
$store=new FakeErpSalesReturnStore();$gateway=new FakeErpSalesReturnGateway();$queue=[null];$lookupCalls=0;
$result=makeWorkflowService($store,$gateway,$queue,false,$lookupCalls)->reconcileOrder($order);
erpWorkflowSame('READY_TO_CREATE',$result['status']??null,'Gate-off mode must mark the workflow ready.');
erpWorkflowSame(0,$gateway->createCalls,'Gate-off mode must never write to ERP.');

// Race protection: a return NF appearing after first lookup must still suppress write.
$store=new FakeErpSalesReturnStore();$gateway=new FakeErpSalesReturnGateway();$queue=[null,$existingInvoice];$lookupCalls=0;
$result=makeWorkflowService($store,$gateway,$queue,true,$lookupCalls)->reconcileOrder($order);
erpWorkflowSame('RETURN_INVOICE_EXISTS',$result['status']??null,'Second preflight must link a newly discovered return NF.');
erpWorkflowSame(0,$gateway->createCalls,'Second preflight must suppress ERP write.');
erpWorkflowSame(2,$lookupCalls,'Enabled write must run a second return-NF preflight.');

// Successful write requires readback before marking the return created.
$store=new FakeErpSalesReturnStore();$gateway=new FakeErpSalesReturnGateway(['ok'=>true,'id'=>'RET-77'],['id'=>'RET-77','order_id'=>$order]);$queue=[null,null,null];$lookupCalls=0;
$service=makeWorkflowService($store,$gateway,$queue,true,$lookupCalls);
$result=$service->reconcileOrder($order);
erpWorkflowSame('RETURN_CREATED_WAITING_INVOICE',$result['status']??null,'Verified ERP readback must mark the sales return created.');
erpWorkflowSame(1,$gateway->createCalls,'Exactly one ERP sales-return write is allowed.');
erpWorkflowSame(1,$gateway->readBackCalls,'Successful create must be read back before persistence.');
$result=$service->reconcileOrder($order);
erpWorkflowSame('RETURN_CREATED_WAITING_INVOICE',$result['status']??null,'Repeated reconciliation must keep the created state while NF is absent.');
erpWorkflowSame(1,$gateway->createCalls,'Repeated reconciliation must not duplicate the ERP sales return.');

// Unverified production gateway must block rather than substitute another ERP write.
$store=new FakeErpSalesReturnStore();
$blockedGateway=new SvAmazonUnverifiedErpSalesReturnGateway();$queue=[null,null];$lookupCalls=0;
$result=(new SvAmazonErpSalesReturnService(
    $store,$blockedGateway,
    static fn(string $id): array=>workflowCases($id),
    static fn(string $id): ?array=>workflowSale($id),
    static function(string $id) use (&$queue,&$lookupCalls): ?array {$lookupCalls++;return array_shift($queue);},
    true
))->reconcileOrder($order);
erpWorkflowSame('BLOCKED',$result['status']??null,'Unverified ERP writer must block the workflow.');
erpWorkflowSame('ERP_SALES_RETURN_WRITE_NOT_VERIFIED',$result['last_error_code']??null,'Block reason must identify the unverified ERP operation.');

echo "erp-sales-return-workflow-test: OK\n";
