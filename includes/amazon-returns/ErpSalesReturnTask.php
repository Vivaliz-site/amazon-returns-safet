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
    /** @return array<string,mixed> */
    public static function run(SvAmazonTenantPersistence $p,SvAmazonReturnsConfig $config): array
    {
        $orders=self::refundedOrders($p);
        if($orders===[])return self::result([],false,false);

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
                        $invoiceNumber=self::salesInvoiceNumberFromCases(
                            $cases,
                            static fn(int $caseId): array=>$p->events->eventsForCase($caseId)
                        );
                        if($invoiceNumber===null)return null;
                        return $saleLookup->findOrderByInvoiceNumber($invoiceNumber);
                    },
                    static function(string $candidateOrderId) use ($limiter,$returnLookup): ?array {
                        $limiter->beforeRequest();
                        return $returnLookup->findForOrder($candidateOrderId);
                    },
                    $config->erpSalesReturnCreateEnabled() && self::writeAllowedForOrderCases($config,$cases)
                );
                $row=$service->reconcileOrder($orderId);
                $rows[]=['order_id'=>$orderId,'status'=>(string)($row['status']??'UNKNOWN')];
            }catch(Throwable $e){
                $rows[]=['order_id'=>$orderId,'status'=>'ERROR','error_class'=>$e::class];
                if(str_contains($e->getMessage(),'HTTP 429')){
                    $rateLimited=true;
                    break;
                }
            }
        }
        return self::result($rows,$config->erpSalesReturnCreateEnabled(),$rateLimited);
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
        $ids=array_keys($orders);
        usort($ids,static function(string $left,string $right) use ($p): int {
            $lw=$p->erpSalesReturns->findByOrder($left);
            $rw=$p->erpSalesReturns->findByOrder($right);
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
        return [
            'status'=>$counts['ERROR']>0?'PARTIAL':'OK',
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
