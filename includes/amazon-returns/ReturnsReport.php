<?php

declare(strict_types=1);

require_once __DIR__ . '/Enums.php';
require_once __DIR__ . '/TenantPersistence.php';

final class SvAmazonReturnsReport
{
    private const SOURCE = 'SP_API_REPORTS';
    private const BR_MARKETPLACE_ID = 'A2Q3Y263D00KWC';
    private const EVENT_FIELDS = [
        'order_id','order_item_id','return_request_at','return_status','return_quantity',
        'return_reason','in_policy','return_type','resolution','invoice_number','return_delivery_at',
        'label_paid_by','a_to_z_claim','safe_t_action_reason','safe_t_id','safe_t_state',
        'safe_t_created_at','safe_t_reimbursement_amount','refunded_amount','refund_initiator',
    ];
    /** @return list<array<string,mixed>> */
    public static function parse(string $document): array
    {
        $document = preg_replace('/^\xEF\xBB\xBF/', '', $document) ?? $document;
        $lines = preg_split('/\r\n|\n|\r/', trim($document)) ?: [];
        if ($lines === [] || trim((string)$lines[0]) === '') return [];
        $headerValues = str_getcsv((string)array_shift($lines), "\t");
        $headers = [];
        foreach ($headerValues as $index => $name) {
            $key = self::headerKey((string)$name);
            if ($key !== '') $headers[$key] = $index;
        }
        if (!isset($headers['order id'])) {
            throw new UnexpectedValueException('Amazon returns report is missing Order ID.');
        }

        $rows = [];
        foreach ($lines as $line) {
            if (trim((string)$line) === '') continue;
            $values = str_getcsv((string)$line, "\t");
            $row = self::normalizeRow($headers, $values);
            if ($row['order_id'] !== '') $rows[] = $row;
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    public static function casePatch(array $row): array
    {
        $patch = [];
        $initiator = (string)($row['refund_initiator'] ?? SvAmazonRefundInitiators::UNKNOWN);
        if ($initiator !== SvAmazonRefundInitiators::UNKNOWN && SvAmazonRefundInitiators::isValid($initiator)) {
            $patch['refund_initiator'] = $initiator;
        }
        $safeTId = trim((string)($row['safe_t_id'] ?? ''));
        if ($safeTId !== '' && preg_match('/^[0-9]+-[0-9]+-[0-9]+$/', $safeTId) === 1) {
            $patch['safe_t_id'] = $safeTId;
        }
        $state = trim((string)($row['safe_t_state'] ?? ''));
        if ($state !== '' && SvAmazonReturnStates::isValid($state)) {
            $patch['state'] = $state;
        }
        return $patch;
    }

    /** @return array<string,mixed> */
    public static function eventForCase(int $caseId, array $row, string $documentId, string $evidenceSha256): array
    {
        if ($caseId < 1) throw new InvalidArgumentException('Amazon return case ID must be positive.');
        $documentId = trim($documentId);
        if ($documentId === '') throw new InvalidArgumentException('Amazon report document ID is required.');
        if (preg_match('/^[a-f0-9]{64}$/i', $evidenceSha256) !== 1) {
            throw new InvalidArgumentException('Amazon report evidence hash is invalid.');
        }
        $orderId = trim((string)($row['order_id'] ?? ''));
        $itemId = trim((string)($row['order_item_id'] ?? ''));
        if ($orderId === '') throw new InvalidArgumentException('Amazon report row order ID is required.');
        $occurred = (string)($row['return_request_at'] ?? $row['safe_t_created_at'] ?? gmdate('Y-m-d H:i:s'));
        $payload = [];
        foreach (self::EVENT_FIELDS as $field) {
            if (array_key_exists($field, $row)) $payload[$field] = $row[$field];
        }
        return [
            'case_id'=>$caseId,
            'event_type'=>'RETURN_REPORT_OBSERVED',
            'source'=>self::SOURCE,
            'source_event_id'=>$documentId,
            'idempotency_key'=>hash('sha256', implode('|', ['report',$documentId,$orderId,$itemId ?: 'unresolved',$occurred,$row['safe_t_id'] ?? 'none'])),
            'occurred_at'=>$occurred,
            'payload'=>$payload + ['financial_truth'=>false],
            'evidence_sha256'=>strtolower($evidenceSha256),
        ];
    }

    /** @return array{from:DateTimeImmutable,to:DateTimeImmutable} */
    public static function nextWindow(?string $highWaterMark, ?string $earliestObserved, DateTimeImmutable $now): array
    {
        $timezone = new DateTimeZone('UTC');
        $now = $now->setTimezone($timezone);
        $from = self::utcDate($highWaterMark);
        if (!$from instanceof DateTimeImmutable) {
            $from = self::utcDate($earliestObserved);
            $from = $from instanceof DateTimeImmutable ? $from->sub(new DateInterval('P2D')) : $now->sub(new DateInterval('P2D'));
        }
        if ($from >= $now) $from = $now->sub(new DateInterval('P2D'));
        $maxTo = $from->add(new DateInterval('P29D'));
        return ['from'=>$from, 'to'=>$maxTo < $now ? $maxTo : $now];
    }

    public static function earliestCaseDate(SvAmazonTenantPersistence $target): ?string
    {
        return $target->cases->earliestObservedDate();
    }

    /** @return array{value:string,metadata:array<string,mixed>}|null */
    public static function loadCursor(SvAmazonTenantPersistence $target, string $key): ?array
    {
        return $target->cursors->load(self::SOURCE, self::cursorKey($key));
    }

    /** @param array<string,mixed> $metadata */
    public static function saveCursor(SvAmazonTenantPersistence $target, string $key, string $value, array $metadata = []): void
    {
        $value=trim($value);
        if($value==='')throw new InvalidArgumentException('Amazon report cursor value cannot be empty.');
        $target->cursors->save(self::SOURCE,self::cursorKey($key),$value,$metadata);
    }

    public static function clearCursor(SvAmazonTenantPersistence $target, string $key): void
    {
        $target->cursors->clear(self::SOURCE,self::cursorKey($key));
    }

    /** @param list<array<string,mixed>> $rows @return array{rows:int,matched:int,created:int,events:int,classified:int} */
    public static function persistRows(SvAmazonTenantPersistence $target, array $rows, string $documentId, string $evidenceSha256): array
    {
        return self::persistRowsScoped($target,$rows,$documentId,$evidenceSha256);
    }

    /** @param list<array<string,mixed>> $rows @return array{rows:int,matched:int,created:int,events:int,classified:int} */
    private static function persistRowsScoped(
        SvAmazonTenantPersistence $p,
        array $rows,
        string $documentId,
        string $evidenceSha256
    ): array {
        $result = ['rows'=>count($rows),'matched'=>0,'created'=>0,'events'=>0,'classified'=>0];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $resolved = self::resolveCaseScoped($p, $row);
            if ($resolved === null) continue;
            $result['matched']++;
            if ($resolved['created']) $result['created']++;
            $p->events->append(self::eventForCase(
                $resolved['id'], $row, $documentId, $evidenceSha256
            ));
            $result['events']++;
            if (self::applyPatchScoped($p->cases, $resolved['id'], $row)) $result['classified']++;
        }
        return $result;
    }

    /** @return array{id:int,created:bool}|null */
    private static function resolveCaseScoped(SvAmazonTenantPersistence $p, array $row): ?array
    {
        $orderId = trim((string)($row['order_id'] ?? ''));
        $itemId = trim((string)($row['order_item_id'] ?? ''));
        if ($orderId === '') return null;
        $existing = $itemId !== ''
            ? $p->cases->findByOrderItem($orderId, $itemId)
            : $p->cases->findSingleByOrder($orderId);
        if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
            return ['id'=>(int)$existing['id'],'created'=>false];
        }
        if ($itemId === '') return null;

        $patch = self::casePatch($row);
        $quantity = max(1, (int)($row['order_quantity'] ?? $row['return_quantity'] ?? 1));
        $physical = ($row['return_delivery_at'] ?? null) !== null
            ? SvAmazonReturnPhysicalStatuses::CARRIER_DELIVERED_PENDING_PHYSICAL
            : SvAmazonReturnPhysicalStatuses::NOT_RECEIVED;
        $marketplace = trim((string)($row['marketplace_id'] ?? ''));
        if ($marketplace === '') $marketplace = $p->cases->marketplaceId();
        $id = $p->cases->upsertOrderItem([
            'amazon_order_id'=>$orderId,
            'amazon_order_item_id'=>$itemId,
            'marketplace_id'=>$marketplace,
            'sku'=>self::nullable($row['sku'] ?? null),
            'asin'=>self::nullable($row['asin'] ?? null),
            'quantity_ordered'=>$quantity,
            'program'=>SvAmazonReturnPrograms::UNKNOWN,
            'refund_initiator'=>(string)($patch['refund_initiator'] ?? SvAmazonRefundInitiators::UNKNOWN),
            'physical_status'=>$physical,
            'state'=>(string)($patch['state'] ?? SvAmazonReturnStates::POLICY_REVIEW_REQUIRED),
            'safe_t_id'=>$patch['safe_t_id'] ?? null,
        ]);
        return ['id'=>$id,'created'=>true];
    }

