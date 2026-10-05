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
    public static function casePatch(array $event,array $existing=[]): array
    {
        $type = strtoupper(trim((string)($event['event_type'] ?? '')));
        if ($type === 'REFUND_ISSUED_EMAIL') {
            $patch = ['state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED];
            $occurredAt = trim((string)($event['occurred_at'] ?? ''));
            if ($occurredAt !== '' && trim((string)($existing['refund_at'] ?? '')) === '') {
                $patch['refund_at'] = $occurredAt;
            }
            $amount = $event['amount'] ?? null;
            $existingAmount = $existing['refund_amount'] ?? null;
            if (is_numeric($amount) && (float)$amount >= 0
                && (!is_numeric($existingAmount) || (float)$existingAmount <= 0)) {
                $patch['refund_amount'] = number_format((float)$amount, 2, '.', '');
            }
            $initiator = strtoupper(trim((string)($event['refund_initiator'] ?? '')));
            $existingInitiator = strtoupper(trim((string)($existing['refund_initiator'] ?? SvAmazonRefundInitiators::UNKNOWN)));
            if ($initiator !== '' && $initiator !== SvAmazonRefundInitiators::UNKNOWN
                && SvAmazonRefundInitiators::isValid($initiator)
                && ($existingInitiator === '' || $existingInitiator === SvAmazonRefundInitiators::UNKNOWN)) {
                $patch['refund_initiator'] = $initiator;
            }
            $program = strtoupper(trim((string)($event['program'] ?? '')));
            $existingProgram = strtoupper(trim((string)($existing['program'] ?? SvAmazonReturnPrograms::UNKNOWN)));
            if ($program !== '' && $program !== SvAmazonReturnPrograms::UNKNOWN
                && in_array($program, SvAmazonReturnPrograms::all(), true)
                && ($existingProgram === '' || $existingProgram === SvAmazonReturnPrograms::UNKNOWN)) {
                $patch['program'] = $program;
            }
            $quantityOrdered = filter_var($event['quantity_ordered'] ?? null, FILTER_VALIDATE_INT);
            $quantityRefunded = filter_var($event['quantity_refunded'] ?? null, FILTER_VALIDATE_INT);
            $existingOrdered = filter_var($existing['quantity_ordered'] ?? null, FILTER_VALIDATE_INT);
            if ($quantityOrdered !== false && $quantityOrdered > 0
                && (int)($existing['quantity_ordered'] ?? 0) <= 0) {
                $patch['quantity_ordered'] = $quantityOrdered;
            }
            $knownOrdered = $quantityOrdered !== false && $quantityOrdered > 0
                ? $quantityOrdered
                : ($existingOrdered !== false && $existingOrdered > 0 ? $existingOrdered : null);
            if ($knownOrdered !== null
                && $quantityRefunded !== false && $quantityRefunded >= 0 && $quantityRefunded <= $knownOrdered
                && (int)($existing['quantity_refunded'] ?? 0) <= 0) {
                $patch['quantity_refunded'] = $quantityRefunded;
            }
            return $patch;
        }
        if ($type === 'FBA_SHIPMENT_EMAIL') {
            return ['program'=>SvAmazonReturnPrograms::FBA];
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
        $existing=[];
        foreach($p->cases->forOrder($orderId) as $row){
            if((int)($row['id']??0)===$caseId){$existing=$row;break;}
        }
        $patch = self::casePatch($event,$existing);
        if ($itemId !== self::UNRESOLVED_ITEM_ID
            && ($patch['state'] ?? null) === SvAmazonReturnStates::POLICY_REVIEW_REQUIRED) {
            unset($patch['state']);
        }
        self::applyPatchScoped($p->cases, $caseId, $patch);

        $occurredAt = trim((string)($event['occurred_at'] ?? ''));
        if ($occurredAt === '') $occurredAt = gmdate('Y-m-d H:i:s');
        $sourceEventId = trim((string)($event['source_event_id'] ?? $event['message_id'] ?? ''));
        $primaryId = $p->events->append([
            'case_id'=>$caseId,
            'event_type'=>(string)$event['event_type'],
            'source'=>'GMAIL',
            'source_event_id'=>$sourceEventId !== '' ? $sourceEventId : null,
            'idempotency_key'=>(string)$event['idempotency_key'],
            'occurred_at'=>$occurredAt,
            'payload'=>self::payload($event, $orderId),
            'evidence_sha256'=>isset($event['content_sha256']) ? (string)$event['content_sha256'] : null,
        ]);
        $initiatorEvidence = self::refundInitiatorEvidence($caseId, $event, $orderId, $occurredAt, $sourceEventId);
        if ($initiatorEvidence !== null) $p->events->append($initiatorEvidence);
        $programEvidence = self::programEvidence($caseId, $event, $orderId, $occurredAt, $sourceEventId);
        if ($programEvidence !== null) $p->events->append($programEvidence);
        $quantityEvidence = self::refundQuantityEvidence($caseId, $event, $orderId, $occurredAt, $sourceEventId);
        if ($quantityEvidence !== null) $p->events->append($quantityEvidence);
        return $primaryId;
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

    /** @return array<string,mixed>|null */
    private static function refundInitiatorEvidence(
        int $caseId,
        array $event,
        string $orderId,
        string $occurredAt,
        string $sourceEventId
    ): ?array {
        if (strtoupper(trim((string)($event['event_type'] ?? ''))) !== 'REFUND_ISSUED_EMAIL') return null;
        $initiator = strtoupper(trim((string)($event['refund_initiator'] ?? '')));
        if ($initiator === '' || $initiator === SvAmazonRefundInitiators::UNKNOWN
            || !SvAmazonRefundInitiators::isValid($initiator)) return null;
        $sourceIdentity = $sourceEventId !== '' ? $sourceEventId : trim((string)($event['idempotency_key'] ?? ''));
        if ($sourceIdentity === '') return null;
        $evidence = isset($event['content_sha256']) && is_string($event['content_sha256'])
            && preg_match('/^[a-f0-9]{64}$/i', $event['content_sha256']) === 1
            ? strtolower($event['content_sha256']) : null;
        return [
            'case_id'=>$caseId,
            'event_type'=>'REFUND_INITIATOR_CONFIRMED',
            'source'=>'GMAIL',
            'source_event_id'=>$sourceEventId !== '' ? $sourceEventId : null,
            'idempotency_key'=>hash('sha256', implode('|', ['gmail-refund-initiator',$orderId,$sourceIdentity,$initiator])),
            'occurred_at'=>$occurredAt,
            'payload'=>['order_id'=>$orderId,'refund_initiator'=>$initiator,'financial_truth'=>false],
            'evidence_sha256'=>$evidence,
        ];
    }

    /** @return array<string,mixed>|null */
    private static function programEvidence(
        int $caseId,
        array $event,
        string $orderId,
        string $occurredAt,
        string $sourceEventId
    ): ?array {
        if (strtoupper(trim((string)($event['event_type'] ?? ''))) !== 'REFUND_ISSUED_EMAIL') return null;
        $program = strtoupper(trim((string)($event['program'] ?? '')));
        if ($program === '' || $program === SvAmazonReturnPrograms::UNKNOWN
            || !in_array($program, SvAmazonReturnPrograms::all(), true)) return null;
        $sourceIdentity = $sourceEventId !== '' ? $sourceEventId : trim((string)($event['idempotency_key'] ?? ''));
        if ($sourceIdentity === '') return null;
        $evidence = isset($event['content_sha256']) && is_string($event['content_sha256'])
            && preg_match('/^[a-f0-9]{64}$/i', $event['content_sha256']) === 1
            ? strtolower($event['content_sha256']) : null;
        return [
            'case_id'=>$caseId,
            'event_type'=>'PROGRAM_CONFIRMED',
            'source'=>'GMAIL',
            'source_event_id'=>$sourceEventId !== '' ? $sourceEventId : null,
            'idempotency_key'=>hash('sha256', implode('|', ['gmail-refund-program',$orderId,$sourceIdentity,$program])),
            'occurred_at'=>$occurredAt,
            'payload'=>['order_id'=>$orderId,'program'=>$program,'financial_truth'=>false],
            'evidence_sha256'=>$evidence,
        ];
    }

    /** @return array<string,mixed>|null */
    private static function refundQuantityEvidence(
        int $caseId,
        array $event,
        string $orderId,
        string $occurredAt,
        string $sourceEventId
    ): ?array {
        if (strtoupper(trim((string)($event['event_type'] ?? ''))) !== 'REFUND_ISSUED_EMAIL') return null;
        $ordered = filter_var($event['quantity_ordered'] ?? null, FILTER_VALIDATE_INT);
        $refunded = filter_var($event['quantity_refunded'] ?? null, FILTER_VALIDATE_INT);
        if ($refunded === false || $refunded < 1) return null;
        if ($ordered !== false && ($ordered < 1 || $refunded > $ordered)) return null;
        $sourceIdentity = $sourceEventId !== '' ? $sourceEventId : trim((string)($event['idempotency_key'] ?? ''));
        if ($sourceIdentity === '') return null;
        $evidence = isset($event['content_sha256']) && is_string($event['content_sha256'])
            && preg_match('/^[a-f0-9]{64}$/i', $event['content_sha256']) === 1
            ? strtolower($event['content_sha256']) : null;
        return [
            'case_id'=>$caseId,
            'event_type'=>'REFUND_QUANTITY_CONFIRMED',
            'source'=>'GMAIL',
            'source_event_id'=>$sourceEventId !== '' ? $sourceEventId : null,
            'idempotency_key'=>hash('sha256', implode('|', ['gmail-refund-quantity',$orderId,$sourceIdentity,$ordered === false ? 'unknown' : $ordered,$refunded])),
            'occurred_at'=>$occurredAt,
            'payload'=>array_filter([
                'order_id'=>$orderId,
                'quantity_ordered'=>$ordered === false ? null : $ordered,
                'quantity_refunded'=>$refunded,
                'financial_truth'=>false,
            ],static fn(mixed $value,string $key):bool=>$key !== 'quantity_ordered' || $value !== null,ARRAY_FILTER_USE_BOTH),
            'evidence_sha256'=>$evidence,
        ];
    }

    /** @return array<string,mixed> */
    private static function payload(array $event, string $orderId): array
    {
        $tracking=trim((string)($event['tracking_id'] ?? ''));
        $returnTracking=trim((string)($event['return_tracking_id'] ?? ''));
        $carrier=trim((string)($event['carrier'] ?? ''));
        $payload = [
            'order_id'=>$orderId,
            'safe_t_id'=>$event['safe_t_id'] ?? null,
            'amount'=>$event['amount'] ?? null,
            'currency'=>$event['currency'] ?? null,
            'return_tracking_ids'=>$returnTracking!==''?[strtoupper($returnTracking)]:[],
            'customer_tracking_ids'=>$tracking!==''?[$tracking]:[],
            'customer_delivery_carriers'=>$carrier!==''?[$carrier]:[],
            'customer_delivery_confirmed'=>false,
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
        $program = strtoupper(trim((string)($event['program'] ?? '')));
        if ($program !== '') {
            if (!in_array($program, SvAmazonReturnPrograms::all(), true)) {
                throw new UnexpectedValueException('Invalid program in Gmail event.');
            }
            $payload['program']=$program;
        }
        $initiator = strtoupper(trim((string)($event['refund_initiator'] ?? '')));
        if ($initiator !== '') {
            if (!SvAmazonRefundInitiators::isValid($initiator)) {
                throw new UnexpectedValueException('Invalid refund_initiator in Gmail event.');
            }
            $payload['refund_initiator']=$initiator;
        }
        $ordered = filter_var($event['quantity_ordered'] ?? null, FILTER_VALIDATE_INT);
        $refunded = filter_var($event['quantity_refunded'] ?? null, FILTER_VALIDATE_INT);
        if ($ordered !== false && $ordered > 0) $payload['quantity_ordered']=$ordered;
        if ($refunded !== false && $refunded >= 0
            && ($ordered === false || $ordered < 1 || $refunded <= $ordered)) {
            $payload['quantity_refunded']=$refunded;
        }
        return $payload;
    }
}
