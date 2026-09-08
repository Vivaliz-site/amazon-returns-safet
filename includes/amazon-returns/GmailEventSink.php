<?php
declare(strict_types=1);

require_once __DIR__ . '/Enums.php';
require_once __DIR__ . '/AmazonRequestedWait.php';
require_once __DIR__ . '/TenantPersistence.php';

final class SvAmazonGmailEventSink
{
    public const UNRESOLVED_ITEM_ID = 'UNRESOLVED_EMAIL';
    public const BR_MARKETPLACE_ID = 'A2Q3Y263D00KWC';

    /** @return array<string,mixed> */
    public static function casePatch(array $event): array
    {
        $type = strtoupper(trim((string)($event['event_type'] ?? '')));
        if ($type === 'REFUND_ISSUED_EMAIL') {
            $patch = ['state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED];
            $occurredAt = trim((string)($event['occurred_at'] ?? ''));
            if ($occurredAt !== '') $patch['refund_at'] = $occurredAt;
            $amount = $event['amount'] ?? null;
            if (is_numeric($amount) && (float)$amount >= 0) {
                $patch['refund_amount'] = number_format((float)$amount, 2, '.', '');
            }
            return $patch;
        }
        if ($type === 'SAFE_T_REGISTERED_EMAIL') {
            return ['safe_t_id'=>trim((string)($event['safe_t_id'] ?? '')),'state'=>SvAmazonReturnStates::SAFE_T_SUBMITTED];
        }
        if ($type === 'SAFE_T_UPDATED_EMAIL') {
            return ['safe_t_id'=>trim((string)($event['safe_t_id'] ?? ''))];
        }
        if ($type === 'SAFE_T_EMAIL_REVIEW_RESPONSE') {
            $patch = ['safe_t_id'=>trim((string)($event['safe_t_id'] ?? ''))];
            $outcome = strtoupper(trim((string)($event['review_outcome'] ?? 'UNKNOWN_AMBIGUOUS')));
            $resume=SvAmazonRequestedWait::timestamp($event['review_next_action_at']??null);
            if($resume!==null)$patch['next_action_at']=$resume->format('Y-m-d H:i:s');
            $patch['state'] = $outcome === 'APPROVED'
                ? SvAmazonReturnStates::CREDIT_PENDING
                : SvAmazonReturnStates::EMAIL_REVIEW_RESPONSE_PENDING;
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

    public static function persist(SvAmazonTenantPersistence $p, array $event): int
    {
        $orderId = trim((string)($event['order_id'] ?? ''));
        if ($orderId === '') throw new InvalidArgumentException('Gmail event order_id is required.');
        [$caseId,$itemId] = self::ensureTargetCaseScoped($p, $orderId, $event);
        $patch = self::casePatch($event);
        if ($itemId !== self::UNRESOLVED_ITEM_ID
            && ($patch['state'] ?? null) === SvAmazonReturnStates::POLICY_REVIEW_REQUIRED) {
            unset($patch['state']);
        }
        self::applyPatchScoped($p->cases, $caseId, $patch);

        $occurredAt = trim((string)($event['occurred_at'] ?? ''));
        if ($occurredAt === '') $occurredAt = gmdate('Y-m-d H:i:s');
        $sourceEventId = trim((string)($event['source_event_id'] ?? $event['message_id'] ?? ''));
        return $p->events->append([
            'case_id'=>$caseId,
            'event_type'=>(string)$event['event_type'],
            'source'=>'GMAIL',
            'source_event_id'=>$sourceEventId !== '' ? $sourceEventId : null,
            'idempotency_key'=>(string)$event['idempotency_key'],
            'occurred_at'=>$occurredAt,
            'payload'=>self::payload($event, $orderId),
            'evidence_sha256'=>isset($event['content_sha256']) ? (string)$event['content_sha256'] : null,
        ]);
    }

    /** @return array{0:int,1:string} */
    private static function ensureTargetCaseScoped(
        SvAmazonTenantPersistence $p,
        string $orderId,
        array $event
    ): array {
        $rows = $p->cases->forOrder($orderId);
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
                    if ($id > 0) return [$id,$target];
                }
            }
        }
        $existing = $p->cases->findByOrderItem($orderId, self::UNRESOLVED_ITEM_ID);
        if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
            return [(int)$existing['id'], self::UNRESOLVED_ITEM_ID];
        }
        $marketplace = trim((string)($event['marketplace_id'] ?? ''));
        if ($marketplace === '') $marketplace = $p->cases->marketplaceId();
        $id = $p->cases->upsertOrderItem([
            'amazon_order_id'=>$orderId,
            'amazon_order_item_id'=>self::UNRESOLVED_ITEM_ID,
            'marketplace_id'=>$marketplace,
            'quantity_ordered'=>1,
            'program'=>SvAmazonReturnPrograms::UNKNOWN,
            'refund_initiator'=>SvAmazonRefundInitiators::UNKNOWN,
            'physical_status'=>SvAmazonReturnPhysicalStatuses::NOT_RECEIVED,
            'state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,
        ]);
        return [$id,self::UNRESOLVED_ITEM_ID];
    }

    /** @param array<string,mixed> $patch */
    private static function applyPatchScoped(
        SvAmazonReturnCaseRepository $cases,
        int $caseId,
        array $patch
    ): void {
        if (isset($patch['safe_t_id']) && trim((string)$patch['safe_t_id']) === '') {
            unset($patch['safe_t_id']);
        }
        if (isset($patch['state'])
            && (!is_string($patch['state']) || !SvAmazonReturnStates::isValid($patch['state']))) {
            unset($patch['state']);
        }
        if ($patch !== []) $cases->update($caseId, $patch);
    }

    /** @return array<string,mixed> */
    private static function payload(array $event, string $orderId): array
    {
        return [
            'order_id'=>$orderId,
            'safe_t_id'=>$event['safe_t_id'] ?? null,
            'amount'=>$event['amount'] ?? null,
            'currency'=>$event['currency'] ?? null,
            'refund_at'=>strtoupper(trim((string)($event['event_type'] ?? ''))) === 'REFUND_ISSUED_EMAIL'
                ? ($event['occurred_at'] ?? null) : null,
            'refund_amount'=>strtoupper(trim((string)($event['event_type'] ?? ''))) === 'REFUND_ISSUED_EMAIL'
                ? ($event['amount'] ?? null) : null,
            'review_outcome'=>$event['review_outcome'] ?? null,
            'review_suggested_action'=>$event['review_suggested_action'] ?? null,
            'review_reason'=>$event['review_reason'] ?? null,
            'review_next_action_at'=>$event['review_next_action_at'] ?? null,
            'review_excerpt'=>$event['review_excerpt'] ?? null,
            'gmail_thread_id'=>$event['thread_id'] ?? null,
            'gmail_rfc_message_id'=>$event['rfc_message_id'] ?? null,
            'financial_truth'=>false,
            'content_sha256'=>$event['content_sha256'] ?? null,
        ];
    }


}
