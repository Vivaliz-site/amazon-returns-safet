<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantContext.php';

final class SvAmazonReturnEvidenceStore
{
    private const FIELDS = [
        'kind','source','external_id','content_sha256','storage_ref','metadata','captured_at',
    ];

    private const SECRET_KEYS = [
        'access_token','refresh_token','client_secret','password','cookie','authorization','mfa','otp',
    ];

    public function __construct(
        private PDO $db,
        private SvAmazonTenantContext $context
    ) {}

    /** @param array<string,mixed> $evidence */
    public function record(int $caseId, array $evidence): int
    {
        $caseId = $this->positiveId($caseId, 'case ID');
        $normalized = $this->normalize($evidence);
        $this->assertOwnedCase($caseId);
        $params = $this->scopeParams([':case_id'=>$caseId] + $normalized);
        $stmt = $this->prepare(
            'INSERT INTO amazon_return_evidence '
            . '(tenant_id,amazon_connection_id,case_id,kind,source,external_id,content_sha256,'
            . 'storage_ref,metadata_json,captured_at,created_at) '
            . 'VALUES (:tenant_id,:amazon_connection_id,:case_id,:kind,:source,:external_id,:content_sha256,'
            . ':storage_ref,:metadata_json,:captured_at,UTC_TIMESTAMP())'
        );
        try {
            $stmt->execute($params);
            $id = (int)$this->db->lastInsertId();
            if ($id < 1) throw new RuntimeException('Scoped evidence insert did not return an ID.');
            return $id;
        } catch (PDOException $exception) {
            if (!$this->isUniqueViolation($exception)) throw $exception;
        }

        $existing = $this->prepare(
            'SELECT id FROM amazon_return_evidence WHERE tenant_id=:tenant_id '
            . 'AND amazon_connection_id=:amazon_connection_id AND case_id=:case_id '
            . 'AND kind=:kind AND content_sha256=:content_sha256 LIMIT 1'
        );
        $existing->execute($this->scopeParams([
            ':case_id'=>$caseId,
            ':kind'=>$normalized[':kind'],
            ':content_sha256'=>$normalized[':content_sha256'],
        ]));
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        $id = is_array($row) ? (int)($row['id'] ?? 0) : 0;
        if ($id < 1) throw new RuntimeException('Scoped duplicate evidence could not be resolved.');
        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function forCase(int $caseId): array
    {
        $caseId = $this->positiveId($caseId, 'case ID');
        $this->assertOwnedCase($caseId);
        $stmt = $this->prepare(
            'SELECT id,tenant_id,amazon_connection_id,case_id,kind,source,external_id,content_sha256,'
            . 'storage_ref,metadata_json,captured_at,created_at FROM amazon_return_evidence '
            . 'WHERE tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id '
            . 'AND case_id=:case_id ORDER BY captured_at,id'
        );
        $stmt->execute($this->scopeParams([':case_id'=>$caseId]));
        $rows = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), 'is_array'));
        foreach ($rows as &$row) {
            $json = $row['metadata_json'] ?? null;
            if (!is_string($json)) throw new UnexpectedValueException('Evidence metadata is not JSON text.');
            $metadata = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($metadata)) throw new UnexpectedValueException('Evidence metadata must decode to an array.');
            $row['metadata'] = $metadata;
            unset($row['metadata_json']);
        }
        unset($row);
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function projectionForCase(int $caseId): array
    {
        $allowed=array_flip([
            'id','case_id','kind','source','external_id','content_sha256',
            'storage_ref','metadata','captured_at','created_at',
        ]);
        return array_map(
            static fn(array $row):array=>array_intersect_key($row,$allowed),
            $this->forCase($caseId)
        );
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    private function normalize(array $evidence): array
    {
        foreach (array_keys($evidence) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                throw new InvalidArgumentException('Evidence field is not allowed: ' . $field);
            }
        }
        foreach (['kind','source','content_sha256','captured_at'] as $required) {
            if (!array_key_exists($required, $evidence)) {
                throw new InvalidArgumentException('Evidence requires ' . $required . '.');
            }
        }
        $metadata = $evidence['metadata'] ?? [];
        if (!is_array($metadata)) throw new InvalidArgumentException('Evidence metadata must be an array.');
        $this->assertNoSecrets($metadata);
        $sha = strtolower($this->bounded($evidence['content_sha256'], 'content_sha256', 64));
        if (preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            throw new InvalidArgumentException('Evidence content_sha256 must be a SHA-256 digest.');
        }
        return [
            ':kind'=>$this->bounded($evidence['kind'], 'kind', 64),
            ':source'=>$this->bounded($evidence['source'], 'source', 32),
            ':external_id'=>$this->nullableBounded($evidence['external_id'] ?? null, 'external_id', 191),
            ':content_sha256'=>$sha,
            ':storage_ref'=>$this->nullableBounded($evidence['storage_ref'] ?? null, 'storage_ref', 512),
            ':metadata_json'=>json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':captured_at'=>$this->utcDate($evidence['captured_at'], 'captured_at'),
        ];
    }

    private function assertOwnedCase(int $caseId): void
    {
        $stmt = $this->prepare(
            'SELECT id FROM amazon_return_cases WHERE id=:case_id AND tenant_id=:tenant_id '
            . 'AND amazon_connection_id=:amazon_connection_id LIMIT 1'
        );
        $stmt->execute($this->scopeParams([':case_id'=>$caseId]));
        if (!is_array($stmt->fetch(PDO::FETCH_ASSOC))) {
            throw new RuntimeException('Evidence case is not owned by the current tenant connection.');
        }
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function scopeParams(array $extra = []): array
    {
        return $extra + [
            ':tenant_id'=>$this->context->tenantId(),
            ':amazon_connection_id'=>$this->context->amazonConnectionId(),
        ];
    }

    private function prepare(string $sql): PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt instanceof PDOStatement) throw new RuntimeException('Could not prepare scoped evidence statement.');
        return $stmt;
    }
    private function positiveId(int $value, string $label): int
    {
        if ($value < 1) throw new InvalidArgumentException($label . ' must be positive.');
        return $value;
    }

    private function bounded(mixed $value, string $label, int $maxLength): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $maxLength || str_contains($value, "\0")) {
            throw new InvalidArgumentException($label . ' must contain 1-' . $maxLength . ' safe bytes.');
        }
        return $value;
    }

    private function nullableBounded(mixed $value, string $label, int $maxLength): ?string
    {
        if ($value === null) return null;
        return $this->bounded($value, $label, $maxLength);
    }

    private function utcDate(mixed $value, string $label): string
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if (!is_string($value)) throw new InvalidArgumentException($label . ' must be a UTC datetime.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException($label . ' must use Y-m-d H:i:s UTC format.');
        }
        return $value;
    }
    /** @param array<string,mixed> $metadata */
    private function assertNoSecrets(array $metadata): void
    {
        $walk = function(array $values) use (&$walk): void {
            foreach ($values as $key=>$value) {
                $withWordBreaks = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', (string)$key) ?? (string)$key;
                $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $withWordBreaks) ?? '');
                foreach (self::SECRET_KEYS as $secret) {
                    if ($normalized === $secret || str_ends_with($normalized, '_' . $secret)) {
                        throw new InvalidArgumentException('Evidence metadata contains a forbidden secret field.');
                    }
                }
                if (is_array($value)) $walk($value);
            }
        };
        $walk($metadata);
    }

    private function isUniqueViolation(PDOException $exception): bool
    {
        $driverCode = $exception->errorInfo[1] ?? null;
        return (string)$exception->getCode() === '23000' && ($driverCode === null || (int)$driverCode === 1062);
    }
}
