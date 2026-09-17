<?php
declare(strict_types=1);

final class SvAmazonErpSalesReturnCanary
{
    /** @param array<string,mixed> $workflow @param list<array<string,mixed>> $cases @param array<string,mixed>|null $sale @param array<string,mixed>|null $returnInvoice @return array<string,mixed> */
    public static function evaluate(array $workflow,array $cases,?array $sale,?array $returnInvoice): array
    {
        if(strtoupper(trim((string)($workflow['status']??'')))!=='READY_TO_CREATE')return self::no('WORKFLOW_NOT_READY');
        $orderId=trim((string)($workflow['amazon_order_id']??''));
        if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/D',$orderId)!==1)return self::no('ORDER_ID_INVALID');
        $invoiceId=trim((string)($workflow['original_invoice_id']??''));
        if(preg_match('/^[0-9]+$/D',$invoiceId)!==1)return self::no('ORIGINAL_INVOICE_ID_INVALID');
        $refunded=array_values(array_filter($cases,static fn(mixed $row):bool=>is_array($row)&&(int)($row['quantity_refunded']??0)>0));
        if(count($refunded)!==1)return self::no('REFUNDED_CASE_COUNT_NOT_ONE');
        $case=$refunded[0];
        $caseId=(int)($case['id']??0);$sku=trim((string)($case['sku']??''));$quantity=(int)($case['quantity_refunded']??0);
        $caseOrder=trim((string)($case['amazon_order_id']??''));$refundAt=trim((string)($case['refund_at']??''));
        if($caseId<1 || $caseOrder!==$orderId || $sku==='' || $quantity<1 || self::dateOnly($refundAt)===null)return self::no('REFUNDED_CASE_INVALID');
        if($returnInvoice!==null)return self::no('RETURN_INVOICE_ALREADY_EXISTS');
        if(!is_array($sale))return self::no('ORIGINAL_SALE_NOT_FOUND');
        if(trim((string)($sale['order_id']??''))!==$orderId)return self::no('ORIGINAL_SALE_ORDER_MISMATCH');
        if(trim((string)($sale['invoice_id']??''))!==$invoiceId)return self::no('ORIGINAL_SALE_INVOICE_MISMATCH');
        return [
            'eligible'=>true,'reason'=>'READY','case_id'=>$caseId,'order_id'=>$orderId,
            'original_invoice_id'=>$invoiceId,'original_invoice_number'=>trim((string)($sale['invoice_number']??'')),
            'refund_at'=>self::dateOnly($refundAt),'items'=>[['sku'=>$sku,'quantity_refunded'=>$quantity]],
        ];
    }

    public static function exactWriteScope(string $raw,int $caseId): bool
    {
        $raw=trim($raw);
        return $caseId>0 && preg_match('/^[1-9][0-9]*$/D',$raw)===1 && (int)$raw===$caseId;
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $candidate */
    public static function verifyExternalReadBack(array $record,array $candidate): bool
    {
        $id=trim((string)($record['id']??''));
        if(preg_match('/^[0-9]+$/D',$id)!==1)return false;
        $expectedId=trim((string)($candidate['erp_sales_return_id']??''));
        if($expectedId!=='' && $id!==$expectedId)return false;
        if(trim((string)($record['idNotaFiscal']??''))!==trim((string)($candidate['original_invoice_id']??'')))return false;
        $expected=self::itemMap($candidate['items']??null);$actual=self::itemMap($record['itens']??null);
        return $expected!==null && $actual!==null && $expected===$actual;
    }

    /** @return array{eligible:false,reason:string} */
    private static function no(string $reason): array { return ['eligible'=>false,'reason'=>$reason]; }
    private static function dateOnly(string $value): ?string
    {
        $date=substr($value,0,10);
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d')===$date?$date:null;
    }
    /** @return array<string,int>|null */
    private static function itemMap(mixed $items): ?array
    {
        if(!is_array($items)||$items===[])return null;$map=[];
        foreach($items as $item){if(!is_array($item))return null;$sku=trim((string)($item['codigo']??$item['sku']??''));$qty=(int)($item['quantidade']??$item['quantity_refunded']??0);if($sku===''||$qty<1)return null;$map[$sku]=($map[$sku]??0)+$qty;}
        ksort($map,SORT_STRING);return $map;
    }
}
