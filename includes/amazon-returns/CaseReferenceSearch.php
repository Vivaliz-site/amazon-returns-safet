<?php
declare(strict_types=1);

require_once __DIR__.'/TenantContext.php';
require_once __DIR__.'/CaseRepository.php';
require_once __DIR__.'/TenantEventStore.php';
require_once __DIR__.'/InvoiceSearch.php';

final class SvAmazonCaseReferenceSearch
{
    public const ORDER='ORDER';
    public const INVOICE='INVOICE';
    public const RETURN_TRACKING='RETURN_TRACKING';
    public const REFERENCE='REFERENCE';

    public static function kind(string $term): string
    {
        $term=trim($term);
        if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/',$term)===1)return self::ORDER;
        if(preg_match('/^TBR[A-Z0-9-]{6,30}$/i',$term)===1)return self::RETURN_TRACKING;
        if(preg_match('/^[0-9]{1,20}$/',$term)===1)return self::INVOICE;
        return self::REFERENCE;
    }

    /** @return list<int> */
    public static function caseIds(PDO $db,SvAmazonTenantContext $context,string $term): array
    {
        $term=trim($term);
        if($term==='')return [];
        $kind=self::kind($term);
        $exact=in_array($kind,[self::ORDER,self::INVOICE,self::RETURN_TRACKING],true);
        $caseRepository=new SvAmazonReturnCaseRepository($db,$context);
        $ids=$caseRepository->caseIdsForReference($term,$exact);

        // A syntactically valid Amazon order ID is an exact case reference. Searching
        // event JSON and invoice evidence for it cannot produce a semantic match and
        // forces table scans across the entire event history on every order lookup.
        if($kind!==self::ORDER){
            $ids=array_merge($ids,(new SvAmazonTenantReturnEventStore($db,$context))->caseIdsForReference($term,$exact));
        }

        if($kind===self::INVOICE){
            $invoiceIds=SvAmazonInvoiceSearch::caseIdsExact($db,$context,$term);
            foreach($invoiceIds as $id)if($id>0)$ids[]=$id;
        }elseif($kind===self::REFERENCE){
            $invoiceIds=SvAmazonInvoiceSearch::caseIds($db,$context,$term);
            foreach($invoiceIds as $id)if($id>0)$ids[]=$id;
        }

        $ids=array_values(array_unique($ids));
        sort($ids,SORT_NUMERIC);
        return $ids;
    }
}
