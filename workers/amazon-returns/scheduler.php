<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__ . '/../../includes/amazon-returns/TenantOutbox.php';

final class SvAmazonReturnsScheduler
{
    public function __construct(private ?SvAmazonSafeTDecisionEngine $engine = null) { $this->engine ??= new SvAmazonSafeTDecisionEngine(); }

    public static function isWriteAction(array $decision): bool
    {
        return in_array((string)($decision['action'] ?? ''), ['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'], true);
    }

    public static function dependencyForAction(string $action): string
    {
        return in_array(strtoupper(trim($action)), ['SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY'], true) ? 'gmail' : 'seller_central_bridge';
    }

    public function schedule(
        SvAmazonTenantReturnsOutbox $target,
        array $case,
        array $timeline,
        array $policy
    ): array {
        $decision = $this->engine->nextAction($case, $timeline, $policy);
        if (!self::isWriteAction($decision)) return ['decision'=>$decision,'outbox_id'=>null];
        $key = (string)($decision['idempotency_key'] ?? '');
        if ($key === '') throw new LogicException('Write decision missing idempotency key.');
        $caseId = (int)($case['id'] ?? 0);
        $payload = [
            'case_id'=>$caseId,
            'order_id'=>(string)($case['amazon_order_id'] ?? ''),
            'safe_t_id'=>$case['safe_t_id'] ?? null,
            'decision'=>$decision,
        ];
        if (isset($case['appeal_deadline_at'])) $payload['deadline_at'] = $case['appeal_deadline_at'];
        $outboxId=$target->enqueue(
            (string)$decision['action'],$caseId,$payload,$key
        );
        return ['decision'=>$decision,'outbox_id'=>$outboxId];
    }
}
