<?php
declare(strict_types=1);

require_once __DIR__.'/Config.php';
require_once __DIR__.'/TenantPersistence.php';
require_once __DIR__.'/ErpInvoiceLookup.php';
require_once __DIR__.'/ErpReturnInvoiceLookup.php';
require_once __DIR__.'/ErpSalesReturnGateway.php';
require_once __DIR__.'/ErpSalesReturnService.php';
require_once __DIR__.'/ErpApiRateLimiter.php';
require_once __DIR__.'/CaseConsultation.php';

final class SvAmazonErpSalesReturnTask
{
    private const RESUME_SOURCE='ERP_SALES_RETURNS';
    private const RESUME_KEY='quota_resume_v1';

    /** @return array<string,mixed> */
    public static function run(SvAmazonTenantPersistence $p,SvAmazonReturnsConfig $config): array
    {
        $resumeCursor=$p->cursors->load(self::RESUME_SOURCE,self::RESUME_KEY);
        $processed=self::quotaResumeProcessed($resumeCursor);
        $allOrders=self::refundedOrders($p);
        $orders=self::filterQuotaResumeOrders($allOrders,$processed);
        $skippedProcessed=count($allOrders)-count($orders);
        if($orders===[]){
            if($resumeCursor!==null)$p->cursors->clear(self::RESUME_SOURCE,self::RESUME_KEY);
            $result=self::result([],false,false);
            $result['resumed']=$resumeCursor!==null;
            $result['skipped_processed']=$skippedProcessed;
            $result['resume_pending']=false;
            return $result;
        }

        $credentialPath=$config->get('AMAZON_RETURNS_ERP_ENV_FILE',SvAmazonErpInvoiceLookup::defaultCredentialPath());
        if($credentialPath==='' || !is_readable($credentialPath)){
            return [
                'status'=>'SKIPPED_NOT_CONFIGURED',
                'orders'=>count($orders),
                'reason'=>'ERP_CREDENTIAL_SOURCE_NOT_CONFIGURED',
                'write_enabled'=>false,
            ];
        }

        $limiter=SvAmazonErpApiRateLimiter::fromConfig($config);
        $saleLookup=new SvAmazonErpInvoiceLookup(null,null,$config,static fn()=>$limiter->beforeRequest());
        $returnLookup=new SvAmazonErpReturnInvoiceLookup(null,null,$config);
        $gateway=new SvAmazonOlistBrowserErpSalesReturnGateway(null,$config->get('OLIST_ERP_CDP_URL','http://127.0.0.1:9226'));

        $rows=[];$rateLimited=false;
        $processedSet=array_fill_keys($processed,true);
        foreach($orders as $orderId){
            $limiter->beforeRequest();
            try{
                $cases=$p->cases->forOrder($orderId);
                $service=new SvAmazonErpSalesReturnService(
                    $p->erpSalesReturns,
                    $gateway,
                    static fn(string $candidateOrderId): array=>$p->cases->forOrder($candidateOrderId),
                    static function(string $candidateOrderId) use ($limiter,$saleLookup,$p,$cases): ?array {
                        $workflow=$p->erpSalesReturns->findByOrder($candidateOrderId);
                        $knownMiss=is_array($workflow)
                            && strtoupper(trim((string)($workflow['last_error_code']??'')))==='ERP_ORIGINAL_SALE_NOT_FOUND';
                        $sale=$knownMiss
                            ? $saleLookup->findSaleViaSalesOrder($candidateOrderId)
                            : $saleLookup->findSaleForOrder($candidateOrderId);
                        if(is_array($sale))return $sale;
                        $eventsForCase=static fn(int $caseId): array=>$p->events->eventsForCase($caseId);
                        $invoiceNumber=self::salesInvoiceNumberFromCases($cases,$eventsForCase);
                        if($invoiceNumber!==null){
                            $sale=$saleLookup->findOrderByInvoiceNumber($invoiceNumber);
                            if(is_array($sale))return $sale;
                        }
                        $orphanFacts=self::orphanSaleFacts($cases,$eventsForCase);
                        if($orphanFacts===null)return null;
                        return $saleLookup->findOrphanSaleForOrder(
                            $candidateOrderId,
                            $orphanFacts['order_date'],
                            $orphanFacts['sales_amount'],
                            $orphanFacts['items']
                        );
                    },
                    static function(string $candidateOrderId) use ($limiter,$returnLookup): ?array {
                        $limiter->beforeRequest();
                        return $returnLookup->findForOrder($candidateOrderId);
                    },
                    $config->erpSalesReturnCreateEnabled() && self::writeAllowedForOrderCases($config,$cases)
                );
                $row=$service->reconcileOrder($orderId);
                $rows[]=['order_id'=>$orderId,'status'=>(string)($row['status']??'UNKNOWN')];
                $processedSet[$orderId]=true;
            }catch(Throwable $e){
                $rows[]=['order_id'=>$orderId,'status'=>'ERROR','error_class'=>$e::class];
                if(str_contains($e->getMessage(),'HTTP 429')){
                    $rateLimited=true;
                    $p->cursors->save(
                        self::RESUME_SOURCE,self::RESUME_KEY,'active',
                        ['processed_order_ids'=>array_keys($processedSet)]
                    );
                    break;
                }
                $processedSet[$orderId]=true;
            }
        }
        if(!$rateLimited && $resumeCursor!==null){
            $p->cursors->clear(self::RESUME_SOURCE,self::RESUME_KEY);
        }
        $result=self::result($rows,$config->erpSalesReturnCreateEnabled(),$rateLimited);
        $result['resumed']=$resumeCursor!==null;
        $result['skipped_processed']=$skippedProcessed;
        $result['resume_pending']=$rateLimited;
        $result['incomplete_workflows']=$p->erpSalesReturns->countIncomplete();
        $result['incomplete_workflows_detail']=$p->erpSalesReturns->incompleteAuditRows();
        if($result['incomplete_workflows']>0)$result['status']='PARTIAL';
        return $result;
    }

