<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantContext.php';

final class SvAmazonTenantReturnEventStore
{
    private const FIELDS = [
        'case_id','event_type','source','source_event_id','idempotency_key','occurred_at','payload','evidence_sha256',
    ];

    public function __construct(
        private PDO $db,
        private SvAmazonTenantContext $context
    ) {}

    /** @param array<string,mixed> $event */
    public function append(array $event): int
    {
        $normalized = $this->normalizeEvent($event);
        $this->assertOwnedCase((int)$normalized[':case_id']);
        $stmt = $this->prepare(
            'INSERT INTO amazon_return_events '
            . '(tenant_id,amazon_connection_id,case_id,event_type,source,source_event_id,'
            . 'idempotency_key,occurred_at,payload_json,evidence_sha256,created_at) '
            . 'VALUES (:tenant_id,:amazon_connection_id,:case_id,:event_type,:source,:source_event_id,'
            . ':idempotency_key,:occurred_at,:payload_json,:evidence_sha256,:created_at)'
        );
        try {
            $stmt->execute($this->scopeParams($normalized));
            $id = (int)$this->db->lastInsertId();
            if ($id < 1) throw new RuntimeException('Scoped event insert did not return an ID.');
            return $id;
        } catch (PDOException $exception) {
            if (!$this->isUniqueViolation($exception)) throw $exception;
        }
        $existing = $this->prepare(
            'SELECT id FROM amazon_return_events WHERE tenant_id=:tenant_id '
            . 'AND amazon_connection_id=:amazon_connection_id '
            . 'AND idempotency_key=:idempotency_key LIMIT 1'
        );
        $existing->execute($this->scopeParams([':idempotency_key'=>$normalized[':idempotency_key']]));
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        $id = is_array($row) ? (int)($row['id'] ?? 0) : 0;
        if ($id < 1) throw new RuntimeException('Scoped duplicate event could not be resolved.');
        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function eventsForCase(int $caseId): array
    {
        $caseId = $this->positiveId($caseId, 'case ID');
        $this->assertOwnedCase($caseId);
        $stmt = $this->prepare(
            'SELECT id,tenant_id,amazon_connection_id,case_id,event_type,source,source_event_id,'
            . 'idempotency_key,occurred_at,payload_json,evidence_sha256,created_at '
            . 'FROM amazon_return_events WHERE tenant_id=:tenant_id '
            . 'AND amazon_connection_id=:amazon_connection_id AND case_id=:case_id '
            . 'ORDER BY occurred_at,id'
        );
        $stmt->execute($this->scopeParams([':case_id'=>$caseId]));
        $rows = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), 'is_array'));
        foreach ($rows as &$row) {
            $json = $row['payload_json'] ?? null;
            if (!is_string($json)) throw new UnexpectedValueException('Event payload is not JSON text.');
            $row['payload'] = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            unset($row['payload_json']);
        }
        unset($row);
        return $rows;
    }
    public static function deterministicKey(string ...$parts): string
    {
        if ($parts === [] || in_array('', $parts, true)) {
            throw new InvalidArgumentException('Idempotency key parts must be non-empty.');
        }
        return hash('sha256', implode('|', $parts));
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function normalizeEvent(array $event): array
    {
        foreach (array_keys($event) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                throw new InvalidArgumentException('Event field is not allowed: ' . $field);
            }
        }
        $caseId = filter_var($event['case_id'] ?? null, FILTER_VALIDATE_INT);
        if ($caseId === false || $caseId < 1) {
            throw new InvalidArgumentException('Event case_id must be a positive integer.');
        }
        $eventType = $this->bounded($event['event_type'] ?? null, 'event_type', 64);
        $source = $this->bounded($event['source'] ?? null, 'source', 32);
        $sourceEventId = $this->nullableBounded($event['source_event_id'] ?? null, 'source_event_id', 191);
        $idempotencyKey = strtolower($this->bounded($event['idempotency_key'] ?? null, 'idempotency_key', 64));
        if (preg_match('/^[a-f0-9]{64}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Event idempotency_key must be a SHA-256 digest.');
        }
        $occurredAt = $this->utcDate($event['occurred_at'] ?? null, 'occurred_at');
        $payload = $event['payload'] ?? null;
        if (!is_array($payload)) throw new InvalidArgumentException('Event payload must be an array.');
        $evidence = $event['evidence_sha256'] ?? null;
        if ($evidence !== null) {
            if (!is_string($evidence) || preg_match('/^[a-f0-9]{64}$/i', $evidence) !== 1) {
                throw new InvalidArgumentException('Event evidence_sha256 must be a SHA-256 digest or null.');
            }
            $evidence = strtolower($evidence);
        }
        return [
            ':case_id'=>$caseId,
            ':event_type'=>$eventType,
            ':source'=>$source,
            ':source_event_id'=>$sourceEventId,
            ':idempotency_key'=>$idempotencyKey,
            ':occurred_at'=>$occurredAt,
            ':payload_json'=>json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':evidence_sha256'=>$evidence,
            ':created_at'=>gmdate('Y-m-d H:i:s'),
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
            throw new RuntimeException('Event case is not owned by the current tenant connection.');
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
        if (!$stmt instanceof PDOStatement) throw new RuntimeException('Could not prepare scoped event statement.');
        return $stmt;
    }
    private function positiveId(int $value, string $label): int
    {
        if ($value < 1) throw new InvalidArgumentException($label . ' must be positive.');
        return $value;
    }

    private function bounded(mixed $value, string $label, int $maxLength): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $maxLength) {
            throw new InvalidArgumentException($label . ' must contain 1-' . $maxLength . ' bytes.');
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

    private function isUniqueViolation(PDOException $exception): bool
    {
        $driverCode = $exception->errorInfo[1] ?? null;
        return (string)$exception->getCode() === '23000' && ($driverCode === null || (int)$driverCode === 1062);
    }
}
