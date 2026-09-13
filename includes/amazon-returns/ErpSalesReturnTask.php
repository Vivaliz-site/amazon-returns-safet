<?php
declare(strict_types=1);

require_once __DIR__.'/Config.php';
require_once __DIR__.'/TenantPersistence.php';
require_once __DIR__.'/ErpInvoiceLookup.php';
require_once __DIR__.'/ErpReturnInvoiceLookup.php';
require_once __DIR__.'/ErpSalesReturnGateway.php';
require_once __DIR__.'/ErpSalesReturnService.php';

final class SvAmazonErpSalesReturnTask
{
    /** @return array<string,mixed> */
    public static function run(SvAmazonTenantPersistence $p,SvAmazonReturnsConfig $config): array
    {
        $orders=self::refundedOrders($p);
        if($orders===[])return self::result([],false);

        $credentialPath=$config->get('AMAZON_RETURNS_ERP_ENV_FILE',SvAmazonErpInvoiceLookup::defaultCredentialPath());
        if($credentialPath==='' || !is_readable($credentialPath)){
            return [
                'status'=>'SKIPPED_NOT_CONFIGURED',
                'orders'=>count($orders),
                'reason'=>'ERP_CREDENTIAL_SOURCE_NOT_CONFIGURED',
                'write_enabled'=>false,
            ];
        }

        $saleLookup=new SvAmazonErpInvoiceLookup(null,null,$config);
        $returnLookup=new SvAmazonErpReturnInvoiceLookup(null,null,$config);
        $gateway=new SvAmazonUnverifiedErpSalesReturnGateway();
        $service=new SvAmazonErpSalesReturnService(
            $p->erpSalesReturns,
            $gateway,
            static fn(string $orderId): array=>$p->cases->forOrder($orderId),
            static fn(string $orderId): ?array=>$saleLookup->findSaleForOrder($orderId),
            static fn(string $orderId): ?array=>$returnLookup->findForOrder($orderId),
            $config->erpSalesReturnCreateEnabled()
        );

        $rows=[];
        foreach($orders as $orderId){
            try{
                $row=$service->reconcileOrder($orderId);
                $rows[]=['order_id'=>$orderId,'status'=>(string)($row['status']??'UNKNOWN')];
            }catch(Throwable $e){
                $rows[]=['order_id'=>$orderId,'status'=>'ERROR','error_class'=>$e::class];
            }
        }
        return self::result($rows,$config->erpSalesReturnCreateEnabled());
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
        $ids=array_keys($orders);sort($ids,SORT_STRING);return $ids;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private static function result(array $rows,bool $writeEnabled): array
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
            'write_enabled'=>$writeEnabled,
        ];
    }
}
