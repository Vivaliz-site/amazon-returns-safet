<?php

declare(strict_types=1);

require_once __DIR__ . '/SafeTStatus.php';

final class SvAmazonReturnsRemoteBridge
{
    private const KINDS = [
        'SAFE_T_READ',
        'SAFE_T_SUBMIT',
        'SAFE_T_APPEAL',
        'SELLER_SUPPORT_READ',
        'SELLER_SUPPORT_OPEN',
        'SELLER_SUPPORT_UPDATE',
    ];
    private const STATUSES = [
        'ACCEPTED',
        'BLOCKED_UNTIL',
        'ALREADY_EXISTS',
        'AUTH_REQUIRED',
        'HUMAN_CHALLENGE',
        'UI_DRIFT',
        'NOT_FOUND',
        'FAILED',
    ];

    /**
     * Resolves the raw Authorization header value across server SAPIs.
     *
     * Apache's mod_php does not reliably populate $_SERVER['HTTP_AUTHORIZATION']
     * for this endpoint (confirmed in production: it was empty on every request
     * regardless of the token sent, so auth always failed closed on a 401 that
     * looked like a bad token but never was one). apache_request_headers()
     * reads straight from Apache's own request record and reliably includes
     * Authorization, so it is used as a fallback when the $_SERVER copy is
     * missing.
     *
     * @param array<string,mixed> $server Typically $_SERVER.
     * @param array<string,mixed> $apacheHeaders Typically apache_request_headers(), or [] outside Apache/CLI.
     */
    public static function resolveAuthorizationHeader(array $server, array $apacheHeaders): string
    {
        $auth = $server['HTTP_AUTHORIZATION'] ?? ($server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!is_string($auth) || trim($auth) === '') {
            foreach ($apacheHeaders as $name => $value) {
                if (is_string($name) && strtolower($name) === 'authorization') {
                    $auth = $value;
                    break;
                }
            }
        }
        return is_string($auth) ? trim($auth) : '';
    }

    public static function authorized(string $expected, string $authorization): bool
    {
        $expected = trim($expected);
        if ($expected === '') return false;
        if (!preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $m)) return false;
        return hash_equals($expected, trim((string)$m[1]));
    }

    /** @return array<string,mixed> */
    public static function jobEnvelope(array $row, array $case, array $writeFlags): array
    {
        $kind = strtoupper(trim((string)($row['kind'] ?? '')));
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unsupported Seller Central bridge action.');
        }
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
        return [
            'job_id' => (int)($row['id'] ?? 0),
            'case_id' => (int)($row['case_id'] ?? 0),
            'action' => $kind,
            'idempotency_key' => (string)($row['idempotency_key'] ?? ''),
            'attempt_count' => (int)($row['attempt_count'] ?? 0),
            'write_enabled' => ($writeFlags[$kind] ?? false) === true,
            'case' => [
                'order_id' => (string)($case['amazon_order_id'] ?? ''),
                'order_item_id' => (string)($case['amazon_order_item_id'] ?? ''),
                'safe_t_id' => self::nullableString($case['safe_t_id'] ?? null),
                'support_case_id' => self::nullableString($case['support_case_id'] ?? null),
                'quantity_refunded' => (int)($case['quantity_refunded'] ?? 0),
                'quantity_received' => (int)($case['quantity_received'] ?? 0),
            ],
            'payload' => $payload,
        ];
    }

    /** @return array<string,mixed> */
    public static function validateResult(array $result): array
    {
        $status = strtoupper(trim((string)($result['status'] ?? '')));
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Invalid Seller Central bridge status.');
        }
        $submitted = ($result['submitted'] ?? false) === true;
        $externalId = self::nullableString($result['external_id'] ?? null);
        if ($submitted && $externalId === null) {
            throw new RuntimeException('Submitted Seller Central write requires external read-back ID.');
        }
        return [
            'status' => $status,
            'submitted' => $submitted,
            'external_id' => $externalId,
            'retry_safe' => ($result['retry_safe'] ?? false) === true,
            'block_reason' => self::nullableString($result['block_reason'] ?? null),
            'next_allowed_at' => self::nullableString($result['next_allowed_at'] ?? null),
            'reason' => self::nullableString($result['reason'] ?? null),
            'evidence' => is_array($result['evidence'] ?? null) ? $result['evidence'] : [],
            'read' => is_array($result['read'] ?? null) ? SvAmazonSafeTStatus::normalize($result['read']) : null,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}
