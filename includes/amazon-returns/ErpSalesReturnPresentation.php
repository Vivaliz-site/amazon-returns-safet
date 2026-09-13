<?php
declare(strict_types=1);

final class SvAmazonErpSalesReturnPresentation
{
    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    public static function project(?array $row): ?array
    {
        if($row===null)return null;
        $status=strtoupper(trim((string)($row['status']??'')));
        $labels=[
            'PENDING'=>'Devolução no ERP em preparação',
            'READY_TO_CREATE'=>'Devolução no ERP pronta para criar',
            'RETURN_CREATED_WAITING_INVOICE'=>'Devolução criada no ERP — aguardando NF de devolução',
            'RETURN_INVOICE_EXISTS'=>'NF de devolução já emitida',
            'BLOCKED'=>'Não foi possível criar a devolução automaticamente',
        ];
        return [
            'status'=>$status!==''?$status:'PENDING',
            'label'=>$labels[$status]??$labels['PENDING'],
            'erp_sales_return_id'=>self::nullable($row['erp_sales_return_id']??null),
            'invoice_number'=>self::nullable($row['return_invoice_number']??null),
            'invoice_status'=>self::nullable($row['return_invoice_status']??null),
            'issued_at'=>self::nullable($row['return_invoice_issued_at']??null),
            'last_checked_at'=>self::nullable($row['last_checked_at']??null),
            'message'=>$status==='BLOCKED'?self::nullable($row['last_error_message']??null):null,
        ];
    }

    private static function nullable(mixed $value): ?string
    {
        if($value===null || !is_scalar($value))return null;
        $value=trim((string)$value);
        return $value===''?null:$value;
    }
}
