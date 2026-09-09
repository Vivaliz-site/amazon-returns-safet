<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantContext.php';

final class SvAmazonTenantReturnsOutbox
{
    public const MAX_ATTEMPTS = 5;
    public const LEASE_SECONDS = 300;
    private const SECRET_KEYS = [
        'access_token','refresh_token','client_secret','password','cookie','authorization','mfa','otp',
    ];

    public function __construct(
        private PDO $db,
        private SvAmazonTenantContext $context
    ) {}

    public function deterministicKey(string $kind, int $caseId, string $scope): string
    {
        $kind = $this->kind($kind);
        if ($caseId < 1 || trim($scope) === '') {
            throw new InvalidArgumentException('Outbox deterministic key inputs are invalid.');
        }
        return hash('sha256', implode('|', [
            $this->context->scopeKey(), $kind, (string)$caseId, trim($scope),
        ]));
    }

    /** @param array<string,mixed> $payload */
    public function enqueue(string $kind, int $caseId, array $payload, string $idempotencyKey): int
    {
        $kind = $this->kind($kind);
        $caseId = $this->positiveId($caseId, 'case ID');
        $idempotencyKey = $this->idempotencyKey($idempotencyKey);
        self::assertNoSecrets($payload);
        $this->assertOwnedCase($caseId);
        $stmt = $this->prepare(
            "INSERT INTO amazon_return_outbox "
            . "(tenant_id,amazon_connection_id,case_id,kind,idempotency_key,payload_json,status,attempt_count,available_at,created_at,updated_at) "
            . "VALUES (:tenant_id,:amazon_connection_id,:case_id,:kind,:idempotency_key,:payload_json,'PENDING',0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())"
        );
        $params = $this->scopeParams([
            ':case_id'=>$caseId,
            ':kind'=>$kind,
            ':idempotency_key'=>$idempotencyKey,
            ':payload_json'=>json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        try {
            $stmt->execute($params);
            $id = (int)$this->db->lastInsertId();
            if ($id < 1) throw new RuntimeException('Scoped outbox insert did not return an ID.');
            return $id;
        } catch (PDOException $exception) {
            if (!$this->isUniqueViolation($exception)) throw $exception;
        }

        $existing = $this->prepare(
            'SELECT id FROM amazon_return_outbox WHERE tenant_id=:tenant_id '
            . 'AND amazon_connection_id=:amazon_connection_id AND idempotency_key=:idempotency_key LIMIT 1'
        );
        $existing->execute($this->scopeParams([':idempotency_key'=>$idempotencyKey]));
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        $id = is_array($row) ? (int)($row['id'] ?? 0) : 0;
        if ($id < 1) throw new RuntimeException('Scoped duplicate outbox action could not be resolved.');
        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function claimBatch(int $limit = 10, array $kinds = []): array
    {
        $limit = max(1, min(50, $limit));
        $normalizedKinds = [];
        foreach ($kinds as $kind) $normalizedKinds[] = $this->kind((string)$kind);
        $normalizedKinds = array_values(array_unique($normalizedKinds));
        $params = $this->scopeParams();
        $kindSql = '';
        if ($normalizedKinds !== []) {
            $placeholders = [];
            foreach ($normalizedKinds as $index=>$kind) {
                $name = ':kind_' . $index;
                $placeholders[] = $name;
                $params[$name] = $kind;
            }
            $kindSql = ' AND kind IN (' . implode(',', $placeholders) . ')';
        }

        $this->db->beginTransaction();
        try {
            $select = $this->prepare(
                "SELECT * FROM amazon_return_outbox WHERE tenant_id=:tenant_id "
                . "AND amazon_connection_id=:amazon_connection_id AND ((status='PENDING' AND available_at<=UTC_TIMESTAMP()) "
                . "OR (status='PROCESSING' AND locked_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL " . self::LEASE_SECONDS . " SECOND)))"
                . $kindSql . ' ORDER BY available_at,id LIMIT ' . $limit . ' FOR UPDATE SKIP LOCKED'
            );
            $select->execute($params);
            $rows = array_values(array_filter($select->fetchAll(PDO::FETCH_ASSOC), 'is_array'));
            $update = $this->prepare(
                "UPDATE amazon_return_outbox SET status='PROCESSING',attempt_count=attempt_count+1,"
                . 'locked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id '
                . 'AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id'
            );
            foreach ($rows as &$row) {
                $this->assertOwnedRow($row);
                $id = $this->positiveId((int)($row['id'] ?? 0), 'outbox ID');
                $update->execute($this->scopeParams([':id'=>$id]));
                if ($update->rowCount() !== 1) throw new RuntimeException('Scoped outbox claim lost ownership.');
                $row['attempt_count'] = (int)($row['attempt_count'] ?? 0) + 1;
                $row['status'] = 'PROCESSING';
                $row['payload'] = $this->decodePayload($row['payload_json'] ?? null);
            }
            unset($row);
            $this->db->commit();
            return $rows;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    /** @return list<array<string,mixed>> */
    public function historyForCase(int $caseId): array
    {
        $caseId=$this->positiveId($caseId,'case ID');
        $this->assertOwnedCase($caseId);
        $stmt=$this->prepare(
            'SELECT * FROM amazon_return_outbox WHERE tenant_id=:tenant_id '
            . 'AND amazon_connection_id=:amazon_connection_id AND case_id=:case_id '
            . 'ORDER BY created_at,id'
        );
        $stmt->execute($this->scopeParams([':case_id'=>$caseId]));
        $rows=array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),'is_array'));
        foreach($rows as &$row){
            $this->assertOwnedRow($row);
            $row['payload']=$this->decodePayload($row['payload_json']??null);
            unset($row['payload_json']);
        }
        unset($row);
        return $rows;
    }

    public function countPendingProcessing(): int
    {
        $stmt=$this->prepare(
            "SELECT COUNT(*) FROM amazon_return_outbox WHERE tenant_id=:tenant_id "
            . "AND amazon_connection_id=:amazon_connection_id AND status IN ('PENDING','PROCESSING')"
        );
        $stmt->execute($this->scopeParams());
        return max(0,(int)$stmt->fetchColumn());
    }

    public function countDeadLetters(): int
    {
        $stmt=$this->prepare(
            'SELECT COUNT(*) FROM amazon_return_dead_letters WHERE tenant_id=:tenant_id '
            . 'AND amazon_connection_id=:amazon_connection_id'
        );
        $stmt->execute($this->scopeParams());
        return max(0,(int)$stmt->fetchColumn());
    }

    public function hasActive(int $caseId,string $kind): bool
    {
        $stmt=$this->prepare(
            'SELECT id FROM amazon_return_outbox WHERE tenant_id=:tenant_id '
            . 'AND amazon_connection_id=:amazon_connection_id AND case_id=:case_id AND kind=:kind '
            . "AND status IN ('PENDING','PROCESSING') LIMIT 1"
        );
        $stmt->execute($this->scopeParams([
            ':case_id'=>$this->positiveId($caseId,'case ID'),
            ':kind'=>$this->kind($kind),
        ]));
        return $stmt->fetchColumn()!==false;
    }

    public function defer(int $id,DateTimeImmutable $availableAt,string $error,bool $refundAttempt=false): void
    {
        $id=$this->positiveId($id,'outbox ID');
        $attempt=$refundAttempt?',attempt_count=GREATEST(attempt_count-1,0)':'';
        $stmt=$this->prepare(
            "UPDATE amazon_return_outbox SET status='PENDING'{$attempt},available_at=:available_at,"
            . 'locked_at=NULL,last_error=:last_error,updated_at=UTC_TIMESTAMP() WHERE id=:id '
            . "AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id AND status='PROCESSING'"
        );
        $stmt->execute($this->scopeParams([
            ':id'=>$id,
            ':available_at'=>$availableAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ':last_error'=>$this->errorMessage($error),
        ]));
        if($stmt->rowCount()!==1)throw new RuntimeException('Scoped outbox defer failed.');
    }

    /** @return array<string,mixed>|null */
    public function findOwned(int $id): ?array
    {
        $id = $this->positiveId($id, 'outbox ID');
        $stmt = $this->prepare(
            'SELECT * FROM amazon_return_outbox WHERE id=:id AND tenant_id=:tenant_id '
            . 'AND amazon_connection_id=:amazon_connection_id LIMIT 1'
        );
        $stmt->execute($this->scopeParams([':id'=>$id]));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $this->assertOwnedRow($row);
        $row['payload'] = $this->decodePayload($row['payload_json'] ?? null);
        return $row;
    }

    public function markSucceeded(int $id): void
    {
        $this->updateOne(
            "UPDATE amazon_return_outbox SET status='SUCCEEDED',locked_at=NULL,last_error=NULL,updated_at=UTC_TIMESTAMP() "
            . "WHERE id=:id AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id AND status='PROCESSING'",
            $this->positiveId($id, 'outbox ID'),
            'Scoped outbox success acknowledgement failed.'
        );
    }

    public function releaseUnprocessed(int $id): void
    {
        $this->updateOne(
            "UPDATE amazon_return_outbox SET status='PENDING',attempt_count=GREATEST(attempt_count-1,0),"
            . 'locked_at=NULL,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id '
            . "AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id AND status='PROCESSING'",
            $this->positiveId($id, 'outbox ID'),
            'Scoped outbox release failed.'
        );
    }

    public function reschedule(int $id, DateTimeImmutable $availableAt, string $error): void
    {
        $id = $this->positiveId($id, 'outbox ID');
        $message = $this->errorMessage($error);
        $stmt = $this->prepare(
            "UPDATE amazon_return_outbox SET status='PENDING',available_at=:available_at,locked_at=NULL,"
            . 'last_error=:last_error,updated_at=UTC_TIMESTAMP() WHERE id=:id '
            . "AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id AND status='PROCESSING'"
        );
        $stmt->execute($this->scopeParams([
            ':id'=>$id,
            ':available_at'=>$availableAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ':last_error'=>$message,
        ]));
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Scoped outbox reschedule failed.');
    }

    /** @return array{status:string,next_at:?DateTimeImmutable,reason:string} */
    public function markFailed(array $row, Throwable|string $error, ?DateTimeImmutable $now = null): array
    {
        $this->assertOwnedRow($row);
        $id = $this->positiveId((int)($row['id'] ?? 0), 'outbox ID');
        $caseId = $this->positiveId((int)($row['case_id'] ?? 0), 'case ID');
        $message = $this->errorMessage($error instanceof Throwable ? $error->getMessage() : $error);
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $decision = self::retryDecision($row, $now);
        if ($decision['status'] === 'RETRY') {
            $stmt = $this->prepare(
                "UPDATE amazon_return_outbox SET status='PENDING',available_at=:available_at,locked_at=NULL,"
                . 'last_error=:last_error,updated_at=UTC_TIMESTAMP() WHERE id=:id '
                . "AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id AND status='PROCESSING'"
            );
            $stmt->execute($this->scopeParams([
                ':id'=>$id,
                ':available_at'=>$decision['next_at']->format('Y-m-d H:i:s'),
                ':last_error'=>$message,
            ]));
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Scoped outbox retry update failed.');
            return $decision;
        }

        $payloadJson = $this->payloadJson($row);
        $this->db->beginTransaction();
        try {
            $dead = $this->prepare(
                'INSERT INTO amazon_return_dead_letters '
                . '(tenant_id,amazon_connection_id,outbox_id,case_id,kind,idempotency_key,payload_sha256,'
                . 'payload_json,error_class,error_message,attempt_count,first_attempt_at,failed_at,created_at) '
                . 'VALUES (:tenant_id,:amazon_connection_id,:outbox_id,:case_id,:kind,:idempotency_key,'
                . ':payload_sha256,:payload_json,:error_class,:error_message,:attempt_count,'
                . 'COALESCE(:first_attempt_at,UTC_TIMESTAMP()),UTC_TIMESTAMP(),UTC_TIMESTAMP()) '
                . 'ON DUPLICATE KEY UPDATE error_message=VALUES(error_message),'
                . 'attempt_count=GREATEST(attempt_count,VALUES(attempt_count)),failed_at=UTC_TIMESTAMP()'
            );
            $dead->execute($this->scopeParams([
                ':outbox_id'=>$id,
                ':case_id'=>$caseId,
                ':kind'=>$this->kind((string)($row['kind'] ?? '')),
                ':idempotency_key'=>$this->idempotencyKey((string)($row['idempotency_key'] ?? '')),
                ':payload_sha256'=>hash('sha256', $payloadJson),
                ':payload_json'=>$payloadJson,
                ':error_class'=>$error instanceof Throwable ? $error::class : 'RuntimeFailure',
                ':error_message'=>$message,
                ':attempt_count'=>max(0, (int)($row['attempt_count'] ?? 0)),
                ':first_attempt_at'=>$this->nullableSqlDate($row['created_at'] ?? null),
            ]));
            $update = $this->prepare(
                "UPDATE amazon_return_outbox SET status='DEAD_LETTER',locked_at=NULL,last_error=:last_error,"
                . 'updated_at=UTC_TIMESTAMP() WHERE id=:id AND tenant_id=:tenant_id '
                . "AND amazon_connection_id=:amazon_connection_id AND status='PROCESSING'"
            );
            $update->execute($this->scopeParams([
                ':id'=>$id,
                ':last_error'=>$decision['reason'] . ': ' . $message,
            ]));
            if ($update->rowCount() !== 1) throw new RuntimeException('Scoped dead-letter transition failed.');
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
        return $decision;
    }

    /** @return array{status:string,next_at:?DateTimeImmutable,reason:string} */
    public static function retryDecision(array $row, DateTimeImmutable $now): array
    {
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $attempt = max(0, (int)($row['attempt_count'] ?? 0));
        $payload = is_array($row['payload'] ?? null)
            ? $row['payload']
            : self::decodePayloadStatic($row['payload_json'] ?? null);
        $deadline = null;
        $deadlineRaw = $payload['deadline_at'] ?? null;
        if (is_scalar($deadlineRaw) && trim((string)$deadlineRaw) !== '') {
            try {
                $deadline = (new DateTimeImmutable((string)$deadlineRaw))->setTimezone(new DateTimeZone('UTC'));
            } catch (Throwable) {
                return ['status'=>'DEAD_LETTER','next_at'=>null,'reason'=>'INVALID_DEADLINE'];
            }
            if ($deadline <= $now) {
                return ['status'=>'DEAD_LETTER','next_at'=>null,'reason'=>'DEADLINE_WOULD_EXPIRE'];
            }
        }
        if ($attempt >= self::MAX_ATTEMPTS) {
            if (!$deadline instanceof DateTimeImmutable) {
                return ['status'=>'DEAD_LETTER','next_at'=>null,'reason'=>'MAX_ATTEMPTS_EXHAUSTED'];
            }
            $next = $now->modify('+1 day');
            if ($next >= $deadline) {
                return ['status'=>'DEAD_LETTER','next_at'=>null,'reason'=>'DEADLINE_WOULD_EXPIRE'];
            }
            return ['status'=>'RETRY','next_at'=>$next,'reason'=>'RECOVERY_WINDOW_RETRY'];
        }
        $delay = min(3600, 60 * (2 ** max(0, $attempt - 1)));
        $next = $now->modify('+' . $delay . ' seconds');
        if ($deadline instanceof DateTimeImmutable && $next >= $deadline) {
            return ['status'=>'DEAD_LETTER','next_at'=>null,'reason'=>'DEADLINE_WOULD_EXPIRE'];
        }
        return ['status'=>'RETRY','next_at'=>$next,'reason'=>'TRANSIENT_FAILURE'];
    }

    public static function leaseExpired(?string $lockedAt, DateTimeImmutable $now): bool
    {
        if ($lockedAt === null || trim($lockedAt) === '') return true;
        try {
            $locked = (new DateTimeImmutable($lockedAt))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return true;
        }
        return ($now->setTimezone(new DateTimeZone('UTC'))->getTimestamp() - $locked->getTimestamp()) >= self::LEASE_SECONDS;
    }

    private function assertOwnedCase(int $caseId): void
    {
        $stmt = $this->prepare(
            'SELECT id FROM amazon_return_cases WHERE id=:case_id AND tenant_id=:tenant_id '
            . 'AND amazon_connection_id=:amazon_connection_id LIMIT 1'
        );
        $stmt->execute($this->scopeParams([':case_id'=>$caseId]));
        if (!is_array($stmt->fetch(PDO::FETCH_ASSOC))) {
            throw new RuntimeException('Outbox case is not owned by the current tenant connection.');
        }
    }
    /** @param array<string,mixed> $row */
    private function assertOwnedRow(array $row): void
    {
        if ((int)($row['tenant_id'] ?? 0) !== $this->context->tenantId()
            || (int)($row['amazon_connection_id'] ?? 0) !== $this->context->amazonConnectionId()) {
            throw new RuntimeException('Outbox row is not owned by the current tenant connection.');
        }
    }

    private function updateOne(string $sql, int $id, string $failure): void
    {
        $stmt = $this->prepare($sql);
        $stmt->execute($this->scopeParams([':id'=>$id]));
        if ($stmt->rowCount() !== 1) throw new RuntimeException($failure);
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
        if (!$stmt instanceof PDOStatement) throw new RuntimeException('Could not prepare scoped outbox statement.');
        return $stmt;
    }

    private function positiveId(int $value, string $label): int
    {
        if ($value < 1) throw new InvalidArgumentException($label . ' must be positive.');
        return $value;
    }
    private function kind(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '' || strlen($value) > 64 || preg_match('/^[A-Z0-9_:-]+$/', $value) !== 1) {
            throw new InvalidArgumentException('Outbox kind is invalid.');
        }
        return $value;
    }

    private function idempotencyKey(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException('Outbox idempotency key must be a SHA-256 digest.');
        }
        return $value;
    }

    private function errorMessage(string $value): string
    {
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [REDACTED]', $value) ?? $value;
        $value = preg_replace('/\b(access_token|refresh_token|client_secret|password|cookie|authorization|mfa|otp)\b\s*[:=]\s*[^\s,;]+/i', '$1=[REDACTED]', $value) ?? $value;
        $value = trim($value);
        if ($value === '') $value = 'Unspecified outbox failure.';
        return mb_substr($value, 0, 1900, 'UTF-8');
    }

    /** @param array<string,mixed> $row */
    private function payloadJson(array $row): string
    {
        if (is_string($row['payload_json'] ?? null) && trim((string)$row['payload_json']) !== '') {
            $json = (string)$row['payload_json'];
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new UnexpectedValueException('Outbox payload JSON must decode to an array.');
            return $json;
        }
        $payload = $row['payload'] ?? [];
        if (!is_array($payload)) throw new UnexpectedValueException('Outbox payload must be an array.');
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    /** @return array<string,mixed> */
    private function decodePayload(mixed $value): array
    {
        return self::decodePayloadStatic($value);
    }

    /** @return array<string,mixed> */
    private static function decodePayloadStatic(mixed $value): array
    {
        if (is_array($value)) {
            self::assertNoSecrets($value);
            return $value;
        }
        if (!is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new UnexpectedValueException('Outbox payload JSON must decode to an array.');
        self::assertNoSecrets($decoded);
        return $decoded;
    }

    /** @param array<string,mixed> $values */
    private static function assertNoSecrets(array $values): void
    {
        $walk = function(array $items) use (&$walk): void {
            foreach ($items as $key=>$item) {
                $withBreaks = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', (string)$key) ?? (string)$key;
                $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $withBreaks) ?? '');
                foreach (self::SECRET_KEYS as $secret) {
                    if ($normalized === $secret || str_ends_with($normalized, '_' . $secret)) {
                        throw new InvalidArgumentException('Outbox payload contains a forbidden secret field.');
                    }
                }
                if (is_array($item)) $walk($item);
            }
        };
        $walk($values);
    }

    private function nullableSqlDate(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) throw new InvalidArgumentException('Outbox date must be text or null.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('Outbox date must use Y-m-d H:i:s UTC format.');
        }
        return $value;
    }

    private function isUniqueViolation(PDOException $exception): bool
    {
        $driverCode = $exception->errorInfo[1] ?? null;
        return (string)$exception->getCode() === '23000' && ($driverCode === null || (int)$driverCode === 1062);
    }
}
