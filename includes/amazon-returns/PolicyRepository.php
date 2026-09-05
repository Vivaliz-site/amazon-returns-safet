<?php

declare(strict_types=1);

require_once __DIR__ . '/TenantContext.php';

final class SvAmazonReturnPolicyRepository
{
    private const FIELDS = [
        'policy_key','marketplace_id','program','effective_from','effective_to',
        'eligibility_days','basis','source_url','source_hash','status',
    ];

    public function __construct(
        private PDO $db,
        private SvAmazonTenantContext $context
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function activeFor(string $marketplaceId, string $program): array
    {
        $marketplaceId = self::bounded($marketplaceId, 32, 'marketplace ID');
        $program = self::bounded($program, 64, 'program');
        $stmt = $this->db->prepare(
            'SELECT id,tenant_id,policy_key,marketplace_id,program,effective_from,effective_to,'
            . 'eligibility_days,basis,source_url,source_hash,status,created_at '
            . 'FROM amazon_return_policies WHERE tenant_id=:tenant_id '
            . 'AND marketplace_id=:marketplace_id AND program=:program AND status=\'ACTIVE\' '
            . 'ORDER BY effective_from DESC,id DESC'
        );
        if (!$stmt instanceof PDOStatement) {
            throw new RuntimeException('Could not prepare tenant policy lookup.');
        }
        $stmt->execute([
            ':tenant_id'=>$this->context->tenantId(),
            ':marketplace_id'=>$marketplaceId,
            ':program'=>$program,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return list<array<string,mixed>> */
    public function allActive(): array
    {
        $stmt=$this->db->prepare(
            "SELECT * FROM amazon_return_policies WHERE tenant_id=:tenant_id AND status='ACTIVE' "
            . 'ORDER BY effective_from DESC,id DESC'
        );
        if(!$stmt instanceof PDOStatement) {
            throw new RuntimeException('Could not prepare active tenant policies.');
        }
        $stmt->execute([':tenant_id'=>$this->context->tenantId()]);
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),'is_array'));
    }

    /** @param array<string,mixed> $definition */
    public function insertCandidate(array $definition): int
    {
        $definition['status']='CANDIDATE';
        $row=self::normalize($definition);
        $stmt=$this->db->prepare(
            'INSERT INTO amazon_return_policies '
            . '(tenant_id,policy_key,marketplace_id,program,effective_from,effective_to,'
            . 'eligibility_days,basis,source_url,source_hash,status,created_at) '
            . 'VALUES (:tenant_id,:policy_key,:marketplace_id,:program,:effective_from,'
            . ':effective_to,:eligibility_days,:basis,:source_url,:source_hash,:status,UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),effective_to=VALUES(effective_to),'
            . 'eligibility_days=VALUES(eligibility_days),basis=VALUES(basis),'
            . 'source_url=VALUES(source_url),source_hash=VALUES(source_hash),status=VALUES(status)'
        );
        if(!$stmt instanceof PDOStatement){
            throw new RuntimeException('Could not prepare tenant policy candidate.');
        }
        $stmt->execute([
            ':tenant_id'=>$this->context->tenantId(),
            ':policy_key'=>$row['policy_key'],
            ':marketplace_id'=>$row['marketplace_id'],
            ':program'=>$row['program'],
            ':effective_from'=>$row['effective_from'],
            ':effective_to'=>$row['effective_to'],
            ':eligibility_days'=>$row['eligibility_days'],
            ':basis'=>$row['basis'],
            ':source_url'=>$row['source_url'],
            ':source_hash'=>$row['source_hash'],
            ':status'=>'CANDIDATE',
        ]);
        $id=(int)$this->db->lastInsertId();
        if($id<1)throw new RuntimeException('Tenant policy candidate did not return an ID.');
        return $id;
    }

    /** @param list<array<string,mixed>> $definitions */
    public function seed(array $definitions): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO amazon_return_policies '
            . '(tenant_id,policy_key,marketplace_id,program,effective_from,effective_to,eligibility_days,basis,source_url,source_hash,status,created_at) '
            . 'VALUES (:tenant_id,:policy_key,:marketplace_id,:program,:effective_from,:effective_to,:eligibility_days,:basis,:source_url,:source_hash,:status,UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE effective_to=VALUES(effective_to),eligibility_days=VALUES(eligibility_days),'
            . 'basis=VALUES(basis),source_url=VALUES(source_url),source_hash=VALUES(source_hash),status=VALUES(status)'
        );
        if (!$stmt instanceof PDOStatement) {
            throw new RuntimeException('Could not prepare tenant policy seed.');
        }

