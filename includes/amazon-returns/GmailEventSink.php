<?php

declare(strict_types=1);

require_once __DIR__ . '/Enums.php';
require_once __DIR__ . '/EventStore.php';

final class SvAmazonGmailEventSink
{
    public const UNRESOLVED_ITEM_ID = 'UNRESOLVED_EMAIL';
    public const BR_MARKETPLACE_ID = 'A2Q3Y263D00KWC';

    /** @return array<string,mixed> */
    public static function casePatch(array $event): array
    {
        $type = strtoupper(trim((string)($event['event_type'] ?? '')));
        if ($type === 'SAFE_T_REGISTERED_EMAIL') {
            return [
                'safe_t_id'=>trim((string)($event['safe_t_id'] ?? '')),
                'state'=>SvAmazonReturnStates::SAFE_T_SUBMITTED,
            ];
        }
        if ($type === 'SAFE_T_UPDATED_EMAIL') {
            return ['safe_t_id'=>trim((string)($event['safe_t_id'] ?? ''))];
        }
        if ($type === 'SAFE_T_EMAIL_REVIEW_RESPONSE') {
            $patch = ['safe_t_id'=>trim((string)($event['safe_t_id'] ?? ''))];
            $outcome = strtoupper(trim((string)($event['review_outcome'] ?? 'UNKNOWN')));
            if ($outcome === 'APPROVED') $patch['state'] = SvAmazonReturnStates::CREDIT_PENDING;
            elseif ($outcome === 'DENIED') $patch['state'] = SvAmazonReturnStates::SUPPORT_ESCALATION;
            return $patch;
        }
        return ['state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED];
    }

    /** @param list<string> $resolvedItemIds */
    public static function targetItemId(array $resolvedItemIds): string
    {
        $items = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $resolvedItemIds
        ), static fn(string $value): bool => $value !== '' && $value !== self::UNRESOLVED_ITEM_ID)));
        return count($items) === 1 ? $items[0] : self::UNRESOLVED_ITEM_ID;
    }

    public static function persist(PDO $db, array $event): int
    {
        $orderId = trim((string)($event['order_id'] ?? ''));
        if ($orderId === '') throw new InvalidArgumentException('Gmail event order_id is required.');
        [$caseId, $itemId] = self::ensureTargetCase($db, $orderId);
        $patch = self::casePatch($event);
        if ($itemId !== self::UNRESOLVED_ITEM_ID && ($patch['state'] ?? null) === SvAmazonReturnStates::POLICY_REVIEW_REQUIRED) {
            unset($patch['state']);
        }
        self::applyPatch($db, $caseId, $patch);

        $occurredAt = trim((string)($event['occurred_at'] ?? ''));
        if ($occurredAt === '') $occurredAt = gmdate('Y-m-d H:i:s');

        $payload = [
            'order_id'=>$orderId,
            'safe_t_id'=>$event['safe_t_id'] ?? null,
            'amount'=>$event['amount'] ?? null,
            'currency'=>$event['currency'] ?? null,
            'review_outcome'=>$event['review_outcome'] ?? null,
            'financial_truth'=>false,
            'content_sha256'=>$event['content_sha256'] ?? null,
        ];
        return SvAmazonReturnEventStore::append($db, [
            'case_id'=>$caseId,
            'event_type'=>(string)$event['event_type'],
            'source'=>'GMAIL',
            'source_event_id'=>(string)($event['source_event_id'] ?? $event['message_id'] ?? ''),
            'idempotency_key'=>(string)$event['idempotency_key'],
            'occurred_at'=>$occurredAt,
            'payload'=>$payload,
            'evidence_sha256'=>isset($event['content_sha256']) ? (string)$event['content_sha256'] : null,
        ]);
    }

    /** @return array{0:int,1:string} */
    private static function ensureTargetCase(PDO $db, string $orderId): array
    {
        $known = $db->prepare('SELECT id, amazon_order_item_id FROM amazon_return_cases WHERE amazon_order_id=:order_id ORDER BY id');
        $known->execute([':order_id'=>$orderId]);
        $rows = $known->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $resolved = [];
        foreach ($rows as $row) {
            $item = trim((string)($row['amazon_order_item_id'] ?? ''));
            if ($item !== '' && $item !== self::UNRESOLVED_ITEM_ID) $resolved[] = $item;
        }
        $target = self::targetItemId($resolved);
        if ($target !== self::UNRESOLVED_ITEM_ID) {
            foreach ($rows as $row) {
                if ((string)($row['amazon_order_item_id'] ?? '') === $target) {
                    $id = (int)($row['id'] ?? 0);
                    if ($id > 0) return [$id, $target];
                }
            }
        }

        $stmt = $db->prepare(
            "INSERT INTO amazon_return_cases "
            . "(amazon_order_id,amazon_order_item_id,marketplace_id,quantity_ordered,program,refund_initiator,physical_status,state,created_at,updated_at) "
            . "VALUES (:order_id,:item_id,:marketplace_id,1,'UNKNOWN','UNKNOWN','NOT_RECEIVED','POLICY_REVIEW_REQUIRED',UTC_TIMESTAMP(),UTC_TIMESTAMP()) "
            . "ON DUPLICATE KEY UPDATE updated_at=updated_at"
        );
        $stmt->execute([':order_id'=>$orderId,':item_id'=>self::UNRESOLVED_ITEM_ID,':marketplace_id'=>self::BR_MARKETPLACE_ID]);
        $find = $db->prepare('SELECT id FROM amazon_return_cases WHERE amazon_order_id=:order_id AND amazon_order_item_id=:item_id LIMIT 1');
        $find->execute([':order_id'=>$orderId,':item_id'=>self::UNRESOLVED_ITEM_ID]);
        $id = (int)$find->fetchColumn();
        if ($id < 1) throw new RuntimeException('Unable to resolve Gmail placeholder case.');
        return [$id, self::UNRESOLVED_ITEM_ID];
    }

    /** @param array<string,mixed> $patch */
    private static function applyPatch(PDO $db, int $caseId, array $patch): void
    {
        $sets = [];
        $params = [':id'=>$caseId];
        if (isset($patch['safe_t_id']) && trim((string)$patch['safe_t_id']) !== '') {
            $sets[] = 'safe_t_id=:safe_t_id';
            $params[':safe_t_id'] = trim((string)$patch['safe_t_id']);
        }
        if (isset($patch['state']) && is_string($patch['state']) && SvAmazonReturnStates::isValid($patch['state'])) {
            $sets[] = 'state=:state';
            $params[':state'] = $patch['state'];
        }
        if ($sets === []) return;
        $sets[] = 'updated_at=UTC_TIMESTAMP()';
        $stmt = $db->prepare('UPDATE amazon_return_cases SET ' . implode(',', $sets) . ' WHERE id=:id');
        $stmt->execute($params);
    }
}
