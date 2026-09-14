<?php
declare(strict_types=1);

final class SvAmazonRecoveryWindow
{
    public const DAYS = 90;

    public static function deadlineAt(array $case): ?DateTimeImmutable
    {
        $basis = self::timestamp($case['refund_at'] ?? null);
        return $basis?->add(new DateInterval('P'.self::DAYS.'D'));
    }

    public static function effectiveDeadlineAt(array $case, string $action = ''): ?DateTimeImmutable
    {
        $recovery = self::deadlineAt($case);
        if (strtoupper(trim($action)) !== 'SAFE_T_APPEAL') return $recovery;
        $explicit = self::timestamp($case['appeal_deadline_at'] ?? null);
        if (!$explicit instanceof DateTimeImmutable) return $recovery;
        if (!$recovery instanceof DateTimeImmutable) return $explicit;
        return $explicit < $recovery ? $explicit : $recovery;
    }

    public static function expired(array $case, DateTimeImmutable $now): bool
    {
        $deadline = self::deadlineAt($case);
        if (!$deadline instanceof DateTimeImmutable) return false;
        return $now->setTimezone(new DateTimeZone('UTC')) >= $deadline;
    }

    public static function nextDailyRetryAt(DateTimeImmutable $now, DateTimeImmutable $deadline): ?DateTimeImmutable
    {
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $deadline = $deadline->setTimezone(new DateTimeZone('UTC'));
        if ($now >= $deadline) return null;
        $next = $now->add(new DateInterval('P1D'));
        return $next <= $deadline ? $next : $deadline;
    }

    private static function timestamp(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        }
        if (!is_string($value) || trim($value) === '') return null;
        $text = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text, new DateTimeZone('UTC'));
        if ($date instanceof DateTimeImmutable && $date->format('Y-m-d H:i:s') === $text) return $date;
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $text) !== 1) return null;
        try { return (new DateTimeImmutable($text))->setTimezone(new DateTimeZone('UTC')); } catch (Throwable) { return null; }
    }
}