        $count = 0;
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                throw new InvalidArgumentException('Policy definition must be an array.');
            }
            $row = self::normalize($definition);
            $stmt->execute([
                ':tenant_id'=>$this->context->tenantId(),
                ':policy_key'=>$row['policy_key'],
                ':marketplace_id'=>$row['marketplace_id'],
                ':program'=>$row['program'],
                ':effective_from'=>$row['effective_from'],
                ':effective_to'=>$row['effective_to'],
                ':eligibility_days'=>$row['eligibility_days'],
                ':basis'=>$row['basis'],
                ':source_url'=>$row['source_url'],
                ':source_hash'=>$row['source_hash'],
                ':status'=>$row['status'],
            ]);
            $count++;
        }
        return $count;
    }

    public static function matrixMismatchSql(int $tenantId): string
    {
        if($tenantId<1)throw new InvalidArgumentException('Positive tenant ID required.');
        $terms=[];
        foreach(SvAmazonReturnPolicySeeder::definitions() as $definition){
            $parts=[];
            foreach($definition as $field=>$value){
                if($value===null)$parts[]=$field.' IS NULL';
                elseif(is_int($value))$parts[]=$field.'='.$value;
                else $parts[]=$field."='".str_replace("'","''",$value)."'";
            }
            $terms[]='('.implode(' AND ',$parts).')';
        }
        $perProgram=array_map(static fn(string $term):string=>'COALESCE(SUM(CASE WHEN '.$term.' THEN 1 ELSE 0 END),0)=1',$terms);
        return 'SELECT CASE WHEN COUNT(*)='.count($terms).' AND '.implode(' AND ',$perProgram)
            .' AND COALESCE(SUM(CASE WHEN '.implode(' OR ',$terms)
            ." THEN 0 ELSE 1 END),0)=0 THEN 0 ELSE 1 END FROM amazon_return_policies WHERE tenant_id="
            .$tenantId." AND status='ACTIVE'";
    }

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    private static function normalize(array $definition): array
    {
        $unknown = array_diff(array_keys($definition), self::FIELDS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown tenant policy fields: ' . implode(',', $unknown));
        }
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $definition)) {
                throw new InvalidArgumentException('Tenant policy is missing field: ' . $field);
            }
        }

        $days = filter_var($definition['eligibility_days'], FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 3650) {
            throw new InvalidArgumentException('Tenant policy eligibility days are invalid.');
        }
        $sourceHash = strtolower(trim((string)$definition['source_hash']));
        if (preg_match('/^[a-f0-9]{64}$/', $sourceHash) !== 1) {
            throw new InvalidArgumentException('Tenant policy source hash is invalid.');
        }
        $status = strtoupper(self::bounded((string)$definition['status'], 24, 'status'));
        if (preg_match('/^[A-Z_]+$/', $status) !== 1) {
            throw new InvalidArgumentException('Tenant policy status is invalid.');
        }

        return [
            'policy_key'=>self::bounded((string)$definition['policy_key'], 96, 'policy key'),
            'marketplace_id'=>self::bounded((string)$definition['marketplace_id'], 32, 'marketplace ID'),
            'program'=>self::bounded((string)$definition['program'], 64, 'program'),
            'effective_from'=>self::date((string)$definition['effective_from'], false),
            'effective_to'=>self::date($definition['effective_to'], true),
            'eligibility_days'=>$days,
            'basis'=>self::bounded((string)$definition['basis'], 32, 'basis'),
            'source_url'=>self::bounded((string)$definition['source_url'], 8192, 'source URL'),
            'source_hash'=>$sourceHash,
            'status'=>$status,
        ];
    }

    private static function bounded(string $value, int $max, string $label): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $max) {
            throw new InvalidArgumentException('Tenant policy ' . $label . ' is invalid.');
        }
        return $value;
    }

    private static function date(mixed $value, bool $nullable): ?string
    {
        if ($nullable && ($value === null || $value === '')) return null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Tenant policy date is invalid.');
        }
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Tenant policy date is invalid.');
        }
        return $value;
    }
}
