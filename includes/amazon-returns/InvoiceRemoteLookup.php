<?php
declare(strict_types=1);

require_once __DIR__.'/SpApi.php';
require_once __DIR__.'/ErpInvoiceLookup.php';

final class SvAmazonInvoiceRemoteLookup
{
    private ?object $amazon;
    private ?object $erp;

    public function __construct(?object $amazon=null,?object $erp=null)
    {
        $this->amazon=$amazon;
        $this->erp=$erp;
        if($this->amazon!==null && !method_exists($this->amazon,'findOrderByInvoiceNumber')){
            throw new InvalidArgumentException('Amazon invoice lookup transport is invalid.');
        }
        if($this->erp!==null
            && !method_exists($this->erp,'findOrderByInvoiceNumber')
            && !method_exists($this->erp,'findOrdersByInvoiceNumber')){
            throw new InvalidArgumentException('ERP invoice lookup transport is invalid.');
        }
    }

    /** @return list<array<string,mixed>> */
    public function findOrdersByInvoiceNumber(string $invoiceNumber): array
    {
        $byOrder=[];
        $amazonFailure=null;
        try{
            $found=$this->amazon()->findOrderByInvoiceNumber($invoiceNumber);
            if(is_array($found))$this->addResolved($byOrder,$found);
        }catch(RuntimeException $e){
            $amazonFailure=$e;
            error_log('[amazon-returns-invoice-fallback] Amazon invoice lookup unavailable; checking ERP read-only source. class='.get_class($e));
        }

        try{
            foreach($this->erpOrders($invoiceNumber) as $found)$this->addResolved($byOrder,$found);
        }catch(Throwable $erpFailure){
            if($byOrder===[])throw $erpFailure;
            error_log('[amazon-returns-invoice-fallback] ERP invoice lookup unavailable after Amazon match. class='.get_class($erpFailure));
        }

        ksort($byOrder,SORT_STRING);
        return array_values($byOrder);
    }

    /** @return array<string,mixed>|null */
    public function findOrderByInvoiceNumber(string $invoiceNumber): ?array
    {
        $resolved=$this->findOrdersByInvoiceNumber($invoiceNumber);
        if($resolved===[])return null;
        if(count($resolved)!==1)throw new UnexpectedValueException('Remote invoice maps to multiple Amazon orders.');
        return $resolved[0];
    }

    private function amazon(): object
    {
        if($this->amazon===null)$this->amazon=new SvAmazonReturnsSpApi();
        return $this->amazon;
    }

    private function erp(): object
    {
        if($this->erp===null)$this->erp=new SvAmazonErpInvoiceLookup();
        return $this->erp;
    }

    /** @return list<array<string,mixed>> */
    private function erpOrders(string $invoiceNumber): array
    {
        $erp=$this->erp();
        if(method_exists($erp,'findOrdersByInvoiceNumber')){
            $rows=$erp->findOrdersByInvoiceNumber($invoiceNumber);
            return is_array($rows)?array_values(array_filter($rows,'is_array')):[];
        }
        $row=$erp->findOrderByInvoiceNumber($invoiceNumber);
        return is_array($row)?[$row]:[];
    }

    /** @param array<string,array<string,mixed>> $byOrder @param array<string,mixed> $found */
    private function addResolved(array &$byOrder,array $found): void
    {
        $orderId=trim((string)($found['order_id']??''));
        if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/D',$orderId)!==1)return;
        if(!isset($byOrder[$orderId]))$byOrder[$orderId]=$found;
    }
}
