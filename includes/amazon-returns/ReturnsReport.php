<?php

declare(strict_types=1);

require_once __DIR__ . '/Enums.php';
require_once __DIR__ . '/EventStore.php';

final class SvAmazonReturnsReport
{
    private const SOURCE = 'SP_API_REPORTS';
    private const BR_MARKETPLACE_ID = 'A2Q3Y263D00KWC';
    private const EVENT_FIELDS = [
        'order_id','order_item_id','return_request_at','return_status','return_quantity',
        'return_reason','in_policy','return_type','resolution','return_delivery_at',
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

    public static function earliestCaseDate(PDO $db): ?string
    {
        $value = $db->query('SELECT MIN(COALESCE(refund_at,seller_debit_at,created_at)) FROM amazon_return_cases')?->fetchColumn();
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return array{value:string,metadata:array<string,mixed>}|null */
    public static function loadCursor(PDO $db, string $key): ?array
    {
        $stmt = $db->prepare('SELECT cursor_value,metadata_json FROM amazon_return_source_cursors WHERE source=:source AND cursor_key=:cursor_key LIMIT 1');
        $stmt->execute([':source'=>self::SOURCE, ':cursor_key'=>self::cursorKey($key)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $metadata = json_decode((string)($row['metadata_json'] ?? '{}'), true);
        return ['value'=>(string)$row['cursor_value'], 'metadata'=>is_array($metadata) ? $metadata : []];
    }

    /** @param array<string,mixed> $metadata */
    public static function saveCursor(PDO $db, string $key, string $value, array $metadata = []): void
    {
        $value = trim($value);
        if ($value === '') throw new InvalidArgumentException('Amazon report cursor value cannot be empty.');
        $stmt = $db->prepare(
            'INSERT INTO amazon_return_source_cursors (source,cursor_key,cursor_value,metadata_json,observed_at) '
            . 'VALUES (:source,:cursor_key,:cursor_value,:metadata_json,UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE cursor_value=VALUES(cursor_value),metadata_json=VALUES(metadata_json),observed_at=UTC_TIMESTAMP()'
        );
        $stmt->execute([
            ':source'=>self::SOURCE,
            ':cursor_key'=>self::cursorKey($key),
            ':cursor_value'=>$value,
            ':metadata_json'=>json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public static function clearCursor(PDO $db, string $key): void
    {
        $stmt = $db->prepare('DELETE FROM amazon_return_source_cursors WHERE source=:source AND cursor_key=:cursor_key');
        $stmt->execute([':source'=>self::SOURCE, ':cursor_key'=>self::cursorKey($key)]);
    }

    /** @param list<array<string,mixed>> $rows @return array{rows:int,matched:int,created:int,events:int,classified:int} */
    public static function persistRows(PDO $db, array $rows, string $documentId, string $evidenceSha256): array
    {
        $result = ['rows'=>count($rows),'matched'=>0,'created'=>0,'events'=>0,'classified'=>0];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $resolved = self::resolveCase($db, $row);
            if ($resolved === null) continue;
            $result['matched']++;
            if ($resolved['created']) $result['created']++;
            SvAmazonReturnEventStore::append($db, self::eventForCase($resolved['id'], $row, $documentId, $evidenceSha256));
            $result['events']++;
            if (self::applyPatch($db, $resolved['id'], $row)) $result['classified']++;
        }
        return $result;
    }

    /** @return array{id:int,created:bool}|null */
    private static function resolveCase(PDO $db, array $row): ?array
    {
        $orderId = trim((string)($row['order_id'] ?? ''));
        $itemId = trim((string)($row['order_item_id'] ?? ''));
        if ($orderId === '') return null;
        if ($itemId !== '') {
            $find = $db->prepare('SELECT id FROM amazon_return_cases WHERE amazon_order_id=:order_id AND amazon_order_item_id=:item_id LIMIT 1');
            $find->execute([':order_id'=>$orderId, ':item_id'=>$itemId]);
            $id = (int)$find->fetchColumn();
            if ($id > 0) return ['id'=>$id,'created'=>false];
        } else {
            $find = $db->prepare('SELECT id FROM amazon_return_cases WHERE amazon_order_id=:order_id ORDER BY id LIMIT 2');
            $find->execute([':order_id'=>$orderId]);
            $ids = $find->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) === 1) return ['id'=>(int)$ids[0],'created'=>false];
            return null;
        }

        $patch = self::casePatch($row);
        $quantity = max(1, (int)($row['order_quantity'] ?? $row['return_quantity'] ?? 1));
        $physical = ($row['return_delivery_at'] ?? null) !== null
            ? SvAmazonReturnPhysicalStatuses::CARRIER_DELIVERED_PENDING_PHYSICAL
            : SvAmazonReturnPhysicalStatuses::NOT_RECEIVED;
        $state = (string)($patch['state'] ?? SvAmazonReturnStates::POLICY_REVIEW_REQUIRED);
        $stmt = $db->prepare(
            'INSERT INTO amazon_return_cases '
            . '(amazon_order_id,amazon_order_item_id,marketplace_id,sku,asin,quantity_ordered,program,refund_initiator,physical_status,state,safe_t_id,created_at,updated_at) '
            . "VALUES (:order_id,:item_id,:marketplace_id,:sku,:asin,:quantity,'UNKNOWN',:initiator,:physical_status,:state,:safe_t_id,UTC_TIMESTAMP(),UTC_TIMESTAMP()) "
            . 'ON DUPLICATE KEY UPDATE id=id'
        );
        $stmt->execute([
            ':order_id'=>$orderId,
            ':item_id'=>$itemId,
            ':marketplace_id'=>self::BR_MARKETPLACE_ID,
            ':sku'=>self::nullable($row['sku'] ?? null),
            ':asin'=>self::nullable($row['asin'] ?? null),
            ':quantity'=>$quantity,
            ':initiator'=>(string)($patch['refund_initiator'] ?? SvAmazonRefundInitiators::UNKNOWN),
            ':physical_status'=>$physical,
            ':state'=>$state,
            ':safe_t_id'=>$patch['safe_t_id'] ?? null,
        ]);
        $find = $db->prepare('SELECT id FROM amazon_return_cases WHERE amazon_order_id=:order_id AND amazon_order_item_id=:item_id LIMIT 1');
        $find->execute([':order_id'=>$orderId, ':item_id'=>$itemId]);
        $id = (int)$find->fetchColumn();
        return $id > 0 ? ['id'=>$id,'created'=>true] : null;
    }

    private static function applyPatch(PDO $db, int $caseId, array $row): bool
    {
        $patch = self::casePatch($row);
        $sets = [];
        $params = [':id'=>$caseId];
        if (isset($patch['refund_initiator'])) {
            $sets[] = "refund_initiator=CASE WHEN refund_initiator='UNKNOWN' THEN :initiator ELSE refund_initiator END";
            $params[':initiator'] = $patch['refund_initiator'];
        }
        if (isset($patch['safe_t_id'])) {
            $sets[] = 'safe_t_id=COALESCE(safe_t_id,:safe_t_id)';
            $params[':safe_t_id'] = $patch['safe_t_id'];
        }
        if (($row['return_delivery_at'] ?? null) !== null) {
            $sets[] = "physical_status=CASE WHEN physical_status IN ('NOT_RECEIVED','IN_TRANSIT') THEN 'CARRIER_DELIVERED_PENDING_PHYSICAL' ELSE physical_status END";
        }
        if (isset($patch['state'])) {
            $sets[] = "state=CASE WHEN state IN ('POLICY_REVIEW_REQUIRED','REFUND_DETECTED','AWAITING_RETURN','SAFE_T_SUBMITTED') THEN :report_state ELSE state END";
            $params[':report_state'] = $patch['state'];
        }
        if ($sets === []) return false;
        $sets[] = 'updated_at=UTC_TIMESTAMP()';
        $stmt = $db->prepare('UPDATE amazon_return_cases SET ' . implode(',', $sets) . ' WHERE id=:id');
        $stmt->execute($params);
        return isset($patch['refund_initiator']);
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
