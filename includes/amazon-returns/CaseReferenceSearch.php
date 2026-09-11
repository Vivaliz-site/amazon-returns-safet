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
        $ids=array_merge(
            (new SvAmazonReturnCaseRepository($db,$context))->caseIdsForReference($term,$exact),
            (new SvAmazonTenantReturnEventStore($db,$context))->caseIdsForReference($term,$exact)
        );

        $invoiceIds=$kind===self::INVOICE
            ? SvAmazonInvoiceSearch::caseIdsExact($db,$context,$term)
            : SvAmazonInvoiceSearch::caseIds($db,$context,$term);
        foreach($invoiceIds as $id)if($id>0)$ids[]=$id;
        $ids=array_values(array_unique($ids));
        sort($ids,SORT_NUMERIC);
        return $ids;
    }
}
