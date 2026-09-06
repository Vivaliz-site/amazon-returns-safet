<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
function awdSame(mixed $want,mixed $got,string $why):void{
    if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));
}
$engine=new SvAmazonSafeTDecisionEngine();
$policy=['eligible'=>true,'policy_version_id'=>1,'eligibility_at'=>'2026-08-15 12:00:00'];
$case=['id'=>77,'amazon_order_id'=>'702-9582024-4340203','safe_t_id'=>'98143-99485-9285859',
    'state'=>'SAFE_T_DENIED','appeal_deadline_at'=>'2026-09-08 18:00:00','refund_at'=>'2026-07-01 12:00:00',
    'refund_initiator'=>'AMAZON_AUTOMATIC','expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00'];
$wait=['id'=>101,'case_id'=>77,'event_type'=>'SAFE_T_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-01 12:00:00','payload'=>['claim_status'=>'DENIED',
    'decision_text'=>'Nenhuma acao e necessaria. Voce sera reembolsado proativamente ate 10/09/2026.']];
$before=$engine->nextAction($case,[$wait],$policy,new DateTimeImmutable('2026-09-06T12:00:00Z'));
awdSame('SAFE_T_APPEAL',$before['action'],'official internal appeal deadline must preempt a later Amazon wait date');
awdSame('APPEAL_DEADLINE_PREEMPTS_AMAZON_WAIT',$before['reason'],'preemption must be explicit and auditable');
$expired=$engine->nextAction($case,[$wait],$policy,new DateTimeImmutable('2026-09-09T12:00:00Z'));
awdSame('HUMAN_REVIEW',$expired['action'],'expired internal appeal window cannot be hidden by a later wait date');
awdSame('OFFICIAL_APPEAL_WINDOW_EXPIRED_DURING_AMAZON_WAIT',$expired['reason'],'expired conflict must be explicit');
$unknown=$case;unset($unknown['appeal_deadline_at']);
$unknownAction=$engine->nextAction($unknown,[$wait],$policy,new DateTimeImmutable('2026-09-06T12:00:00Z'));
awdSame('HUMAN_REVIEW',$unknownAction['action'],'denied claim with a dated Amazon wait and unknown internal deadline must fail closed');
awdSame('OFFICIAL_APPEAL_DEADLINE_UNRESOLVED_DURING_AMAZON_WAIT',$unknownAction['reason'],'unknown deadline conflict must be explicit');
$later=$case;$later['appeal_deadline_at']='2026-09-12 18:00:00';
$normal=$engine->nextAction($later,[$wait],$policy,new DateTimeImmutable('2026-09-06T12:00:00Z'));
awdSame('WAIT',$normal['action'],'Amazon wait remains valid when it ends before the internal appeal deadline');
awdSame('2026-09-10 03:00:00',$normal['next_action_at']??null,'non-conflicting wait date remains exact');
$submitted=$case;$submitted['state']='APPEAL_SUBMITTED';
$afterAppeal=$engine->nextAction($submitted,[$wait],$policy,new DateTimeImmutable('2026-09-06T12:00:00Z'));
awdSame('WAIT',$afterAppeal['action'],'already-submitted appeal must not be duplicated by deadline preemption');
echo "amazon-wait-appeal-deadline-test: OK\n";
