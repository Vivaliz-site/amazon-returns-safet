<?php
declare(strict_types=1);

final class SvAmazonSafeTStatus
{
    private const CLAIM_STATUSES = ['DENIED','APPROVED','INFO_REQUESTED','PENDING','UNKNOWN'];

    /** @return array{claim_status:string,safe_t_id:?string,order_id:?string,denied_at:?string,appeal_deadline_at:?string,decision_text:?string,decision_fingerprint:?string,appeal_submitted:bool,appeal_denied:bool} */
    public static function normalize(array $read): array
    {
        $status = strtoupper(trim((string)($read['claim_status'] ?? 'UNKNOWN')));
        if (!in_array($status, self::CLAIM_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid SAFE-T claim status.');
        }
        $decisionText = self::nullableText($read['decision_text'] ?? null, 8000);
        $fingerprint = self::nullableHash($read['decision_fingerprint'] ?? null);
        if ($decisionText !== null && $fingerprint === null) {
            $normalized = preg_replace('/\s+/u', ' ', trim($decisionText)) ?? trim($decisionText);
            $normalized = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
            $fingerprint = hash('sha256', $normalized);
        }
        return [
            'claim_status'=>$status,
            'safe_t_id'=>self::nullableText($read['safe_t_id'] ?? null, 64),
            'order_id'=>self::nullableText($read['order_id'] ?? null, 32),
            'denied_at'=>self::utc($read['denied_at'] ?? null),
            'appeal_deadline_at'=>self::utc($read['appeal_deadline_at'] ?? null),
            'decision_text'=>$decisionText,
            'decision_fingerprint'=>$fingerprint,
            'appeal_submitted'=>(bool)($read['appeal_submitted'] ?? false),
            'appeal_denied'=>(bool)($read['appeal_denied'] ?? false),
        ];
    }

    public static function domainState(string $claimStatus): ?string
    {
        return match (strtoupper(trim($claimStatus))) {
            'DENIED' => 'SAFE_T_DENIED',
            'APPROVED' => 'SAFE_T_APPROVED',
            'INFO_REQUESTED' => 'SAFE_T_INFO_REQUESTED',
            'PENDING' => 'SAFE_T_SUBMITTED',
            default => null,
        };
    }

    /** @return array{latest_denial_text:string,latest_denial_fingerprint:string,previous_denial_fingerprint:string} */
    public static function denialContext(array $timeline): array
    {
        $denials = [];
        foreach ($timeline as $event) {
            if (!is_array($event) || ($event['event_type'] ?? '') !== 'SAFE_T_STATUS_OBSERVED') continue;
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            if (strtoupper(trim((string)($payload['claim_status'] ?? ''))) !== 'DENIED') continue;
            $text = trim((string)($payload['decision_text'] ?? ''));
            if ($text === '') continue;
            $fp = trim((string)($payload['decision_fingerprint'] ?? ''));
            if (preg_match('/^[a-f0-9]{64}$/i', $fp) !== 1) {
                $normalized = preg_replace('/\s+/u', ' ', $text) ?? $text;
                $normalized = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
                $fp = hash('sha256', $normalized);
            }
            $denials[] = ['text'=>$text,'fingerprint'=>strtolower($fp)];
        }
        $latest = $denials[count($denials)-1] ?? ['text'=>'','fingerprint'=>''];
        $previous = $denials[count($denials)-2] ?? ['fingerprint'=>''];
        return [
            'latest_denial_text'=>$latest['text'],
            'latest_denial_fingerprint'=>$latest['fingerprint'],
            'previous_denial_fingerprint'=>$previous['fingerprint'],
        ];
    }

    private static function nullableText(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) return null;
        $text = trim((string)$value);
        if ($text === '') return null;
        return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
    }

    private static function nullableHash(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $value = strtolower(trim($value));
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }

    private static function utc(mixed $value): ?string
    {
        if (!is_scalar($value) || trim((string)$value) === '') return null;
        try {
            return (new DateTimeImmutable((string)$value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            throw new InvalidArgumentException('Invalid SAFE-T timestamp.');
        }
    }
}
