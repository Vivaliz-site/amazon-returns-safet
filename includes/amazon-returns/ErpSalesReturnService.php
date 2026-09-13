<?php
declare(strict_types=1);

require_once __DIR__.'/ErpSalesReturnRepository.php';
require_once __DIR__.'/ErpSalesReturnGateway.php';

final class SvAmazonErpSalesReturnService
{
    /** @var callable(string):list<array<string,mixed>> */
    private $casesForOrder;
    /** @var callable(string):?array<string,mixed> */
    private $saleResolver;
    /** @var callable(string):?array<string,mixed> */
    private $returnInvoiceLookup;

    public function __construct(
        private SvAmazonErpSalesReturnStore $store,
        private SvAmazonErpSalesReturnGateway $gateway,
        callable $casesForOrder,
        callable $saleResolver,
        callable $returnInvoiceLookup,
        private bool $writeEnabled=false
    ) {
        $this->casesForOrder=$casesForOrder;
        $this->saleResolver=$saleResolver;
        $this->returnInvoiceLookup=$returnInvoiceLookup;
    }

    /** @return array<string,mixed> */
    public function reconcileOrder(string $orderId): array
    {
        $orderId=self::orderId($orderId);
        $cases=($this->casesForOrder)($orderId);
        if(!is_array($cases) || $cases===[]){
            throw new RuntimeException('Amazon order has no return cases.');
        }
        $items=$this->refundedItems($orderId,$cases);
        if($items===[]){
            throw new RuntimeException('Amazon order has no refunded quantity to reconcile with ERP.');
        }

        $sale=($this->saleResolver)($orderId);
        if(!is_array($sale)){
            $workflow=$this->store->ensureWorkflow(['amazon_order_id'=>$orderId]);
            return $this->store->markBlocked($orderId,'ERP_ORIGINAL_SALE_NOT_FOUND','Nao foi possivel localizar a venda original no Olist/Tiny.');
        }
        $saleOrderId=trim((string)($sale['order_id']??$orderId));
        if($saleOrderId!==$orderId){
            $this->store->ensureWorkflow(['amazon_order_id'=>$orderId]);
            return $this->store->markBlocked($orderId,'ERP_ORIGINAL_SALE_ORDER_MISMATCH','A venda localizada no Olist/Tiny pertence a outro pedido Amazon.');
        }

        $workflow=$this->store->ensureWorkflow([
            'amazon_order_id'=>$orderId,
            'original_invoice_id'=>self::nullable($sale['invoice_id']??null),
            'original_invoice_number'=>self::nullable($sale['invoice_number']??null),
            'original_invoice_key'=>self::nullable($sale['access_key']??($sale['invoice_key']??null)),
        ]);

        $existingInvoice=($this->returnInvoiceLookup)($orderId);
        if(is_array($existingInvoice)){
            return $this->store->linkReturnInvoice($orderId,$existingInvoice);
        }

        $workflow=$this->store->findByOrder($orderId)??$workflow;
        $status=strtoupper(trim((string)($workflow['status']??'')));
        if(in_array($status,['RETURN_INVOICE_EXISTS','RETURN_CREATED_WAITING_INVOICE','BLOCKED'],true)){
            return $workflow;
        }

        if(!$this->writeEnabled){
            return $this->store->markReady($orderId);
        }

        // Mandatory second preflight immediately before the external write.
        $raceInvoice=($this->returnInvoiceLookup)($orderId);
        if(is_array($raceInvoice)){
            return $this->store->linkReturnInvoice($orderId,$raceInvoice);
        }

        $result=$this->gateway->create([
            'amazon_order_id'=>$orderId,
            'original_sale'=>[
                'invoice_id'=>self::nullable($sale['invoice_id']??null),
                'invoice_number'=>self::nullable($sale['invoice_number']??null),
                'access_key'=>self::nullable($sale['access_key']??($sale['invoice_key']??null)),
            ],
            'items'=>$items,
        ]);
        if(!is_array($result)){
            return $this->store->markBlocked($orderId,'ERP_SALES_RETURN_INVALID_RESPONSE','O Olist/Tiny retornou uma resposta invalida ao criar a devolucao.');
        }

        $candidateId=trim((string)($result['id']??($result['erp_sales_return_id']??'')));
        $ok=($result['ok']??false)===true;
        $uncertain=($result['uncertain']??false)===true;
        if($ok || ($uncertain && $candidateId!=='')){
            if($candidateId===''){
                return $this->store->markBlocked($orderId,'ERP_SALES_RETURN_ID_MISSING','O Olist/Tiny nao informou o identificador da devolucao criada.');
            }
            $readBack=$this->gateway->readBack($candidateId,$orderId);
            if($this->validReadBack($readBack,$candidateId,$orderId)){
                return $this->store->markReturnCreated($orderId,$candidateId);
            }
            return $this->store->markBlocked(
                $orderId,
                'ERP_SALES_RETURN_READBACK_NOT_CONFIRMED',
                'A tentativa de criar a devolucao no Olist/Tiny nao pôde ser confirmada por leitura. Nova escrita foi bloqueada para evitar duplicidade.'
            );
        }

        $code=self::safeCode($result['error_code']??null,'ERP_SALES_RETURN_CREATE_FAILED');
        $message=self::safeMessage($result['error_message']??null,'Nao foi possivel criar a devolucao automaticamente no Olist/Tiny.');
        return $this->store->markBlocked($orderId,$code,$message);
    }