    /** @return list<string> */
    private static function refundedOrders(SvAmazonTenantPersistence $p): array
    {
        $orders=[];$page=1;$perPage=100;
        do{
            $found=$p->cases->search([], $page, $perPage);
            foreach($found['items']??[] as $case){
                if(!is_array($case) || (int)($case['quantity_refunded']??0)<1)continue;
                $orderId=trim((string)($case['amazon_order_id']??''));
                if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/D',$orderId)!==1)continue;
                $orders[$orderId]=true;
            }
            $total=(int)($found['total']??0);$page++;
        }while((($page-1)*$perPage)<$total && $page<=1000);
        $workflows=[];
        foreach(array_keys($orders) as $orderId){
            $workflow=$p->erpSalesReturns->findByOrder($orderId);
            if(!self::workflowProcessable($workflow))continue;
            $workflows[$orderId]=$workflow;
        }
        $ids=array_keys($workflows);
        usort($ids,static function(string $left,string $right) use ($workflows): int {
            $lw=$workflows[$left]??null;
            $rw=$workflows[$right]??null;
            $lp=self::workflowPriority($lw);
            $rp=self::workflowPriority($rw);
            $lt=self::workflowLastCheckedAt($lw);
            $rt=self::workflowLastCheckedAt($rw);
            return [$lp,$lt,$left]<=>[$rp,$rt,$right];
        });
        return $ids;
    }

    /** @param list<array<string,mixed>> $cases */
    public static function writeAllowedForOrderCases(SvAmazonReturnsConfig $config,array $cases): bool
    {
        $refunded=0;
        foreach($cases as $case){
            if(!is_array($case) || (int)($case['quantity_refunded']??0)<1)continue;
            $caseId=(int)($case['id']??0);
            if($caseId<1 || !$config->writeCaseAllowed($caseId))return false;
            $refunded++;
        }
        return $refunded>0;
    }

