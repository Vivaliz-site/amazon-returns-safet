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

    /** @param array<string,mixed> $lookup @return array<string,mixed> */
    public static function evidenceEvent(int $caseId,array $lookup,?DateTimeImmutable $occurredAt=null): array
    {
        if($caseId<1)throw new InvalidArgumentException('Invoice evidence case ID must be positive.');
        $invoiceNumber=trim((string)($lookup['invoice_number'] ?? ''));
        $orderId=trim((string)($lookup['order_id'] ?? ''));
        if(preg_match('/^[0-9]{1,20}$/D',$invoiceNumber)!==1){
            throw new InvalidArgumentException('Invoice evidence number is invalid.');
        }
        if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/D',$orderId)!==1){
            throw new InvalidArgumentException('Invoice evidence order ID is invalid.');
        }
        $source=trim((string)($lookup['source'] ?? 'SP_API_INVOICES'));
        if(!in_array($source,['SP_API_INVOICES','ERP_OLIST_INVOICE'],true)){
            throw new InvalidArgumentException('Invoice evidence source is invalid.');
        }
        $invoiceId=trim((string)($lookup['invoice_id'] ?? ''));
        $requestId=trim((string)($lookup['request_id'] ?? ''));
        $at=($occurredAt ?? new DateTimeImmutable('now',new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        return [
            'case_id'=>$caseId,
            'event_type'=>'SALES_INVOICE_LINKED',
            'source'=>$source,
            'source_event_id'=>$invoiceId!=='' ? $invoiceId : ($requestId!=='' ? $requestId : null),
            'idempotency_key'=>hash('sha256',implode('|',[
                'sales-invoice-linked',(string)$caseId,$invoiceNumber,$orderId,
            ])),
            'occurred_at'=>$at->format('Y-m-d H:i:s'),
            'payload'=>[
                'invoice_number'=>$invoiceNumber,
                'order_id'=>$orderId,
                'invoice_id'=>$invoiceId!=='' ? $invoiceId : null,
                'series'=>self::nullable($lookup['series'] ?? null),
                'status'=>self::nullable($lookup['status'] ?? null),
                'invoice_type'=>self::nullable($lookup['invoice_type'] ?? null),
                'transaction_type'=>self::nullable($lookup['transaction_type'] ?? null),
                'request_id'=>$requestId!=='' ? $requestId : null,
                'sales_channel'=>self::nullable($lookup['sales_channel'] ?? null),
            ],
            'evidence_sha256'=>null,
        ];
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

    private static function nullable(mixed $value): ?string
    {
        if(!is_scalar($value))return null;
        $value=trim((string)$value);
        return $value==='' ? null : $value;
    }
}
