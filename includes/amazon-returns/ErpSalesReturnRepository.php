<?php
declare(strict_types=1);

require_once __DIR__.'/TenantContext.php';

interface SvAmazonErpSalesReturnStore
{
    /** @return array<string,mixed>|null */
    public function findByOrder(string $orderId): ?array;
    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function ensureWorkflow(array $data): array;
    /** @return array<string,mixed> */
    public function markReady(string $orderId): array;
    /** @return array<string,mixed> */
    public function markReturnCreated(string $orderId,string $erpSalesReturnId): array;
    /** @param array<string,mixed> $invoice @return array<string,mixed> */
    public function linkReturnInvoice(string $orderId,array $invoice): array;
    /** @return array<string,mixed> */
    public function markBlocked(string $orderId,string $code,string $message): array;
    /** Records the total refunded quantity actually covered by the ERP return/invoice so far. */
    public function recordReconciledQuantity(string $orderId,int $quantity): array;
}

final class SvAmazonErpSalesReturnRepository implements SvAmazonErpSalesReturnStore
{
    private const TABLE='amazon_return_erp_sales_returns';

    public function __construct(private PDO $db,private SvAmazonTenantContext $context) {}

    /** @return array<string,mixed>|null */
    public function findByOrder(string $orderId): ?array
    {
        $orderId=self::orderId($orderId);
        $stmt=$this->sql(
            'SELECT * FROM '.self::TABLE.' WHERE tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id AND amazon_order_id=:amazon_order_id LIMIT 1',
            [':amazon_order_id'=>$orderId]
        );
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    public function countIncomplete(): int
    {
        $stmt=$this->sql(
            "SELECT COUNT(*) FROM ".self::TABLE." WHERE tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id AND status NOT IN ('RETURN_CREATED_WAITING_INVOICE','RETURN_INVOICE_EXISTS')"
        );
        return max(0,(int)$stmt->fetchColumn());
    }

    /** @return list<array{amazon_order_id:string,status:string,last_error_code:?string}> */
    public function incompleteAuditRows(): array
    {
        $stmt=$this->sql(
            "SELECT amazon_order_id,status,last_error_code FROM ".self::TABLE." WHERE tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id AND status NOT IN ('RETURN_CREATED_WAITING_INVOICE','RETURN_INVOICE_EXISTS') ORDER BY amazon_order_id"
        );
        $rows=[];
        while($row=$stmt->fetch(PDO::FETCH_ASSOC)){
            if(!is_array($row))continue;
            $rows[]=[
                'amazon_order_id'=>(string)($row['amazon_order_id']??''),
                'status'=>strtoupper(trim((string)($row['status']??''))),
                'last_error_code'=>($row['last_error_code']??null)!==null
                    ? strtoupper(trim((string)$row['last_error_code']))
                    : null,
            ];
        }
        return $rows;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function ensureWorkflow(array $data): array
    {
        $orderId=self::orderId((string)($data['amazon_order_id']??''));
        $idempotency=self::idempotencyKey($data['idempotency_key']??null,$this->context,$orderId);
        $params=[
            ':amazon_order_id'=>$orderId,
            ':original_invoice_id'=>self::nullable($data['original_invoice_id']??null,64),
            ':original_invoice_number'=>self::nullable($data['original_invoice_number']??null,64),
            ':original_invoice_key'=>self::nullable($data['original_invoice_key']??null,64),
            ':idempotency_key'=>$idempotency,
            ':last_checked_at'=>self::now(),
        ];
        $this->sql(
            'INSERT INTO '.self::TABLE.' (tenant_id,amazon_connection_id,amazon_order_id,original_invoice_id,original_invoice_number,original_invoice_key,status,idempotency_key,last_checked_at) '.
            'VALUES (:tenant_id,:amazon_connection_id,:amazon_order_id,:original_invoice_id,:original_invoice_number,:original_invoice_key,\'PENDING\',:idempotency_key,:last_checked_at) '.
            'ON DUPLICATE KEY UPDATE '.
            'original_invoice_id=COALESCE(original_invoice_id,VALUES(original_invoice_id)), '.
            'original_invoice_number=COALESCE(original_invoice_number,VALUES(original_invoice_number)), '.
            'original_invoice_key=COALESCE(original_invoice_key,VALUES(original_invoice_key)), '.
            'last_checked_at=VALUES(last_checked_at), updated_at=CURRENT_TIMESTAMP',
            $params
        );
        return $this->requireByOrder($orderId);
    }

    /** @return array<string,mixed> */
    public function markReady(string $orderId): array
    {
        return $this->transition($orderId,[
            'status'=>'READY_TO_CREATE',
            'last_checked_at'=>self::now(),
            'last_error_code'=>null,
            'last_error_message'=>null,
        ]);
    }

    /** @return array<string,mixed> */
    public function markReturnCreated(string $orderId,string $erpSalesReturnId): array
    {
        $erpSalesReturnId=self::required($erpSalesReturnId,64,'ERP sales return ID');
        return $this->transition($orderId,[
            'status'=>'RETURN_CREATED_WAITING_INVOICE',
            'erp_sales_return_id'=>$erpSalesReturnId,
            'created_in_erp_at'=>self::now(),
            'last_checked_at'=>self::now(),
            'last_error_code'=>null,
            'last_error_message'=>null,
        ]);
    }

    /** @param array<string,mixed> $invoice @return array<string,mixed> */
    public function linkReturnInvoice(string $orderId,array $invoice): array
    {
        $invoiceId=self::required((string)($invoice['invoice_id']??''),64,'ERP return invoice ID');
        return $this->transition($orderId,[
            'status'=>'RETURN_INVOICE_EXISTS',
            'return_invoice_id'=>$invoiceId,
            'return_invoice_number'=>self::nullable($invoice['invoice_number']??null,64),
            'return_invoice_key'=>self::nullable($invoice['access_key']??null,64),
            'return_invoice_status'=>self::nullable($invoice['status']??null,64),
            'return_invoice_issued_at'=>self::mysqlDate($invoice['issued_at']??null),
            'last_checked_at'=>self::now(),
            'last_error_code'=>null,
            'last_error_message'=>null,
        ]);
    }

    /** @return array<string,mixed> */
    public function markBlocked(string $orderId,string $code,string $message): array
    {
        return $this->transition($orderId,[
            'status'=>'BLOCKED',
            'last_checked_at'=>self::now(),
            'last_error_code'=>self::required($code,96,'ERP return error code'),
            'last_error_message'=>self::required($message,512,'ERP return error message'),
        ]);
    }

    /** Records the total refunded quantity actually covered by the ERP return/invoice so far. */
    public function recordReconciledQuantity(string $orderId,int $quantity): array
    {
        if($quantity<0)throw new InvalidArgumentException('Reconciled refunded quantity cannot be negative.');
        return $this->transition($orderId,[
            'reconciled_quantity_refunded'=>$quantity,
            'last_checked_at'=>self::now(),
        ]);
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private function transition(string $orderId,array $values): array
    {
        $orderId=self::orderId($orderId);
        $sets=[];
        $params=[':amazon_order_id'=>$orderId];
        foreach($values as $field=>$value){
            if(!in_array($field,['status','erp_sales_return_id','return_invoice_id','return_invoice_number','return_invoice_key','return_invoice_status','return_invoice_issued_at','reconciled_quantity_refunded','last_checked_at','created_in_erp_at','last_error_code','last_error_message'],true)){
                throw new InvalidArgumentException('Unsupported ERP sales return field.');
            }
            $key=':set_'.$field;
            $sets[]=$field.'='.$key;
            $params[$key]=$value;
        }
        $this->sql(
            'UPDATE '.self::TABLE.' SET '.implode(',',$sets).', updated_at=CURRENT_TIMESTAMP '.
            'WHERE tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id AND amazon_order_id=:amazon_order_id',
            $params
        );
        return $this->requireByOrder($orderId);
    }

    /** @return array<string,mixed> */
    private function requireByOrder(string $orderId): array
    {
        $row=$this->findByOrder($orderId);
        if($row===null)throw new RuntimeException('ERP sales return workflow was not persisted.');
        return $row;
    }

    private function sql(string $sql,array $params=[]): PDOStatement
    {
        $stmt=$this->db->prepare($sql);
        if(!$stmt || !$stmt->execute($params+[
            ':tenant_id'=>$this->context->tenantId(),
            ':amazon_connection_id'=>$this->context->amazonConnectionId(),
        ])){
            throw new RuntimeException('ERP sales return persistence failed.');
        }
        return $stmt;
    }

    private static function orderId(string $value): string
    {
        $value=trim($value);
        if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/D',$value)!==1)throw new InvalidArgumentException('Amazon order ID is invalid.');
        return $value;
    }

    private static function idempotencyKey(mixed $value,SvAmazonTenantContext $context,string $orderId): string
    {
        if(is_string($value) && preg_match('/^[a-f0-9]{64}$/D',$value)===1)return $value;
        return hash('sha256',$context->scopeKey().'|erp-sales-return|'.$orderId);
    }

    private static function nullable(mixed $value,int $max): ?string
    {
        if($value===null || !is_scalar($value))return null;
        $value=trim((string)$value);
        if($value==='')return null;
        if(strlen($value)>$max)throw new InvalidArgumentException('ERP sales return value is too long.');
        return $value;
    }

    private static function required(string $value,int $max,string $label): string
    {
        $value=trim($value);
        if($value==='' || strlen($value)>$max)throw new InvalidArgumentException($label.' is invalid.');
        return $value;
    }

    private static function mysqlDate(mixed $value): ?string
    {
        $value=self::nullable($value,64);
        if($value===null)return null;
        try{return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
        catch(Throwable){throw new InvalidArgumentException('ERP return invoice date is invalid.');}
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }
}
