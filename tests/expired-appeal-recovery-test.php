<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';

$engine=new SvAmazonSafeTDecisionEngine();
$now=new DateTimeImmutable('2026-09-07 17:10:00',new DateTimeZone('UTC'));
$policy=['eligible'=>true,'state'=>'SAFE_T_ELIGIBLE','policy_version_id'=>7441,'eligibility_at'=>'2026-06-20 08:32:36'];
$case=['id'=>505,'amazon_order_id'=>'702-8373627-4388208','safe_t_id'=>'84164-27415-4505005','state'=>'SAFE_T_DENIED','appeal_deadline_at'=>'2026-06-29 16:35:00','expected_reimbursement_amount'=>'136.96','reconciled_credit_amount'=>'0.00','refund_at'=>'2026-05-06 08:32:36','refund_initiator'=>'UNKNOWN','physical_status'=>'NOT_RECEIVED'];
$timeline=[
 ['id'=>1,'case_id'=>505,'event_type'=>'SAFE_T_STATUS_OBSERVED','source'=>'SELLER_CENTRAL','occurred_at'=>'2026-09-07 04:48:04','payload'=>['safe_t_id'=>'84164-27415-4505005','claim_status'=>'DENIED','appeal_submitted'=>false,'decision_text'=>'Nenhuma ação é necessária da nossa parte neste momento. Se a devolução ainda não estiver marcada como entregue, você será reembolsado proativamente até Jun 24 2026. Se você não receber o reembolso no prazo mencionado, recorra dessa reivindicação.','appeal_deadline_at'=>'2026-06-29 16:35:00']],
 ['id'=>2,'case_id'=>505,'event_type'=>'FINANCIAL_REFRESH_CONFIRMED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-09-07 17:00:00','payload'=>['refresh_complete'=>true]],
 ['id'=>3,'case_id'=>505,'event_type'=>'FINANCIAL_RECONCILIATION_CONFIRMED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-09-07 17:00:10','payload'=>['refresh_complete'=>true,'source_refreshed_at'=>'2026-09-07 17:00:00','source_observation_id'=>2,'credit_amount'=>'0.00','outstanding_amount'=>'136.96']],
];
$errors=[];
$decision=$engine->nextAction($case,$timeline,$policy,$now);
if(($decision['action']??null)!=='SAFE_T_APPEAL')$errors[]='Expired promised-credit appeal must trigger a recovery appeal attempt, not human review. got='.json_encode($decision);
if(($decision['reason']??null)!=='MISSED_APPEAL_WINDOW_RECOVERY_ATTEMPT')$errors[]='Recovery attempt must be auditable. got='.json_encode($decision);
$late=$case;$late['id']=507;$late['amazon_order_id']='702-2458327-9625858';$late['safe_t_id']='31163-36572-5421246';$late['appeal_deadline_at']='2026-07-30 18:00:00';$late['expected_reimbursement_amount']='62.91';
$lateTimeline=[['id'=>4,'case_id'=>507,'event_type'=>'SAFE_T_STATUS_OBSERVED','source'=>'SELLER_CENTRAL','occurred_at'=>'2026-09-07 04:48:17','payload'=>['safe_t_id'=>'31163-36572-5421246','claim_status'=>'DENIED','appeal_submitted'=>false,'decision_text'=>'Sua reivindicação SAFE-T para este pedido não foi registrada dentro do período elegível. Todas as reivindicações SAFE-T devem ser registradas até 52 dias depois que o Seller Central debita débito na conta de vendedor.','appeal_deadline_at'=>'2026-07-30 18:00:00']]];
$lateDecision=$engine->nextAction($late,$lateTimeline,$policy,$now);
if(($lateDecision['action']??null)!=='SAFE_T_APPEAL')$errors[]='Expired late-filing denial must still get one automatic recovery appeal attempt when no appeal was submitted. got='.json_encode($lateDecision);
$case['state']='APPEAL_SUBMITTED';
$again=$engine->nextAction($case,$timeline,$policy,$now);
if(($again['action']??null)==='SAFE_T_APPEAL')$errors[]='Already submitted appeal must never be duplicated.';
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "expired-appeal-recovery-test: OK\n";
