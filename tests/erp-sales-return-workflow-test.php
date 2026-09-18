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
        foreach(['original_invoice_id','original_invoice_number','original_invoice_key'] as $field){
            if(($this->rows[$orderId][$field]??null)===null && array_key_exists($field,$data))$this->rows[$orderId][$field]=$data[$field];
        }
        return $this->rows[$orderId];
    }
    public function markReady(string $orderId): array { $this->rows[$orderId]['status']='READY_TO_CREATE'; return $this->rows[$orderId]; }
    public function markReturnCreated(string $orderId,string $erpSalesReturnId): array { $this->rows[$orderId]['status']='RETURN_CREATED_WAITING_INVOICE';$this->rows[$orderId]['erp_sales_return_id']=$erpSalesReturnId;return $this->rows[$orderId]; }
    public function linkReturnInvoice(string $orderId,array $invoice): array { $this->rows[$orderId]['status']='RETURN_INVOICE_EXISTS';$this->rows[$orderId]['return_invoice_id']=$invoice['invoice_id']??null;$this->rows[$orderId]['return_invoice_number']=$invoice['invoice_number']??null;return $this->rows[$orderId]; }
    public function markBlocked(string $orderId,string $code,string $message): array { $this->rows[$orderId]['status']='BLOCKED';$this->rows[$orderId]['last_error_code']=$code;$this->rows[$orderId]['last_error_message']=$message;return $this->rows[$orderId]; }
    public function recordReconciledQuantity(string $orderId,int $quantity): array { $this->rows[$orderId]['reconciled_quantity_refunded']=$quantity;return $this->rows[$orderId]; }
}

