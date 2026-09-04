<?php
declare(strict_types=1);

final class SvAmazonReturnEventStore
{
    public static function append(PDO $db, array $event): int
    {
        $normalized = self::normalizeEvent($event);
        $statement = $db->prepare(
            'INSERT INTO `amazon_return_events` '
            . '(`case_id`, `event_type`, `source`, `source_event_id`, `idempotency_key`, '
            . '`occurred_at`, `payload_json`, `evidence_sha256`, `created_at`) '
            . 'VALUES (:case_id, :event_type, :source, :source_event_id, :idempotency_key, '
            . ':occurred_at, :payload_json, :evidence_sha256, :created_at)'
        );
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('Could not prepare Amazon return event insert.');
        }

        try {
            $statement->execute($normalized);
            $id = (int) $db->lastInsertId();
            if ($id < 1) {
                throw new RuntimeException('Amazon return event insert did not return an ID.');
            }
            return $id;
        } catch (PDOException $exception) {
            if (!self::isUniqueViolation($exception)) {
                throw $exception;
            }
        }

        $existing = $db->prepare(
            'SELECT `id` FROM `amazon_return_events` WHERE `idempotency_key` = :idempotency_key LIMIT 1'
        );
        if (!$existing instanceof PDOStatement) {
            throw new RuntimeException('Could not prepare Amazon return event duplicate lookup.');
        }
        $existing->execute([':idempotency_key' => $normalized[':idempotency_key']]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        if ($id < 1) {
            throw new RuntimeException('Duplicate Amazon return event could not be resolved.');
        }

        return $id;
    }

    /** @return list<array<string,mixed>> */
    public static function eventsForCase(PDO $db, int $caseId): array
    {
        if ($caseId < 1) {
            throw new InvalidArgumentException('Amazon return case ID must be positive.');
        }

        $statement = $db->prepare(
            'SELECT `id`, `case_id`, `event_type`, `source`, `source_event_id`, `idempotency_key`, '
            . '`occurred_at`, `payload_json`, `evidence_sha256`, `created_at` '
            . 'FROM `amazon_return_events` WHERE `case_id` = :case_id ORDER BY `occurred_at`, `id`'
        );
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('Could not prepare Amazon return case event query.');
        }
        $statement->execute([':case_id' => $caseId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $payloadJson = $row['payload_json'] ?? null;
            if (!is_string($payloadJson)) {
                throw new UnexpectedValueException('Amazon return event payload is not JSON text.');
            }
            $row['payload'] = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
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

    /** @return array<string,int|string|null> */
    private static function normalizeEvent(array $event): array
    {
        $caseId = filter_var($event['case_id'] ?? null, FILTER_VALIDATE_INT);
        if ($caseId === false || $caseId < 1) {
            throw new InvalidArgumentException('Amazon return event case_id must be a positive integer.');
        }

        $eventType = self::boundedString($event, 'event_type', 64);
        $source = self::boundedString($event, 'source', 32);
        $sourceEventId = self::nullableBoundedString($event['source_event_id'] ?? null, 191, 'source_event_id');
        $idempotencyKey = strtolower(self::boundedString($event, 'idempotency_key', 64));
        if (preg_match('/^[a-f0-9]{64}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Amazon return event idempotency_key must be a SHA-256 hex digest.');
        }

        $occurredAt = $event['occurred_at'] ?? null;
        if ($occurredAt instanceof DateTimeInterface) {
            $occurredAt = DateTimeImmutable::createFromInterface($occurredAt)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        }
        if (!is_string($occurredAt) || !self::isUtcDateTime($occurredAt)) {
            throw new InvalidArgumentException('Amazon return event occurred_at must use Y-m-d H:i:s UTC format.');
        }

        $payload = $event['payload'] ?? null;
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Amazon return event payload must be an array.');
        }
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $evidenceSha256 = $event['evidence_sha256'] ?? null;
        if ($evidenceSha256 !== null) {
            if (!is_string($evidenceSha256) || preg_match('/^[a-fA-F0-9]{64}$/', $evidenceSha256) !== 1) {
                throw new InvalidArgumentException('Amazon return event evidence_sha256 must be null or a SHA-256 hex digest.');
            }
            $evidenceSha256 = strtolower($evidenceSha256);
        }

        return [
            ':case_id' => $caseId,
            ':event_type' => $eventType,
            ':source' => $source,
            ':source_event_id' => $sourceEventId,
            ':idempotency_key' => $idempotencyKey,
            ':occurred_at' => $occurredAt,
            ':payload_json' => $payloadJson,
            ':evidence_sha256' => $evidenceSha256,
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    private static function boundedString(array $values, string $key, int $maxLength): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $maxLength) {
            throw new InvalidArgumentException("Amazon return event {$key} must be 1-{$maxLength} bytes.");
        }
        return $value;
    }

    private static function nullableBoundedString(mixed $value, int $maxLength, string $name): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '' || strlen($value) > $maxLength) {
            throw new InvalidArgumentException("Amazon return event {$name} must be null or 1-{$maxLength} bytes.");
        }
        return $value;
    }

    private static function isUtcDateTime(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d H:i:s') === $value;
    }

    private static function isUniqueViolation(PDOException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        $driverCode = $exception->errorInfo[1] ?? null;
        return $sqlState === '23000' && ($driverCode === null || (int) $driverCode === 1062);
    }
}
