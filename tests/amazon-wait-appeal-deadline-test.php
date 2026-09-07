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
awdSame('WAIT',$before['action'],'Amazon explicit proactive-credit date must be honored even when an older appeal deadline is sooner');
awdSame('AMAZON_REQUESTED_WAIT',$before['reason'],'the dated Amazon instruction must remain the authoritative wait reason');
awdSame('2026-09-10 03:00:00',$before['next_action_at']??null,'promised date must remain exact and auditable');
$afterOldDeadline=$engine->nextAction($case,[$wait],$policy,new DateTimeImmutable('2026-09-09T12:00:00Z'));
awdSame('WAIT',$afterOldDeadline['action'],'an elapsed appeal deadline must not override Amazon own later proactive-credit promise');
awdSame('AMAZON_REQUESTED_WAIT',$afterOldDeadline['reason'],'wait must remain authoritative until the promised date');
$unknown=$case;unset($unknown['appeal_deadline_at']);
$unknownAction=$engine->nextAction($unknown,[$wait],$policy,new DateTimeImmutable('2026-09-06T12:00:00Z'));
awdSame('WAIT',$unknownAction['action'],'an explicit Amazon wait date is sufficient before the promise expires even when an appeal deadline is unknown');
awdSame('AMAZON_REQUESTED_WAIT',$unknownAction['reason'],'unknown appeal deadline must not create premature human review during Amazon promised wait');
$due=$engine->nextAction($case,[$wait],$policy,new DateTimeImmutable('2026-09-10T04:00:00Z'));
awdSame('CHECK_FINANCES',$due['action'],'when the promised date passes the system must recheck seller credit before appealing');
awdSame('AMAZON_WAIT_DATE_REACHED_RECHECK_CREDIT',$due['reason'],'post-promise finance verification must be explicit');
$submitted=$case;$submitted['state']='APPEAL_SUBMITTED';
$afterAppeal=$engine->nextAction($submitted,[$wait],$policy,new DateTimeImmutable('2026-09-06T12:00:00Z'));
awdSame('WAIT',$afterAppeal['action'],'already-submitted appeal must never be duplicated');
echo "amazon-wait-appeal-deadline-test: OK\n";
