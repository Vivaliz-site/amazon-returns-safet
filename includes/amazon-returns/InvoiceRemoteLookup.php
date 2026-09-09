<?php
declare(strict_types=1);

require_once __DIR__.'/SpApi.php';
require_once __DIR__.'/ErpInvoiceLookup.php';

final class SvAmazonInvoiceRemoteLookup
{
    private object $amazon;
    private object $erp;

    public function __construct(?object $amazon=null,?object $erp=null)
    {
        $this->amazon=$amazon ?? new SvAmazonReturnsSpApi();
        $this->erp=$erp ?? new SvAmazonErpInvoiceLookup();
        foreach([['client'=>$this->amazon,'name'=>'Amazon'],['client'=>$this->erp,'name'=>'ERP']] as $entry){
            if(!method_exists($entry['client'],'findOrderByInvoiceNumber')){
                throw new InvalidArgumentException($entry['name'].' invoice lookup transport is invalid.');
            }
        }
    }

    /** @return array<string,mixed>|null */
    public function findOrderByInvoiceNumber(string $invoiceNumber): ?array
    {
        try{
            return $this->amazon->findOrderByInvoiceNumber($invoiceNumber);
        }catch(SvAmazonInvoiceAccessException $e){
            error_log('[amazon-returns-invoice-fallback] Amazon Invoices API unavailable; using ERP read-only lookup.');
            return $this->erp->findOrderByInvoiceNumber($invoiceNumber);
        }
    }
}
