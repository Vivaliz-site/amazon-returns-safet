<?php

declare(strict_types=1);

final class SvAmazonReturnsOutbox
{
    public const MAX_ATTEMPTS = 5;
    public const LEASE_SECONDS = 300;

    public static function deterministicKey(string $kind, int $caseId, string $scope): string
    {
        return hash('sha256', strtolower(trim($kind)) . '|' . $caseId . '|' . trim($scope));
    }

    public static function enqueue(PDO $db, string $kind, int $caseId, array $payload, string $idempotencyKey): int
    {
        if ($caseId <= 0 || trim($kind) === '' || preg_match('/^[a-f0-9]{64}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Invalid outbox action.');
        }
        $stmt = $db->prepare(
            'INSERT INTO amazon_return_outbox '
            . '(case_id, kind, idempotency_key, payload_json, status, attempt_count, available_at, created_at, updated_at) '
            . 'VALUES (:case_id, :kind, :idempotency_key, :payload_json, \'PENDING\', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        try {
            $stmt->execute([
                ':case_id' => $caseId,
                ':kind' => strtoupper(trim($kind)),
                ':idempotency_key' => $idempotencyKey,
                ':payload_json' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            return (int)$db->lastInsertId();
        } catch (PDOException $e) {
            if (!self::isDuplicate($e)) throw $e;
            $find = $db->prepare('SELECT id FROM amazon_return_outbox WHERE idempotency_key = :idempotency_key LIMIT 1');
            $find->execute([':idempotency_key' => $idempotencyKey]);
            $existing = $find->fetchColumn();
            if ($existing === false) throw $e;
            return (int)$existing;
        }
    }

    /** @return array{status:string,next_at:?DateTimeImmutable,reason:string} */
    public static function retryDecision(array $row, DateTimeImmutable $now): array
    {
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $attempt = max(0, (int)($row['attempt_count'] ?? 0));
        if ($attempt >= self::MAX_ATTEMPTS) {
            return ['status'=>'DEAD_LETTER','next_at'=>null,'reason'=>'MAX_ATTEMPTS_EXHAUSTED'];
        }
        $delay = min(3600, 60 * (2 ** max(0, $attempt - 1)));
        $next = $now->modify('+' . $delay . ' seconds');
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : self::decodePayload($row['payload_json'] ?? null);
        $deadlineRaw = $payload['deadline_at'] ?? null;
        if (is_scalar($deadlineRaw) && trim((string)$deadlineRaw) !== '') {
            try {
                $deadline = (new DateTimeImmutable((string)$deadlineRaw))->setTimezone(new DateTimeZone('UTC'));
                if ($deadline <= $now || $next >= $deadline) {
                    return ['status'=>'DEAD_LETTER','next_at'=>null,'reason'=>'DEADLINE_WOULD_EXPIRE'];
                }
            } catch (Throwable) {
                return ['status'=>'DEAD_LETTER','next_at'=>null,'reason'=>'INVALID_DEADLINE'];
            }
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

    /** @return list<array<string,mixed>> */
    public static function claimBatch(PDO $db, int $limit = 10, array $kinds = []): array
    {
        $limit = max(1, min(50, $limit));
        $normalizedKinds = array_values(array_unique(array_filter(array_map(static fn(mixed $kind): string => strtoupper(trim((string)$kind)), $kinds))));
        $db->beginTransaction();
        try {
            $kindSql = $normalizedKinds === [] ? '' : ' AND kind IN (' . implode(',', array_map(static fn(string $kind): string => $db->quote($kind), $normalizedKinds)) . ')';
            $sql = 'SELECT * FROM amazon_return_outbox '
                . 'WHERE ((status = \'PENDING\' AND available_at <= UTC_TIMESTAMP()) '
                . 'OR (status = \'PROCESSING\' AND locked_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . self::LEASE_SECONDS . ' SECOND)))' . $kindSql . ' '
                . 'ORDER BY available_at, id LIMIT ' . $limit . ' FOR UPDATE SKIP LOCKED';
            $rows = $db->query($sql)?->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $update = $db->prepare('UPDATE amazon_return_outbox SET status=\'PROCESSING\', attempt_count=attempt_count+1, locked_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=:id');
            foreach ($rows as &$row) {
                $update->execute([':id'=>(int)$row['id']]);
                $row['attempt_count'] = (int)($row['attempt_count'] ?? 0) + 1;
                $row['payload'] = self::decodePayload($row['payload_json'] ?? null);
            }
            unset($row);
            $db->commit();
            return array_values($rows);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function markSucceeded(PDO $db, int $id): void
    {
        $stmt = $db->prepare('UPDATE amazon_return_outbox SET status=\'SUCCEEDED\', locked_at=NULL, last_error=NULL, updated_at=UTC_TIMESTAMP() WHERE id=:id');
        $stmt->execute([':id'=>$id]);
    }

    public static function releaseUnprocessed(PDO $db, int $id): void
    {
        if ($id < 1) throw new InvalidArgumentException('Invalid outbox ID.');
        $stmt = $db->prepare("UPDATE amazon_return_outbox SET status='PENDING', attempt_count=GREATEST(attempt_count-1,0), locked_at=NULL, last_error=NULL, updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='PROCESSING'");
        $stmt->execute([':id'=>$id]);
    }

    public static function markFailed(PDO $db, array $row, Throwable|string $error, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $message = mb_substr($error instanceof Throwable ? $error->getMessage() : $error, 0, 1900, 'UTF-8');
        $decision = self::retryDecision($row, $now);
        if ($decision['status'] === 'RETRY') {
            $stmt = $db->prepare('UPDATE amazon_return_outbox SET status=\'PENDING\', available_at=:available_at, locked_at=NULL, last_error=:last_error, updated_at=UTC_TIMESTAMP() WHERE id=:id');
            $stmt->execute([':available_at'=>$decision['next_at']->format('Y-m-d H:i:s'), ':last_error'=>$message, ':id'=>(int)$row['id']]);
            return $decision;
        }

        $payloadJson = is_string($row['payload_json'] ?? null) ? $row['payload_json'] : json_encode($row['payload'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $db->beginTransaction();
        try {
            $dead = $db->prepare('INSERT INTO amazon_return_dead_letters (outbox_id, case_id, kind, idempotency_key, payload_sha256, payload_json, error_class, error_message, attempt_count, first_attempt_at, failed_at, created_at) VALUES (:outbox_id,:case_id,:kind,:idempotency_key,:payload_sha256,:payload_json,:error_class,:error_message,:attempt_count,COALESCE(:first_attempt_at,UTC_TIMESTAMP()),UTC_TIMESTAMP(),UTC_TIMESTAMP())');
            $dead->execute([
                ':outbox_id'=>(int)$row['id'], ':case_id'=>(int)$row['case_id'], ':kind'=>(string)$row['kind'], ':idempotency_key'=>(string)$row['idempotency_key'],
                ':payload_sha256'=>hash('sha256',$payloadJson), ':payload_json'=>$payloadJson, ':error_class'=>$error instanceof Throwable ? $error::class : 'RuntimeFailure',
                ':error_message'=>$message, ':attempt_count'=>(int)($row['attempt_count'] ?? 0), ':first_attempt_at'=>$row['created_at'] ?? null,
            ]);
            $update = $db->prepare('UPDATE amazon_return_outbox SET status=\'DEAD_LETTER\', locked_at=NULL, last_error=:last_error, updated_at=UTC_TIMESTAMP() WHERE id=:id');
            $update->execute([':last_error'=>$decision['reason'] . ': ' . $message, ':id'=>(int)$row['id']]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return $decision;
    }

    private static function isDuplicate(PDOException $e): bool
    {
        return (string)$e->getCode() === '23000' || str_contains(strtolower($e->getMessage()), 'duplicate');
    }

    /** @return array<string,mixed> */
    private static function decodePayload(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
