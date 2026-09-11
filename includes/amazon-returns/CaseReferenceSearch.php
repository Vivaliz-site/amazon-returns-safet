<?php
declare(strict_types=1);

require_once __DIR__.'/TenantContext.php';
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
        $pattern=$exact?$term:'%'.self::escapeLike($term).'%';
        $arrayPattern=$exact?$term:'%'.self::escapeLike($term).'%';
        $arrayMatch=static fn(string $path,string $placeholder):string=>$exact
            ? "JSON_CONTAINS(JSON_EXTRACT(payload_json,'$.{$path}'),JSON_QUOTE({$placeholder}))"
            : "JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.{$path}')) LIKE {$placeholder}";
        $ids=[];
        $caseStmt=$db->prepare(
            'SELECT id FROM amazon_return_cases WHERE tenant_id=:case_tenant_id '
            .'AND amazon_connection_id=:case_connection_id AND ('
            .'amazon_order_id LIKE :case_order OR safe_t_id LIKE :case_safe_t '
            .'OR sku LIKE :case_sku OR asin LIKE :case_asin) ORDER BY id LIMIT 1000'
        );
        $caseStmt->execute([
            ':case_tenant_id'=>$context->tenantId(),
            ':case_connection_id'=>$context->amazonConnectionId(),
            ':case_order'=>$pattern,':case_safe_t'=>$pattern,':case_sku'=>$pattern,':case_asin'=>$pattern,
        ]);
        foreach($caseStmt->fetchAll(PDO::FETCH_COLUMN) as $id)if((int)$id>0)$ids[]=(int)$id;

        $eventStmt=$db->prepare(
            "SELECT DISTINCT case_id FROM amazon_return_events WHERE tenant_id=:event_tenant_id "
            ."AND amazon_connection_id=:event_connection_id AND ("
            ."JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.return_tracking_id')) LIKE :event_return_tracking_id "
            ."OR ".$arrayMatch('return_tracking_ids',':event_return_tracking_ids')." "
            ."OR ".$arrayMatch('customer_tracking_ids',':event_customer_tracking_ids')." "
            ."OR JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.tracking_id')) LIKE :event_tracking_id "
            ."OR ".$arrayMatch('tracking_ids',':event_tracking_ids')." "
            ."OR JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.invoice_number')) LIKE :event_invoice_number "
            ."OR JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.sales_invoice_number')) LIKE :event_sales_invoice_number) "
            ."ORDER BY case_id LIMIT 1000"
        );
        $eventStmt->execute([
            ':event_tenant_id'=>$context->tenantId(),
            ':event_connection_id'=>$context->amazonConnectionId(),
            ':event_return_tracking_id'=>$pattern,
            ':event_return_tracking_ids'=>$arrayPattern,
            ':event_customer_tracking_ids'=>$arrayPattern,
            ':event_tracking_id'=>$pattern,
            ':event_tracking_ids'=>$arrayPattern,
            ':event_invoice_number'=>$pattern,
            ':event_sales_invoice_number'=>$pattern,
        ]);
        foreach($eventStmt->fetchAll(PDO::FETCH_COLUMN) as $id)if((int)$id>0)$ids[]=(int)$id;

        $invoiceIds=$kind===self::INVOICE
            ? SvAmazonInvoiceSearch::caseIdsExact($db,$context,$term)
            : SvAmazonInvoiceSearch::caseIds($db,$context,$term);
        foreach($invoiceIds as $id)if($id>0)$ids[]=$id;
        $ids=array_values(array_unique($ids));
        sort($ids,SORT_NUMERIC);
        return $ids;
    }

    private static function escapeLike(string $value): string
    {
        return strtr($value,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']);
    }
}
