<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantContext.php';
require_once __DIR__ . '/PolicyMatrix.php';

final class SvAmazonReturnsShadowAuditRepository
{
    private bool $tenantSchema;

    public function __construct(
        private PDO $db,
        private ?SvAmazonTenantContext $context
    ) {
        $this->tenantSchema=self::hasTenantColumns($db);
        if($this->tenantSchema && !$context instanceof SvAmazonTenantContext){
            throw new RuntimeException('Tenant-owned shadow source requires an explicit tenant connection.');
        }
        if(!$this->tenantSchema && $context instanceof SvAmazonTenantContext){
            throw new RuntimeException('Legacy shadow source cannot accept tenant ownership.');
        }
    }

    public static function hasTenantColumns(PDO $db): bool
    {
        $stmt=$db->prepare(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() "
            . "AND table_name='amazon_return_cases' AND column_name='tenant_id'"
        );
        if(!$stmt instanceof PDOStatement){
            throw new RuntimeException('Could not inspect shadow schema.');
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn()>0;
    }

    /** @return array<string,array<string,mixed>> */
    public function openCases(): array
    {
        $sql='SELECT * FROM amazon_return_cases WHERE closed_at IS NULL';
        $params=[];
        if($this->tenantSchema){
            $sql.=' AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id';
            $params=$this->scopeParams();
        }
        $sql.=' ORDER BY id';
        $stmt=$this->prepare($sql);
        $stmt->execute($params);
        $out=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
            if(!is_array($row))continue;
            $out[self::caseKey($row)]=$row;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function activePolicies(): array
    {
        $sql="SELECT * FROM amazon_return_policies WHERE status='ACTIVE'";
        $params=[];
        if($this->tenantSchema){
            $sql.=' AND tenant_id=:tenant_id';
            $params=[':tenant_id'=>$this->context->tenantId()];
        }
        $sql.=' ORDER BY effective_from DESC,id DESC';
        $stmt=$this->prepare($sql);
        $stmt->execute($params);
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),'is_array'));
    }

    /** @return list<array<string,mixed>> */
    public function eventsForCase(int $caseId): array
    {
        if($caseId<1)throw new InvalidArgumentException('Shadow case ID must be positive.');
        $sql='SELECT * FROM amazon_return_events WHERE case_id=:case_id';
        $params=[':case_id'=>$caseId];
        if($this->tenantSchema){
            $sql.=' AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id';
            $params+=$this->scopeParams();
        }
        $sql.=' ORDER BY occurred_at,id';
        $stmt=$this->prepare($sql);
        $stmt->execute($params);
        $rows=array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),'is_array'));
        foreach($rows as &$row){
            $raw=$row['payload_json'] ?? null;
            if(!is_string($raw))throw new UnexpectedValueException('Shadow event payload is not JSON text.');
            $decoded=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($decoded))throw new UnexpectedValueException('Shadow event payload must decode to an array.');
            $row['payload']=$decoded;
            unset($row['payload_json']);
        }
        unset($row);
        return $rows;
    }

    public function policyMatrixViolationCount(): int
    {
        return count(SvAmazonReturnPolicyMatrix::violations($this->activePolicies()));
    }

    /** @return array<string,int|string> */
    public function identity(): array
    {
        if(!$this->tenantSchema)return ['mode'=>'legacy'];
        return [
            'mode'=>'tenant',
            'tenant_id'=>$this->context->tenantId(),
            'amazon_connection_id'=>$this->context->amazonConnectionId(),
        ];
    }

    /** @param array<string,mixed> $row */
    public static function caseKey(array $row): string
    {
        $order=trim((string)($row['amazon_order_id'] ?? ''));
        $item=trim((string)($row['amazon_order_item_id'] ?? ''));
        if($order==='' || $item==='')throw new UnexpectedValueException('Shadow case lacks external identity.');
        return $order.'|'.$item;
    }

    /** @return array<string,int> */
    private function scopeParams(): array
    {
        if(!$this->context instanceof SvAmazonTenantContext)return [];
        return [
            ':tenant_id'=>$this->context->tenantId(),
            ':amazon_connection_id'=>$this->context->amazonConnectionId(),
        ];
    }

    private function prepare(string $sql): PDOStatement
    {
        $stmt=$this->db->prepare($sql);
        if(!$stmt instanceof PDOStatement)throw new RuntimeException('Could not prepare shadow audit query.');
        return $stmt;
    }
}