    /** @param list<array<string,mixed>> $cases @return list<array<string,mixed>> */
    private function refundedItems(string $orderId,array $cases): array
    {
        $aggregated=[];
        foreach($cases as $case){
            if(!is_array($case))continue;
            if(trim((string)($case['amazon_order_id']??''))!==$orderId)throw new UnexpectedValueException('Return case belongs to another Amazon order.');
            $quantity=max(0,(int)($case['quantity_refunded']??0));
            if($quantity===0)continue;
            $itemId=trim((string)($case['amazon_order_item_id']??''));
            $sku=trim((string)($case['sku']??''));
            $key=$itemId!==''?'item:'.$itemId:'sku:'.$sku;
            if($key==='sku:')throw new UnexpectedValueException('Refunded ERP return item has no stable identity.');
            if(!isset($aggregated[$key]))$aggregated[$key]=[
                'amazon_order_item_id'=>$itemId!==''?$itemId:null,
                'sku'=>$sku!==''?$sku:null,
                'quantity_refunded'=>0,
            ];
            $aggregated[$key]['quantity_refunded']+=(int)$quantity;
        }
        ksort($aggregated,SORT_STRING);
        return array_values($aggregated);
    }

    /** @param array<string,mixed>|null $readBack */
    private function validReadBack(?array $readBack,string $candidateId,string $orderId): bool
    {
        if(!is_array($readBack))return false;
        $id=trim((string)($readBack['id']??($readBack['erp_sales_return_id']??'')));
        if($id!==$candidateId)return false;
        $readOrder=trim((string)($readBack['order_id']??($readBack['amazon_order_id']??'')));
        return $readOrder==='' || $readOrder===$orderId;
    }

    private static function orderId(string $value): string
    {
        $value=trim($value);
        if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/D',$value)!==1)throw new InvalidArgumentException('Amazon order ID is invalid.');
        return $value;
    }

    private static function nullable(mixed $value): ?string
    {
        if($value===null || !is_scalar($value))return null;
        $value=trim((string)$value);
        return $value===''?null:$value;
    }

    private static function safeCode(mixed $value,string $fallback): string
    {
        if(!is_scalar($value))return $fallback;
        $value=strtoupper(trim((string)$value));
        return preg_match('/^[A-Z0-9_]{3,96}$/D',$value)===1?$value:$fallback;
    }

    private static function safeMessage(mixed $value,string $fallback): string
    {
        if(!is_scalar($value))return $fallback;
        $value=trim((string)$value);
        if($value==='' || strlen($value)>512)return $fallback;
        return $value;
    }
}
