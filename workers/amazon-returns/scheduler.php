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

    public static function isReadAction(array $decision): bool
    {
        return strtoupper(trim((string)($decision['action'] ?? ''))) === 'SAFE_T_READ';
    }

    public static function dependencyForAction(string $action): string
    {
        return in_array(strtoupper(trim($action)), ['SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY'], true) ? 'gmail' : 'seller_central_bridge';
    }

    public static function normalizeRecoveryChannel(array $case,array $decision,DateTimeImmutable $now): array
    {
        if(SvAmazonRecoveryWindow::expired($case,$now)){
            return ['action'=>'WAIT','reason'=>'RECOVERY_WINDOW_EXPIRED','case_id'=>(int)($case['id']??0)];
        }
        if(strtoupper(trim((string)($decision['action']??'')))!=='SAFE_T_APPEAL')return $decision;
        $deadlineRaw=$case['appeal_deadline_at']??null;
        $deadline=null;
        if(is_string($deadlineRaw) && trim($deadlineRaw)!==''){
            try{$deadline=(new DateTimeImmutable($deadlineRaw,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));}catch(Throwable){}
        }
        if(!$deadline instanceof DateTimeImmutable || $now->setTimezone(new DateTimeZone('UTC'))<=$deadline)return $decision;
        $supportId=trim((string)($case['support_case_id']??''));
        $supportStatus=strtoupper(trim((string)($case['support_case_status']??'OPEN')));
        if($supportId!=='' && !in_array($supportStatus,['CLOSED','RESOLVED','CANCELLED'],true)){
            return [
                'action'=>'WAIT','reason'=>'SUPPORT_ESCALATION_ALREADY_ACTIVE','case_id'=>(int)($case['id']??0),
                'support_case_id'=>$supportId,
            ];
        }
        $scope=(string)($decision['resume_scope']??$decision['review_scope']??$decision['idempotency_key']??'appeal-expired');
        return array_replace($decision,[
            'action'=>'SELLER_SUPPORT_OPEN',
            'reason'=>'OFFICIAL_APPEAL_WINDOW_EXPIRED_RECOVERY_CONTINUES',
            'support_route'=>'GENERAL_ORDER_SUPPORT',
            'idempotency_key'=>hash('sha256','support-after-expired-appeal|'.(int)($case['id']??0).'|'.(string)($case['safe_t_id']??'').'|'.$scope),
        ]);
    }

    public function schedule(
        SvAmazonTenantReturnsOutbox $target,
        array $case,
        array $timeline,
        array $policy,
        ?DateTimeImmutable $now=null
    ): array {
        $now ??= new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $decision = $this->engine->nextAction($case, $timeline, $policy, $now);
        return $this->scheduleDecision($target,$case,$decision,$timeline,$now);
    }

    public function scheduleDecision(SvAmazonTenantReturnsOutbox $target,array $case,array $decision,array $timeline=[],?DateTimeImmutable $now=null): array
    {
        $now ??= new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $decision=self::normalizeRecoveryChannel($case,$decision,$now);
        $read=self::isReadAction($decision);
        if (!$read && !self::isWriteAction($decision)) return ['decision'=>$decision,'outbox_id'=>null];
        $key = (string)($decision['idempotency_key'] ?? '');
        if ($key === '') throw new LogicException(($read ? 'Read' : 'Write').' decision missing idempotency key.');
        $caseId = (int)($case['id'] ?? 0);
        $action=(string)$decision['action'];
        if($read){
            $payload=[
                'case_id'=>$caseId,
                'order_id'=>(string)($case['amazon_order_id'] ?? ''),
                'order_item_id'=>(string)($case['amazon_order_item_id'] ?? ''),
                'safe_t_id'=>$case['safe_t_id'] ?? null,
                'read_only'=>true,
                'decision'=>$decision,
            ];
        }else{
            $payload = [
                'case_id'=>$caseId,
                'order_id'=>(string)($case['amazon_order_id'] ?? ''),
                'safe_t_id'=>$case['safe_t_id'] ?? null,
                'decision'=>$decision,
            ] + SvAmazonExternalWritePayload::build($decision,$case,$timeline);
            $deadline=SvAmazonRecoveryWindow::effectiveDeadlineAt($case,$action);
            if($deadline instanceof DateTimeImmutable)$payload['deadline_at']=$deadline->format('Y-m-d H:i:s');
        }
        $outboxId=$target->enqueue($action,$caseId,$payload,$key);
        return ['decision'=>$decision,'outbox_id'=>$outboxId];
    }
}