    private static function applyPatchScoped(
        SvAmazonReturnCaseRepository $cases,
        int $caseId,
        array $row
    ): bool {
        $case = $cases->find($caseId);
        if (!is_array($case)) throw new RuntimeException('Scoped return case disappeared.');
        $sourcePatch = self::casePatch($row);
        $patch = [];
        if (isset($sourcePatch['refund_initiator'])
            && (string)($case['refund_initiator'] ?? SvAmazonRefundInitiators::UNKNOWN)
                === SvAmazonRefundInitiators::UNKNOWN) {
            $patch['refund_initiator'] = $sourcePatch['refund_initiator'];
        }
        if (isset($sourcePatch['safe_t_id']) && trim((string)($case['safe_t_id'] ?? '')) === '') {
            $patch['safe_t_id'] = $sourcePatch['safe_t_id'];
        }
        if (($row['return_delivery_at'] ?? null) !== null
            && in_array((string)($case['physical_status'] ?? ''), [
                SvAmazonReturnPhysicalStatuses::NOT_RECEIVED,
                SvAmazonReturnPhysicalStatuses::IN_TRANSIT,
            ], true)) {
            $patch['physical_status'] = SvAmazonReturnPhysicalStatuses::CARRIER_DELIVERED_PENDING_PHYSICAL;
        }
        if (isset($sourcePatch['state']) && in_array((string)($case['state'] ?? ''), [
            SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,
            SvAmazonReturnStates::REFUND_DETECTED,
            SvAmazonReturnStates::AWAITING_RETURN,
            SvAmazonReturnStates::SAFE_T_SUBMITTED,
        ], true)) {
            $patch['state'] = $sourcePatch['state'];
        }
        if ($patch !== []) $cases->update($caseId, $patch);
        return isset($sourcePatch['refund_initiator']);
    }

