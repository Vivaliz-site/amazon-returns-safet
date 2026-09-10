<?php
declare(strict_types=1);
require_once __DIR__ . '/TenantPersistence.php';

final class SvAmazonFinancialRefresh
{
    private const SOURCE = 'SP_API';
    private const KEY = 'financial_order_rotation';
    private const RECONCILIATION_SOURCE = 'FINANCIAL';
    private const RECONCILIATION_KEY = 'case_reconciliation_rotation';

    public static function nextBatch(SvAmazonTenantPersistence $p, int $limit = 25): array
    {
        $limit = max(1, min(999, $limit));
        $saved = $p->cursors->load(self::SOURCE, self::KEY);
        $meta = is_array($saved) ? $saved['metadata'] : [];
        $after = is_array($saved) ? (string)$saved['value'] : '';
        $orders = $p->cases->financialOrderIdsAfter($after, $limit + 1);
        $wrapped = $after !== '' && $orders === [];
        if ($wrapped) $orders = $p->cases->financialOrderIdsAfter('', $limit + 1);
        return [
            'order_ids'=>array_slice($orders, 0, $limit), 'wrapped'=>$wrapped,
            'has_more'=>count($orders) > $limit,
            'initial_scan_complete'=>(bool)($meta['initial_scan_complete'] ?? false),
            'cycle_attempted'=>$wrapped ? 0 : (int)($meta['cycle_attempted'] ?? 0),
            'cycle_failures'=>$wrapped ? 0 : (int)($meta['cycle_failures'] ?? 0),
        ];
    }

    public static function recordAttempted(SvAmazonTenantPersistence $p, array $batch, int $failures): array
    {
        $orders = is_array($batch['order_ids'] ?? null) ? $batch['order_ids'] : [];
        $totalFailures = max(0, (int)($batch['cycle_failures'] ?? 0)) + max(0, $failures);
        $meta = [
            'has_more'=>(bool)($batch['has_more'] ?? false),
            'cycle_attempted'=>max(0, (int)($batch['cycle_attempted'] ?? 0)) + count($orders),
            'cycle_failures'=>$totalFailures,
            'initial_scan_complete'=>(bool)($batch['initial_scan_complete'] ?? false)
                || (!(bool)($batch['has_more'] ?? false) && $totalFailures === 0),
        ];
        $p->cursors->save(self::SOURCE, self::KEY, $orders === [] ? '0' : (string)$orders[array_key_last($orders)], $meta);
        return $meta;
    }

    public static function initialScanComplete(SvAmazonTenantPersistence $p): bool
    {
        $saved = $p->cursors->load(self::SOURCE, self::KEY);
        return (bool)($saved['metadata']['initial_scan_complete'] ?? false);
    }

    public static function requiresInitialRefresh(SvAmazonTenantPersistence $p): bool
    {
        $saved = $p->cursors->load(self::SOURCE, self::KEY);
        if (!is_array($saved)) return true;
        $meta = is_array($saved['metadata'] ?? null) ? $saved['metadata'] : [];
        if (($meta['initial_scan_complete'] ?? false) === true) return false;
        if (($meta['has_more'] ?? false) === true) return true;
        // A failed complete cycle waits for the normal cadence instead of hammering the API.
        return (int)($meta['cycle_failures'] ?? 0) === 0;
    }

    public static function schedule(array $due, bool $needsInitialRefresh): array
    {
        if ($needsInitialRefresh || in_array('sp_api', $due, true) || in_array('financial', $due, true)) {
            if (!in_array('sp_api', $due, true)) $due[] = 'sp_api';
            $due = array_values(array_filter($due, static fn(string $task): bool => $task !== 'financial'));
            $due[] = 'financial';
        }
        return array_values(array_unique($due));
    }

    public static function safeSchedule(array $due, bool $enabled, SvAmazonTenantPersistence $p): array
    {
        if (!$enabled) return ['due'=>$due, 'gate'=>['status'=>'SKIPPED_DISABLED']];
        try {
            return ['due'=>self::schedule($due, self::requiresInitialRefresh($p)), 'gate'=>['status'=>'OK']];
        } catch (Throwable $e) {
            $due = array_values(array_filter($due, static fn(mixed $task): bool => $task !== 'financial'));
            return ['due'=>$due, 'gate'=>['status'=>'FAILED', 'error_class'=>$e::class]];
        }
    }

    public static function nextReconciliationBatch(SvAmazonTenantPersistence $p, int $limit = 250): array
    {
        $limit = max(1, min(999, $limit));
        $saved = $p->cursors->load(self::RECONCILIATION_SOURCE, self::RECONCILIATION_KEY);
        $raw = is_array($saved) ? trim((string)$saved['value']) : '0';
        if ($raw === '') $raw = '0';
        if (!ctype_digit($raw)) throw new UnexpectedValueException('Financial reconciliation cursor is invalid.');
        $after = (int)$raw;
        $rows = $p->cases->financialCasesAfter($after, $limit + 1);
        $wrapped = $after > 0 && $rows === [];
        if ($wrapped) { $after = 0; $rows = $p->cases->financialCasesAfter(0, $limit + 1); }
        $cases = array_slice($rows, 0, $limit);
        $cursor = $after;
        foreach ($cases as $case) {
            $id = (int)($case['id'] ?? 0);
            if ($id <= $cursor) throw new UnexpectedValueException('Financial reconciliation case IDs must advance.');
            $cursor = $id;
        }
        return ['cases'=>$cases, 'has_more'=>count($rows) > $limit, 'wrapped'=>$wrapped, 'after_id'=>$after];
    }

    public static function recordReconciliationBatch(SvAmazonTenantPersistence $p, array $batch): void
    {
        $cases = is_array($batch['cases'] ?? null) ? $batch['cases'] : [];
        $last = 0;
        foreach ($cases as $case) $last = max($last, (int)($case['id'] ?? 0));
        $p->cursors->save(self::RECONCILIATION_SOURCE, self::RECONCILIATION_KEY, (string)$last, [
            'has_more'=>(bool)($batch['has_more'] ?? false),
            'wrapped'=>(bool)($batch['wrapped'] ?? false),
            'processed'=>count($cases),
        ]);
    }

    public static function canReconcile(array $refreshResult, bool $initialScanComplete): bool
    {
        return $initialScanComplete
            && ($refreshResult['status'] ?? '') === 'OK'
            && ($refreshResult['rotation_has_more'] ?? false) !== true;
    }
}