    /** @param list<array<string,mixed>> $cases @param callable(int):array $eventsForCase */
    public static function salesInvoiceNumberFromCases(array $cases,callable $eventsForCase): ?string
    {
        $numbers=[];
        foreach($cases as $case){
            if(!is_array($case))continue;
            $caseId=(int)($case['id']??0);
            if($caseId<1)continue;
            $facts=SvAmazonCaseConsultation::invoiceFacts($eventsForCase($caseId));
            $number=trim((string)($facts['sales_invoice_number']??''));
            if(preg_match('/^[0-9]{1,20}$/D',$number)!==1)continue;
            $key=ltrim($number,'0')===''?'0':ltrim($number,'0');
            if(!isset($numbers[$key]))$numbers[$key]=$number;
        }
        if(count($numbers)!==1)return null;
        return (string)array_values($numbers)[0];
    }

    /**
     * Builds conservative local evidence for orphan invoice recovery.
     * Requires one order date, one distinct BRL item value (prefer the absolute
     * Refund/Refunded Sales amount used by the fiscal invoice, otherwise Shipment/Sales),
     * and a complete refunded-order SKU/quantity signature.
     *
     * @param list<array<string,mixed>> $cases
     * @param callable(int):array $eventsForCase
     * @return array{order_date:string,sales_amount:string,items:array<string,int>}|null
     */
    public static function orphanSaleFacts(array $cases,callable $eventsForCase): ?array
    {
        $dates=[];$shipmentAmounts=[];$refundedSalesAmounts=[];$items=[];
        foreach($cases as $case){
            if(!is_array($case))continue;
            $sku=strtolower(trim((string)($case['sku']??'')));
            $quantity=(int)($case['quantity_ordered']??0);
            if($sku==='' || $quantity<1)return null;
            $items[$sku]=($items[$sku]??0)+$quantity;
            $caseId=(int)($case['id']??0);
            if($caseId<1)return null;
            foreach($eventsForCase($caseId) as $event){
                if(!is_array($event))continue;
                $type=strtoupper(trim((string)($event['event_type']??'')));
                $payload=is_array($event['payload']??null)?$event['payload']:[];
                if($type==='ORDER_SYNCED'){
                    $raw=trim((string)($payload['order_at']??''));
                    if($raw!==''){
                        try{
                            $date=(new DateTimeImmutable($raw,new DateTimeZone('UTC')))
                                ->setTimezone(new DateTimeZone('America/Sao_Paulo'))
                                ->format('Y-m-d');
                        }catch(Throwable){$date='';}
                        if($date!=='')$dates[$date]=true;
                    }
                }
                if($type!=='FINANCIAL_TRANSACTION_OBSERVED')continue;
                $tx=is_array($payload['transaction']??null)?$payload['transaction']:[];
                $transactionType=strtoupper(trim((string)($tx['transaction_type']??'')));
                foreach(($tx['breakdowns']??[]) as $breakdown){
                    if(!is_array($breakdown))continue;
                    $breakdownType=strtoupper(trim((string)($breakdown['breakdown_type']??'')));
                    $money=is_array($breakdown['breakdown_amount']??null)?$breakdown['breakdown_amount']:[];
                    $amount=$money['amount']??null;
                    $currency=strtoupper(trim((string)($money['currency']??'')));
                    if($currency!=='BRL' || !is_numeric($amount))continue;
                    if($transactionType==='REFUND' && $breakdownType==='REFUNDED SALES' && (float)$amount<0){
                        $refundedSalesAmounts[number_format(abs((float)$amount),2,'.','')]=true;
                    }elseif($transactionType==='SHIPMENT' && $breakdownType==='SALES' && (float)$amount>0){
                        $shipmentAmounts[number_format((float)$amount,2,'.','')]=true;
                    }
                }
            }
        }
        ksort($items,SORT_STRING);
        if($items===[] || count($dates)!==1)return null;
        $amounts=$refundedSalesAmounts!==[]?$refundedSalesAmounts:$shipmentAmounts;
        if(count($amounts)!==1)return null;
        return [
            'order_date'=>(string)array_key_first($dates),
            'sales_amount'=>(string)array_key_first($amounts),
            'items'=>$items,
        ];
    }