    private static function cursorKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 64 || preg_match('/^[a-z0-9_]+$/', $key) !== 1) {
            throw new InvalidArgumentException('Amazon report cursor key is invalid.');
        }
        return $key;
    }

    private static function utcDate(?string $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') return null;
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private static function nullable(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    /** @param array<string,int> $headers @param list<string> $values @return array<string,mixed> */
    private static function normalizeRow(array $headers, array $values): array
    {
        $resolution = self::value($headers, $values, 'resolution');
        $aToZ = strtoupper(self::value($headers, $values, 'a-to-z claim'));
        $initiator = $aToZ === 'Y'
            ? SvAmazonRefundInitiators::A_TO_Z
            : (strcasecmp($resolution, 'RefundAtFirstScan') === 0
                ? SvAmazonRefundInitiators::AMAZON_AUTOMATIC
                : SvAmazonRefundInitiators::UNKNOWN);
        $safeTId = self::value($headers, $values, 'safet claim id');
        return [
            'order_id'=>self::value($headers, $values, 'order id'),
            'order_item_id'=>self::value($headers, $values, 'order item id'),
            'sku'=>self::value($headers, $values, 'merchant sku'),
            'asin'=>self::value($headers, $values, 'asin'),
            'order_quantity'=>self::quantity(self::value($headers, $values, 'order quantity')),
            'return_request_at'=>self::date(self::value($headers, $values, 'return request date')),
            'return_status'=>self::value($headers, $values, 'return request status'),
            'return_quantity'=>self::quantity(self::value($headers, $values, 'return quantity')),
            'return_reason'=>self::value($headers, $values, 'return reason'),
            'in_policy'=>strtoupper(self::value($headers, $values, 'in policy')) === 'Y',
            'return_type'=>self::value($headers, $values, 'return type'),
            'resolution'=>$resolution,
            'invoice_number'=>self::value($headers, $values, 'invoice number'),
            'return_delivery_at'=>self::date(self::value($headers, $values, 'return delivery date')),
            'label_paid_by'=>self::value($headers, $values, 'label to be paid by'),
            'a_to_z_claim'=>$aToZ === 'Y',
            'safe_t_action_reason'=>self::value($headers, $values, 'safet action reason'),
            'safe_t_id'=>$safeTId,
            'safe_t_state'=>self::safeTState(self::value($headers, $values, 'safet claim state'), $safeTId),
            'safe_t_created_at'=>self::date(self::value($headers, $values, 'safet claim creation time')),
            'safe_t_reimbursement_amount'=>self::money(self::value($headers, $values, 'safet claim reimbursement amount')),
            'refunded_amount'=>self::money(self::value($headers, $values, 'refunded amount')),
            'refund_initiator'=>$initiator,
        ];
    }

    /** @param array<string,int> $headers @param list<string> $values */
    private static function value(array $headers, array $values, string $name): string
    {
        $index = $headers[self::headerKey($name)] ?? null;
        return is_int($index) ? trim((string)($values[$index] ?? '')) : '';
    }

    private static function headerKey(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return mb_strtolower($value, 'UTF-8');
    }

    private static function date(string $value): ?string
    {
        if ($value === '') return null;
        $timezone = new DateTimeZone('UTC');
        foreach (['!d-M-Y H:i:s', '!d-M-Y', '!Y-m-d H:i:s', '!Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            if ($date instanceof DateTimeImmutable) return $date->format('Y-m-d H:i:s');
        }
        try {
            return (new DateTimeImmutable($value, $timezone))->setTimezone($timezone)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private static function quantity(string $value): int
    {
        $quantity = filter_var($value, FILTER_VALIDATE_INT);
        return $quantity === false ? 0 : max(0, $quantity);
    }

    private static function money(string $value): ?string
    {
        $value = preg_replace('/[^0-9,\.\-]/u', '', trim($value)) ?? '';
        if ($value === '') return null;
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = strrpos($value, ',') > strrpos($value, '.')
                ? str_replace(',', '.', str_replace('.', '', $value))
                : str_replace(',', '', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }
        return is_numeric($value) ? number_format((float)$value, 2, '.', '') : null;
    }

    private static function safeTState(string $value, string $safeTId): ?string
    {
        $status = mb_strtoupper(trim($value), 'UTF-8');
        if ($status !== '') {
            if (str_contains($status, 'APPROV')) return SvAmazonReturnStates::SAFE_T_APPROVED;
            if (str_contains($status, 'DENIED') || str_contains($status, 'NEGAD') || str_contains($status, 'REJEIT')) {
                return SvAmazonReturnStates::SAFE_T_DENIED;
            }
            if (str_contains($status, 'INFO')) return SvAmazonReturnStates::SAFE_T_INFO_REQUESTED;
            if (str_contains($status, 'APPEAL') || str_contains($status, 'RECURSO')) {
                return SvAmazonReturnStates::APPEAL_REQUIRED;
            }
        }
        return trim($safeTId) !== '' ? SvAmazonReturnStates::SAFE_T_SUBMITTED : null;
    }
}
