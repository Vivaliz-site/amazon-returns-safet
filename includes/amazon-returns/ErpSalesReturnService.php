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
        $refundedQuantity=array_sum(array_map(
            static fn(array $item): int=>max(0,(int)($item['quantity_refunded']??0)),
            $items
        ));

        // Persist the order identity before any ERP resolution. An already-issued
        // return invoice is authoritative and must suppress creation even when the
        // original sale lookup is temporarily unavailable.
        $workflow=$this->store->ensureWorkflow(['amazon_order_id'=>$orderId]);
        $persistedStatus=strtoupper(trim((string)($workflow['status']??'')));
        if(
            $persistedStatus==='RETURN_CREATED_WAITING_INVOICE'
            && ($workflow['reconciled_quantity_refunded']??null)===null
            && $this->legacyCreatedReturnCanBaseline($workflow,$cases)
        ){
            // Legacy rows were created and read back before quantity tracking existed.
            // Baseline locally only when every current refund predates that verified
            // ERP creation, so a later refund can never be hidden by the migration.
            return $this->store->recordReconciledQuantity($orderId,$refundedQuantity);
        }
        if($persistedStatus==='RETURN_INVOICE_EXISTS'){
            $reconciledRaw=$workflow['reconciled_quantity_refunded']??null;
            if($reconciledRaw===null){
                // Legacy row predating quantity tracking: baseline it locally
                // (no ERP quota spent) instead of assuming it is stale.
                return $this->store->recordReconciledQuantity($orderId,$refundedQuantity);
            }
            if((int)$reconciledRaw>=$refundedQuantity)return $workflow;
            // Fall through: quantity grew past what this terminal return/NF
            // covers, so the full reconciliation path below must re-evaluate
            // and, ultimately, surface it as a blocker rather than trust the
            // quota-saving shortcut blindly.
        }else{
            $existingInvoice=($this->returnInvoiceLookup)($orderId);
            if(is_array($existingInvoice)){
                $workflow=$this->store->linkReturnInvoice($orderId,$existingInvoice);
                return $this->store->recordReconciledQuantity($orderId,$refundedQuantity);
            }
            if(
                $persistedStatus==='RETURN_CREATED_WAITING_INVOICE'
                && ($workflow['reconciled_quantity_refunded']??null)!==null
            ){
                $readBackInvoice=$this->returnInvoiceFromSalesReturnReadBack($workflow,$orderId);
                if(is_array($readBackInvoice)){
                    $workflow=$this->store->linkReturnInvoice($orderId,$readBackInvoice);
                    return $this->store->recordReconciledQuantity($orderId,$refundedQuantity);
                }
            }
        }

        // Reuse the already-persisted sale identity before spending ERP API quota.
        // This is especially important while resuming a partially processed backlog.
        $sale=$this->persistedOriginalSale($workflow,$orderId);
        if($sale===null)$sale=($this->saleResolver)($orderId);
        if(!is_array($sale)){
            return $this->store->markBlocked($orderId,'ERP_ORIGINAL_SALE_NOT_FOUND','Nao foi possivel localizar a venda original no Olist/Tiny.');
        }
        $saleOrderId=trim((string)($sale['order_id']??$orderId));
        if($saleOrderId!==$orderId){
            return $this->store->markBlocked($orderId,'ERP_ORIGINAL_SALE_ORDER_MISMATCH','A venda localizada no Olist/Tiny pertence a outro pedido Amazon.');
        }

        $workflow=$this->store->ensureWorkflow([
            'amazon_order_id'=>$orderId,
            'original_invoice_id'=>self::nullable($sale['invoice_id']??null),
            'original_invoice_number'=>self::nullable($sale['invoice_number']??null),
            'original_invoice_key'=>self::nullable($sale['access_key']??($sale['invoice_key']??null)),
        ]);

        $workflow=$this->store->findByOrder($orderId)??$workflow;
        $status=strtoupper(trim((string)($workflow['status']??'')));
        if(in_array($status,['RETURN_INVOICE_EXISTS','RETURN_CREATED_WAITING_INVOICE'],true)){
            $reconciled=(int)($workflow['reconciled_quantity_refunded']??0);
            if($reconciled>=$refundedQuantity)return $workflow;
            // More was refunded on this order after the devolucao/NF already on file was
            // created for a smaller quantity. Automatic amendment/second-document
            // creation in the ERP is not verified, so surface this as an explicit
            // blocker instead of silently treating the order as fully reconciled.
            return $this->store->markBlocked(
                $orderId,
                'ERP_SALES_RETURN_ADDITIONAL_QUANTITY_PENDING',
                'Quantidade reembolsada aumentou apos a devolucao/NF existente no Olist/Tiny; reconciliacao adicional exige acao manual.'
            );
        }

        // Generic CREATE_FAILED is intentionally uncertain: never retry it blindly.
        // First prove target-side absence. If the old write actually exists, read it
        // back and recover the workflow instead of creating a duplicate.
        $verifiedAbsentAfterUncertainCreate=false;
        $errorCode=strtoupper(trim((string)($workflow['last_error_code']??'')));
        if($status==='BLOCKED' && $errorCode==='ERP_SALES_RETURN_CREATE_FAILED'){
            $originalInvoiceId=trim((string)($workflow['original_invoice_id']??''));
            if($originalInvoiceId==='')return $workflow;
            $existingSalesReturnId=$this->gateway->probeExisting(
                $originalInvoiceId,
                trim((string)($workflow['original_invoice_number']??''))
            );
            if($existingSalesReturnId!==null){
                $readBack=$this->gateway->readBack($existingSalesReturnId,$orderId);
                if($this->validReadBack($readBack,$existingSalesReturnId,$orderId)){
                    $this->store->markReturnCreated($orderId,$existingSalesReturnId);
                    return $this->store->recordReconciledQuantity($orderId,$refundedQuantity);
                }
                return $this->store->markBlocked(
                    $orderId,
                    'ERP_SALES_RETURN_READBACK_NOT_CONFIRMED',
                    'A devolucao existente no Olist/Tiny nao pôde ser confirmada por leitura. Nova escrita foi bloqueada para evitar duplicidade.'
                );
            }
            $verifiedAbsentAfterUncertainCreate=true;
        }
        if($status==='BLOCKED' && !$verifiedAbsentAfterUncertainCreate
            && !$this->retryablePreWriteBlock((string)($workflow['last_error_code']??''))){
            return $workflow;
        }

        $refundAt=$this->refundAt($cases);
        if($refundAt===null){
            return $this->store->markBlocked($orderId,'ERP_REFUND_DATE_NOT_FOUND','Nao foi possivel confirmar a data do reembolso para criar a devolucao no ERP.');
        }

        if(!$this->writeEnabled){
            return $this->store->markReady($orderId);
        }

        // Mandatory second preflight immediately before the external write.
        $raceInvoice=($this->returnInvoiceLookup)($orderId);
        if(is_array($raceInvoice)){
            $this->store->linkReturnInvoice($orderId,$raceInvoice);
            return $this->store->recordReconciledQuantity($orderId,$refundedQuantity);
        }

        $result=$this->gateway->create([
            'amazon_order_id'=>$orderId,
            'original_sale'=>[
                'invoice_id'=>self::nullable($sale['invoice_id']??null),
                'invoice_number'=>self::nullable($sale['invoice_number']??null),
                'access_key'=>self::nullable($sale['access_key']??($sale['invoice_key']??null)),
            ],
            'items'=>$items,
            'refund_at'=>$refundAt,
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
                $this->store->markReturnCreated($orderId,$candidateId);
                return $this->store->recordReconciledQuantity($orderId,$refundedQuantity);
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

    /** @param array<string,mixed> $workflow @return array<string,mixed>|null */
    private function returnInvoiceFromSalesReturnReadBack(array $workflow,string $orderId): ?array
    {
        $returnId=trim((string)($workflow['erp_sales_return_id']??''));
        if($returnId==='')return null;
        $readBack=$this->gateway->readBack($returnId,$orderId);
        if(!$this->validReadBack($readBack,$returnId,$orderId)){
            throw new RuntimeException('Persisted ERP sales return could not be confirmed by target read-back.');
        }

        $expectedOriginalInvoiceId=trim((string)($workflow['original_invoice_id']??''));
        $readOriginalInvoiceId=trim((string)($readBack['idNotaFiscal']??''));
        if(
            $expectedOriginalInvoiceId!=='' && $readOriginalInvoiceId!==''
            && $expectedOriginalInvoiceId!==$readOriginalInvoiceId
        ){
            throw new UnexpectedValueException('ERP sales return read-back belongs to a different original invoice.');
        }

        $returnInvoiceId=trim((string)($readBack['idNotaFiscalEntrada']??''));
        if(preg_match('/^[1-9][0-9]*$/D',$returnInvoiceId)!==1)return null;

        return [
            'source'=>'ERP_OLIST_SALES_RETURN_READBACK',
            'invoice_id'=>$returnInvoiceId,
            'invoice_number'=>self::nullable($readBack['numeroNotaFiscalEntrada']??null),
            'series'=>self::nullable($readBack['serieNotaFiscalEntrada']??null),
            'access_key'=>self::nullable($readBack['chaveAcessoNotaFiscalEntrada']??null),
            'status'=>self::nullable($readBack['situacao']??null),
            'purpose'=>4,
            'order_id'=>$orderId,
            'issued_at'=>self::nullable($readBack['dataDevolucao']??null),
        ];
    }

    /** @param array<string,mixed> $workflow @return array<string,mixed>|null */
    private function persistedOriginalSale(array $workflow,string $orderId): ?array
    {
        $invoiceId=trim((string)($workflow['original_invoice_id']??''));
        if($invoiceId==='')return null;
        return [
            'order_id'=>$orderId,
            'invoice_id'=>$invoiceId,
            'invoice_number'=>self::nullable($workflow['original_invoice_number']??null),
            'access_key'=>self::nullable($workflow['original_invoice_key']??null),
        ];
    }

    /** @param array<string,mixed> $workflow @param list<array<string,mixed>> $cases */
    private function legacyCreatedReturnCanBaseline(array $workflow,array $cases): bool
    {
        $createdAt=self::utcTimestamp($workflow['created_in_erp_at']??null);
        if($createdAt===null)return false;
        $latestRefundAt=null;
        foreach($cases as $case){
            if(!is_array($case) || (int)($case['quantity_refunded']??0)<1)continue;
            $refundAt=self::utcTimestamp($case['refund_at']??null);
            if($refundAt===null)return false;
            if($latestRefundAt===null || $refundAt>$latestRefundAt)$latestRefundAt=$refundAt;
        }
        return $latestRefundAt!==null && $latestRefundAt<=$createdAt;
    }

    private static function utcTimestamp(mixed $value): ?DateTimeImmutable
    {
        if(!is_scalar($value))return null;
        $value=trim((string)$value);
        if($value==='')return null;
        try{
            return (new DateTimeImmutable($value,new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'));
        }catch(Throwable){
            return null;
        }
    }

    /** @param list<array<string,mixed>> $cases */
    private function refundAt(array $cases): ?string
    {
        $dates=[];
        foreach($cases as $case){
            if(!is_array($case))continue;
            $value=trim((string)($case['refund_at']??''));
            if($value!=='')$dates[]=$value;
        }
        if($dates===[])return null;
        sort($dates,SORT_STRING);
        return $dates[0];
    }

    private function retryablePreWriteBlock(string $code): bool
    {
        return in_array(strtoupper(trim($code)),[
            'ERP_SALES_RETURN_WRITE_NOT_VERIFIED',
            'ERP_SALES_RETURN_AUTH_REQUIRED',
            'ERP_SALES_RETURN_BROWSER_UNAVAILABLE',
            'ERP_SALES_RETURN_UI_DRIFT',
            'ERP_SALES_RETURN_ADDRESS_NUMBER_REQUIRED',
            'ERP_SALES_RETURN_ITEM_MAPPING_FAILED',
            'ERP_ORIGINAL_SALE_NOT_FOUND',
        ],true);
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
                'quantity_ordered'=>0,
                'quantity_refunded'=>0,
            ];
            $aggregated[$key]['quantity_ordered']=max(
                (int)$aggregated[$key]['quantity_ordered'],
                max(0,(int)($case['quantity_ordered']??0))
            );
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
