<?php

declare(strict_types=1);

require_once __DIR__ . '/TenantContext.php';

final class SvAmazonSourceCursorStore
{
    private const SOURCE_MAX = 32;
    private const KEY_MAX = 96;
    private const VALUE_MAX = 512;

    public function __construct(
        private PDO $db,
        private SvAmazonTenantContext $context
    ) {
    }

    public function count(): int
    {
        $stmt=$this->db->prepare(
            'SELECT COUNT(*) FROM amazon_return_source_cursors WHERE tenant_id=:tenant_id '
            . 'AND amazon_connection_id=:amazon_connection_id'
        );
        if(!$stmt instanceof PDOStatement) throw new RuntimeException('Could not prepare tenant cursor count.');
        $stmt->execute($this->scopeParams());
        return max(0,(int)$stmt->fetchColumn());
    }

    /** @return array{value:string,metadata:array<string,mixed>,observed_at:?string}|null */
    public function load(string $source, string $key): ?array
    {
        [$source, $key] = $this->identity($source, $key);
        $stmt = $this->db->prepare(
            'SELECT cursor_value,metadata_json,observed_at FROM amazon_return_source_cursors '
            . 'WHERE tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id '
            . 'AND source=:source AND cursor_key=:cursor_key LIMIT 1'
        );
        if (!$stmt instanceof PDOStatement) {
            throw new RuntimeException('Could not prepare tenant cursor lookup.');
        }
        $stmt->execute($this->scopeParams([
            ':source'=>$source,
            ':cursor_key'=>$key,
        ]));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;

        $metadata = [];
        $raw = $row['metadata_json'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new UnexpectedValueException('Tenant cursor metadata is invalid JSON.', 0, $e);
            }
            if (!is_array($decoded)) {
                throw new UnexpectedValueException('Tenant cursor metadata must decode to an object.');
            }
            $metadata = $decoded;
        }

        return [
            'value'=>(string)$row['cursor_value'],
            'metadata'=>$metadata,
            'observed_at'=>self::nullableString($row['observed_at'] ?? null),
        ];
    }

    /** @param array<string,mixed> $metadata */
    public function save(string $source, string $key, string $value, array $metadata = []): void
    {
        [$source, $key] = $this->identity($source, $key);
        $value = trim($value);
        if ($value === '' || strlen($value) > self::VALUE_MAX) {
            throw new InvalidArgumentException('Tenant cursor value is invalid.');
        }
        self::assertNoSecrets($metadata);
        $metadataJson = $metadata === [] ? null : json_encode(
            $metadata,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $stmt = $this->db->prepare(
            'INSERT INTO amazon_return_source_cursors '
            . '(tenant_id,amazon_connection_id,source,cursor_key,cursor_value,metadata_json,observed_at,created_at,updated_at) '
            . 'VALUES (:tenant_id,:amazon_connection_id,:source,:cursor_key,:cursor_value,:metadata_json,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE cursor_value=VALUES(cursor_value),metadata_json=VALUES(metadata_json),'
            . 'observed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()'
        );
        if (!$stmt instanceof PDOStatement) {
            throw new RuntimeException('Could not prepare tenant cursor save.');
        }
        $stmt->execute($this->scopeParams([
            ':source'=>$source,
            ':cursor_key'=>$key,
            ':cursor_value'=>$value,
            ':metadata_json'=>$metadataJson,
        ]));
    }

    public function clear(string $source, string $key): void
    {
        [$source, $key] = $this->identity($source, $key);
        $stmt = $this->db->prepare(
            'DELETE FROM amazon_return_source_cursors '
            . 'WHERE tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id '
            . 'AND source=:source AND cursor_key=:cursor_key'
        );
        if (!$stmt instanceof PDOStatement) {
            throw new RuntimeException('Could not prepare tenant cursor delete.');
        }
        $stmt->execute($this->scopeParams([
            ':source'=>$source,
            ':cursor_key'=>$key,
        ]));
    }

    /** @return array{0:string,1:string} */
    private function identity(string $source, string $key): array
    {
        $source = strtoupper(trim($source));
        $key = strtolower(trim($key));
        if ($source === '' || strlen($source) > self::SOURCE_MAX
            || preg_match('/^[A-Z0-9_:-]+$/', $source) !== 1) {
            throw new InvalidArgumentException('Tenant cursor source is invalid.');
        }
        if ($key === '' || strlen($key) > self::KEY_MAX
            || preg_match('/^[a-z0-9_:-]+$/', $key) !== 1) {
            throw new InvalidArgumentException('Tenant cursor key is invalid.');
        }
        return [$source, $key];
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function scopeParams(array $extra = []): array
    {
        return [
            ':tenant_id'=>$this->context->tenantId(),
            ':amazon_connection_id'=>$this->context->amazonConnectionId(),
        ] + $extra;
    }

    /** @param array<string,mixed> $metadata */
    private static function assertNoSecrets(array $metadata): void
    {
        $walk = static function (array $values) use (&$walk): void {
            foreach ($values as $key=>$value) {
                $normalized = preg_replace('/[^a-z0-9]/', '', strtolower((string)$key)) ?? '';
                foreach (['accesstoken','refreshtoken','clientsecret','authorization','password','cookie','sessioncookie','mfa','otp'] as $secret) {
                    if ($normalized === $secret || str_ends_with($normalized, $secret)) {
                        throw new InvalidArgumentException('Tenant cursor metadata contains a secret field.');
                    }
                }
                if (is_array($value)) $walk($value);
            }
        };
        $walk($metadata);
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}
