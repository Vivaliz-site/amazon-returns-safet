<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__ . '/../../includes/amazon-returns/TenantOutbox.php';
require_once __DIR__ . '/../../includes/amazon-returns/ExternalWritePayload.php';
require_once __DIR__ . '/../../includes/amazon-returns/RecoveryWindow.php';

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
        array $policy,
        ?DateTimeImmutable $now=null
    ): array {
        $decision = $this->engine->nextAction($case, $timeline, $policy, $now);
        return $this->scheduleDecision($target,$case,$decision,$timeline);
    }

    public function scheduleDecision(SvAmazonTenantReturnsOutbox $target,array $case,array $decision,array $timeline=[]): array
    {
        if (!self::isWriteAction($decision)) return ['decision'=>$decision,'outbox_id'=>null];
        $key = (string)($decision['idempotency_key'] ?? '');
        if ($key === '') throw new LogicException('Write decision missing idempotency key.');
        $caseId = (int)($case['id'] ?? 0);
        $payload = [
            'case_id'=>$caseId,
            'order_id'=>(string)($case['amazon_order_id'] ?? ''),
            'safe_t_id'=>$case['safe_t_id'] ?? null,
            'decision'=>$decision,
        ] + SvAmazonExternalWritePayload::build($decision,$case,$timeline);
        $deadline=SvAmazonRecoveryWindow::effectiveDeadlineAt($case);
        if($deadline instanceof DateTimeImmutable)$payload['deadline_at']=$deadline->format('Y-m-d H:i:s');
        $outboxId=$target->enqueue((string)$decision['action'],$caseId,$payload,$key);
        return ['decision'=>$decision,'outbox_id'=>$outboxId];
    }
}
