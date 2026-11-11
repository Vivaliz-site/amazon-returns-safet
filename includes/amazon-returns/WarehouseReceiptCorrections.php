<?php
declare(strict_types=1);

/**
 * An immutable warehouse intake may be rescinded after an operator records
 * an incorrect physical receipt. The original evidence stays in the ledger;
 * only the linked, later, explicitly sourced correction changes projections.
 */
final class SvAmazonWarehouseReceiptCorrections
{
    /** @param list<array<string,mixed>> $timeline @return array<int,true> */
    public static function voidedReceiptIds(array $timeline, int $caseId): array
    {
        if ($caseId < 1) return [];
        $receipts = [];
        foreach ($timeline as $event) {
            if (!is_array($event) || (int)($event['case_id'] ?? 0) !== $caseId
                || ($event['event_type'] ?? '') !== 'PHYSICAL_RECEIVED'
                || ($event['source'] ?? '') !== 'WAREHOUSE') continue;
            $id = (int)($event['id'] ?? 0);
            $payload = $event['payload'] ?? null;
            if ($id < 1 || !is_array($payload)
                || trim((string)($event['source_event_id'] ?? '')) === ''
                || (int)($payload['operator_id'] ?? 0) < 1) continue;
            $receipts[$id] = $event;
        }

        $voided = [];
        foreach ($timeline as $event) {
            if (!is_array($event) || (int)($event['case_id'] ?? 0) !== $caseId
                || ($event['event_type'] ?? '') !== 'PHYSICAL_RECEIPT_VOIDED'
                || ($event['source'] ?? '') !== 'WAREHOUSE'
                || trim((string)($event['source_event_id'] ?? '')) === '') continue;
            $id = (int)($event['id'] ?? 0);
            $payload = $event['payload'] ?? null;
            if (!is_array($payload) || (int)($payload['operator_id'] ?? 0) < 1) continue;
            $target = filter_var($payload['receipt_event_id'] ?? null, FILTER_VALIDATE_INT);
            if ($target === false || $target < 1 || !isset($receipts[$target])) continue;
            $receiptAt = (string)($receipts[$target]['occurred_at'] ?? '');
            $correctionAt = (string)($event['occurred_at'] ?? '');
            if ($receiptAt === '' || $correctionAt === '' || $correctionAt < $receiptAt) continue;
            $voided[$target] = true;
        }
        return $voided;
    }

    /** @param list<array<string,mixed>> $timeline @return list<array<string,mixed>> */
    public static function effectiveTimeline(array $timeline, int $caseId): array
    {
        $voided = self::voidedReceiptIds($timeline, $caseId);
        if ($voided === []) return $timeline;
        return array_values(array_filter($timeline, static function (mixed $event) use ($voided): bool {
            if (!is_array($event)) return true;
            return ($event['event_type'] ?? '') !== 'PHYSICAL_RECEIVED'
                || !isset($voided[(int)($event['id'] ?? 0)]);
        }));
    }
}