    /** @param array<string,mixed>|null $workflow */
    public static function workflowProcessable(?array $workflow): bool
    {
        $status=strtoupper(trim((string)($workflow['status']??'PENDING')));
        return $status!=='RETURN_INVOICE_EXISTS';
    }

    /** @param array<string,mixed>|null $cursor @return list<string> */
    public static function quotaResumeProcessed(?array $cursor): array
    {
        $metadata=is_array($cursor['metadata']??null)?$cursor['metadata']:[];
        $raw=is_array($metadata['processed_order_ids']??null)?$metadata['processed_order_ids']:[];
        $orders=[];
        foreach($raw as $orderId){
            if(!is_string($orderId) || preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/D',$orderId)!==1)continue;
            $orders[$orderId]=true;
        }
        return array_keys($orders);
    }

    /** @param list<string> $orders @param list<string> $processed @return list<string> */
    public static function filterQuotaResumeOrders(array $orders,array $processed): array
    {
        if($processed===[])return array_values($orders);
        $seen=array_fill_keys($processed,true);
        return array_values(array_filter(
            $orders,
            static fn(string $orderId):bool=>!isset($seen[$orderId])
        ));
    }

    /** @param array<string,mixed>|null $workflow */
    public static function workflowPriority(?array $workflow): int
    {
        $status=strtoupper(trim((string)($workflow['status']??'PENDING')));
        if($status==='BLOCKED'){
            $code=strtoupper(trim((string)($workflow['last_error_code']??'')));
            if(in_array($code,[
                'ERP_ORIGINAL_SALE_NOT_FOUND',
                'ERP_SALES_RETURN_CREATE_FAILED',
                'ERP_SALES_RETURN_WRITE_NOT_VERIFIED',
                'ERP_SALES_RETURN_AUTH_REQUIRED',
                'ERP_SALES_RETURN_BROWSER_UNAVAILABLE',
                'ERP_SALES_RETURN_UI_DRIFT',
            ],true))return 1;
            return 4;
        }
        return match($status){
            'PENDING'=>0,
            'READY_TO_CREATE'=>2,
            'RETURN_CREATED_WAITING_INVOICE'=>3,
            'RETURN_INVOICE_EXISTS'=>5,
            default=>0,
        };
    }

    /** @param array<string,mixed>|null $workflow */
    public static function workflowLastCheckedAt(?array $workflow): string
    {
        $value=trim((string)($workflow['last_checked_at']??''));
        return $value===''?'0000-00-00 00:00:00':$value;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private static function result(array $rows,bool $writeEnabled,bool $rateLimited): array
    {
        $counts=['READY_TO_CREATE'=>0,'RETURN_CREATED_WAITING_INVOICE'=>0,'RETURN_INVOICE_EXISTS'=>0,'BLOCKED'=>0,'ERROR'=>0,'OTHER'=>0];
        foreach($rows as $row){
            $status=strtoupper(trim((string)($row['status']??'')));
            if(isset($counts[$status]))$counts[$status]++;else $counts['OTHER']++;
        }
        $incomplete=$counts['READY_TO_CREATE']+$counts['BLOCKED']+$counts['ERROR']+$counts['OTHER'];
        return [
            'status'=>$incomplete>0?'PARTIAL':'OK',
            'orders'=>count($rows),
            'ready'=>$counts['READY_TO_CREATE'],
            'created_waiting_invoice'=>$counts['RETURN_CREATED_WAITING_INVOICE'],
            'invoice_exists'=>$counts['RETURN_INVOICE_EXISTS'],
            'blocked'=>$counts['BLOCKED'],
            'errors'=>$counts['ERROR'],
            'other'=>$counts['OTHER'],
            'rate_limited'=>$rateLimited,
            'write_enabled'=>$writeEnabled,
        ];
    }
}
