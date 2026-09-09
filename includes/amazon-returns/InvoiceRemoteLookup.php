<?php
declare(strict_types=1);

require_once __DIR__.'/SpApi.php';
require_once __DIR__.'/ErpInvoiceLookup.php';

final class SvAmazonInvoiceRemoteLookup
{
    private object $amazon;
    private ?object $erp;

    public function __construct(?object $amazon=null,?object $erp=null)
    {
        $this->amazon=$amazon ?? new SvAmazonReturnsSpApi();
        $this->erp=$erp;
        if(!method_exists($this->amazon,'findOrderByInvoiceNumber')){
            throw new InvalidArgumentException('Amazon invoice lookup transport is invalid.');
        }
        if($this->erp!==null && !method_exists($this->erp,'findOrderByInvoiceNumber')){
            throw new InvalidArgumentException('ERP invoice lookup transport is invalid.');
        }
    }

    /** @return array<string,mixed>|null */
    public function findOrderByInvoiceNumber(string $invoiceNumber): ?array
    {
        try{
            return $this->amazon->findOrderByInvoiceNumber($invoiceNumber);
        }catch(SvAmazonInvoiceAccessException $e){
            error_log('[amazon-returns-invoice-fallback] Amazon Invoices API unavailable; using ERP read-only lookup.');
            $erp=$this->erp ?? new SvAmazonErpInvoiceLookup();
            return $erp->findOrderByInvoiceNumber($invoiceNumber);
        }
    }
}
