<?php
declare(strict_types=1);

/** Append-only financial observations, ordered by observation rather than posting time. */
final class SvAmazonFinancialObservations
{
    public static function signature(array $transaction): string
    {
        return hash('sha256', json_encode(self::canonical($transaction), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public static function identity(array $transaction): string
    {
        $id = trim((string)($transaction['transaction_id'] ?? ''));
        return $id !== '' ? $id : 'anonymous-' . self::signature($transaction);
    }

    public static function key(int $caseId, array $transaction, int $previousEventId = 0): string
    {
        if ($caseId < 1 || $previousEventId < 0) throw new InvalidArgumentException('Invalid financial observation scope.');
        // A -> B -> A must append a new A rather than deduplicating against its old version.
        return hash('sha256', implode('|', ['financial-observation-v2', $caseId, self::identity($transaction), $previousEventId, self::signature($transaction)]));
    }

    /** @return array<string,array<string,mixed>> */
    public static function latestEvents(array $events): array
    {
        $latest = [];
        foreach ($events as $event) {
            if (!is_array($event) || ($event['event_type'] ?? '') !== 'FINANCIAL_TRANSACTION_OBSERVED') continue;
            $tx = $event['payload']['transaction'] ?? null;
            if (!is_array($tx)) continue;
            $key = self::identity($tx);
            if (!isset($latest[$key]) || self::rank($event) > self::rank($latest[$key])) $latest[$key] = $event;
        }
        return $latest;
    }

    private static function rank(array $event): array
    {
        $created = trim((string)($event['created_at'] ?? ''));
        try { $stamp = $created !== '' ? (new DateTimeImmutable($created, new DateTimeZone('UTC')))->getTimestamp() : 0; }
        catch (Exception) { $stamp = 0; }
        return [$stamp, (int)($event['id'] ?? 0)];
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        $list = array_is_list($value);
        if (!$list) ksort($value, SORT_STRING);
        foreach ($value as $key => $child) $value[$key] = self::canonical($child);
        if ($list && $value !== [] && is_array($value[0]) && isset($value[0]['name'], $value[0]['value'])) {
            usort($value, static fn(array $a, array $b): int => [$a['name'], $a['value']] <=> [$b['name'], $b['value']]);
        }
        return $value;
    }
}