final class FakeErpSalesReturnGateway implements SvAmazonErpSalesReturnGateway
{
    public int $createCalls=0;
    public int $readBackCalls=0;
    public int $probeExistingCalls=0;
    public ?array $lastCommand=null;
    /** @param array<string,mixed> $createResult @param array<string,mixed>|null $readBack */
    public function __construct(
        private array $createResult=['ok'=>true,'id'=>'RET-1'],
        private ?array $readBack=['id'=>'RET-1'],
        private ?string $existingSalesReturnId=null
    ) {}
    public function create(array $command): array { $this->createCalls++;$this->lastCommand=$command;return $this->createResult; }
    public function readBack(string $erpSalesReturnId,string $amazonOrderId): ?array { $this->readBackCalls++;return $this->readBack; }
    public function probeExisting(string $originalInvoiceId,string $originalInvoiceNumber=''): ?string { $this->probeExistingCalls++;return $this->existingSalesReturnId; }
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

// Existing return NF must still win when the original ERP sale cannot be resolved.
$store=new FakeErpSalesReturnStore();$gateway=new FakeErpSalesReturnGateway();$lookupCalls=0;
$result=(new SvAmazonErpSalesReturnService(
    $store,$gateway,
    static fn(string $id): array=>workflowCases($id),
    static fn(string $id): ?array=>null,
    static function(string $id) use ($existingInvoice,&$lookupCalls): ?array {$lookupCalls++;return $existingInvoice;},
    true
))->reconcileOrder($order);
erpWorkflowSame('RETURN_INVOICE_EXISTS',$result['status']??null,'Existing return NF must be linked even when original ERP sale lookup is unavailable.');
erpWorkflowSame('901',$result['return_invoice_id']??null,'Existing return NF must remain linked to the workflow.');
erpWorkflowSame(0,$gateway->createCalls,'Missing original sale must never cause a duplicate write when return NF already exists.');
erpWorkflowSame(1,$lookupCalls,'Existing return NF must be checked before requiring the original sale.');

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
erpWorkflowSame('2026-09-12 12:00:00',$gateway->lastCommand['refund_at']??null,'ERP sales return must use the actual Amazon refund timestamp.');
$result=$service->reconcileOrder($order);
erpWorkflowSame('RETURN_CREATED_WAITING_INVOICE',$result['status']??null,'Repeated reconciliation must keep the created state while NF is absent.');
erpWorkflowSame(1,$gateway->createCalls,'Repeated reconciliation must not duplicate the ERP sales return.');


// Safe pre-write blockers must resume after the browser writer becomes available.
$store=new FakeErpSalesReturnStore();
$store->rows[$order]=['amazon_order_id'=>$order,'status'=>'BLOCKED','original_invoice_id'=>'500','original_invoice_number'=>'1001','original_invoice_key'=>'SALEKEY1001','erp_sales_return_id'=>null,'return_invoice_id'=>null,'last_error_code'=>'ERP_SALES_RETURN_WRITE_NOT_VERIFIED','last_error_message'=>'old writer unavailable'];
$gateway=new FakeErpSalesReturnGateway(['ok'=>true,'id'=>'RET-88'],['id'=>'RET-88','order_id'=>$order]);$queue=[null,null];$lookupCalls=0;
$result=makeWorkflowService($store,$gateway,$queue,true,$lookupCalls)->reconcileOrder($order);
erpWorkflowSame('RETURN_CREATED_WAITING_INVOICE',$result['status']??null,'A safe pre-write block must resume after the verified writer is installed.');
erpWorkflowSame(1,$gateway->createCalls,'Resumed pre-write block must perform exactly one guarded write.');

// A sale that was temporarily missing must resume safely once the original sale becomes available.
$store=new FakeErpSalesReturnStore();
$store->rows[$order]=['amazon_order_id'=>$order,'status'=>'BLOCKED','original_invoice_id'=>null,'original_invoice_number'=>null,'original_invoice_key'=>null,'erp_sales_return_id'=>null,'return_invoice_id'=>null,'last_error_code'=>'ERP_ORIGINAL_SALE_NOT_FOUND','last_error_message'=>'sale not found yet'];
$gateway=new FakeErpSalesReturnGateway();$queue=[null];$lookupCalls=0;
$result=makeWorkflowService($store,$gateway,$queue,false,$lookupCalls)->reconcileOrder($order);
erpWorkflowSame('READY_TO_CREATE',$result['status']??null,'A previously missing original sale must be retried once it becomes resolvable.');
erpWorkflowSame('500',$result['original_invoice_id']??null,'Recovered original sale must be persisted before readiness.');
erpWorkflowSame(0,$gateway->createCalls,'Retrying original sale lookup with write gate off must not create externally.');

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



// A previously uncertain create must recover only after authoritative target-side absence is proved.
// Persisted original-sale identity must be reused so quota is not wasted re-reading an already saved sale.
$store=new FakeErpSalesReturnStore();
$store->rows[$order]=[
    'amazon_order_id'=>$order,'status'=>'BLOCKED',
    'original_invoice_id'=>'500','original_invoice_number'=>'1001','original_invoice_key'=>'SALEKEY1001',
    'erp_sales_return_id'=>null,'return_invoice_id'=>null,
    'last_error_code'=>'ERP_SALES_RETURN_CREATE_FAILED','last_error_message'=>'uncertain old create',
];
$gateway=new FakeErpSalesReturnGateway(['ok'=>true,'id'=>'RET-99'],['id'=>'RET-99','order_id'=>$order],null);
$returnQueue=[null,null];$returnCalls=0;$saleCalls=0;
$service=new SvAmazonErpSalesReturnService(
    $store,$gateway,
    static fn(string $id):array=>workflowCases($id),
    static function(string $id) use (&$saleCalls):?array {$saleCalls++;return workflowSale($id);},
    static function(string $id) use (&$returnQueue,&$returnCalls):?array {$returnCalls++;return array_shift($returnQueue);},
    true
);
$result=$service->reconcileOrder($order);
erpWorkflowSame('RETURN_CREATED_WAITING_INVOICE',$result['status']??null,'Verified target absence must permit one guarded retry of an uncertain create.');
erpWorkflowSame(0,$saleCalls,'Persisted original sale must suppress redundant ERP sale API lookup.');
erpWorkflowSame(1,$gateway->probeExistingCalls,'Uncertain create recovery must probe the target before retry.');
erpWorkflowSame(1,$gateway->createCalls,'Verified target absence may perform exactly one guarded create.');
erpWorkflowSame(1,$gateway->readBackCalls,'Retried create must still require target readback.');

// If the target probe finds the previously-created return, recover by readback and never write again.
$store=new FakeErpSalesReturnStore();
$store->rows[$order]=[
    'amazon_order_id'=>$order,'status'=>'BLOCKED',
    'original_invoice_id'=>'500','original_invoice_number'=>'1001','original_invoice_key'=>'SALEKEY1001',
    'erp_sales_return_id'=>null,'return_invoice_id'=>null,
    'last_error_code'=>'ERP_SALES_RETURN_CREATE_FAILED','last_error_message'=>'uncertain old create',
];
$gateway=new FakeErpSalesReturnGateway(['ok'=>true,'id'=>'SHOULD-NOT-WRITE'],['id'=>'RET-EXIST','order_id'=>$order],'RET-EXIST');
$returnQueue=[null];$returnCalls=0;$saleCalls=0;
$result=(new SvAmazonErpSalesReturnService(
    $store,$gateway,
    static fn(string $id):array=>workflowCases($id),
    static function(string $id) use (&$saleCalls):?array {$saleCalls++;return workflowSale($id);},
    static function(string $id) use (&$returnQueue,&$returnCalls):?array {$returnCalls++;return array_shift($returnQueue);},
    true
))->reconcileOrder($order);
erpWorkflowSame('RETURN_CREATED_WAITING_INVOICE',$result['status']??null,'Existing sales return discovered after uncertain create must be recovered by readback.');
erpWorkflowSame('RET-EXIST',$result['erp_sales_return_id']??null,'Recovered external return ID must be persisted.');
erpWorkflowSame(0,$saleCalls,'Recovered uncertain create must reuse persisted original sale identity.');
erpWorkflowSame(1,$gateway->probeExistingCalls,'Recovery must execute exactly one target probe.');
erpWorkflowSame(0,$gateway->createCalls,'Existing target return must suppress duplicate write.');

// A refund that grows after the ERP return/NF is already on file must never be
// silently treated as fully reconciled, and must never trigger a blind duplicate write.
$refundedQuantity=1;
$growingCases=static function(string $id) use (&$refundedQuantity): array {
    return [[
        'id'=>11,'amazon_order_id'=>$id,'amazon_order_item_id'=>'item-1','sku'=>'SKU-1',
        'quantity_ordered'=>5,'quantity_refunded'=>$refundedQuantity,'refund_at'=>'2026-09-12 12:00:00',
    ]];
};
$store=new FakeErpSalesReturnStore();$gateway=new FakeErpSalesReturnGateway(['ok'=>true,'id'=>'RET-90'],['id'=>'RET-90','order_id'=>$order]);
$returnQueue=[null,null,null];$returnCalls=0;
$service=new SvAmazonErpSalesReturnService(
    $store,$gateway,
    $growingCases,
    static fn(string $id):array=>workflowSale($id),
    static function(string $id) use (&$returnQueue,&$returnCalls):?array {$returnCalls++;return array_shift($returnQueue);},
    true
);
$result=$service->reconcileOrder($order);
erpWorkflowSame('RETURN_CREATED_WAITING_INVOICE',$result['status']??null,'Initial refunded quantity must be reconciled normally.');
erpWorkflowSame(1,$result['reconciled_quantity_refunded']??null,'Reconciled quantity must be recorded after a verified create.');
erpWorkflowSame(1,$gateway->createCalls,'Exactly one ERP sales-return write for the initial quantity.');

$refundedQuantity=3;
$result=$service->reconcileOrder($order);
erpWorkflowSame('BLOCKED',$result['status']??null,'Growing refunded quantity beyond what is reconciled must not stay silently accepted.');
erpWorkflowSame('ERP_SALES_RETURN_ADDITIONAL_QUANTITY_PENDING',$result['last_error_code']??null,'Additional unreconciled quantity must be named explicitly.');
erpWorkflowSame(1,$gateway->createCalls,'Additional quantity must never trigger a blind duplicate ERP write.');

echo "erp-sales-return-workflow-test: OK\n";
