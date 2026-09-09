<?php
declare(strict_types=1);

require_once __DIR__.'/TenantContext.php';

final class SvAmazonInvoiceSearch
{
    /** @return list<int> */
    public static function caseIds(PDO $db,SvAmazonTenantContext $context,string $term): array
    {
        $term=trim($term);
        return $term==='' ? [] : self::query($db,$context,'%'.$term.'%');
    }

    /** @return list<int> */
    public static function caseIdsExact(PDO $db,SvAmazonTenantContext $context,string $invoiceNumber): array
    {
        $invoiceNumber=trim($invoiceNumber);
        return $invoiceNumber==='' ? [] : self::query($db,$context,$invoiceNumber);
    }

    /** @return list<int> */
    private static function query(PDO $db,SvAmazonTenantContext $context,string $pattern): array
    {
        $stmt=$db->prepare(
            "SELECT DISTINCT case_id FROM amazon_return_events "
            ."WHERE tenant_id=:invoice_tenant_id AND amazon_connection_id=:invoice_connection_id "
            ."AND ("
            ."JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.invoice_number')) LIKE :q_invoice "
            ."OR JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.sales_invoice_number')) LIKE :q_invoice_legacy"
            .") ORDER BY case_id LIMIT 1000"
        );
        $stmt->execute([
            ':invoice_tenant_id'=>$context->tenantId(),
            ':invoice_connection_id'=>$context->amazonConnectionId(),
            ':q_invoice'=>$pattern,
            ':q_invoice_legacy'=>$pattern,
        ]);
        return array_values(array_unique(array_filter(
            array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)),
            static fn(int $id): bool => $id>0
        )));
    }
}
