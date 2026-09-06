<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
function ersSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
$engine=new SvAmazonSafeTDecisionEngine();
$case=['id'=>505,'amazon_order_id'=>'702-test','safe_t_id'=>'84164-27415-4505005','state'=>'SAFE_T_DENIED',
    'appeal_deadline_at'=>'2026-09-09 12:00:00','expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00'];
$wait=['id'=>1,'case_id'=>505,'event_type'=>'SAFE_T_STATUS_OBSERVED','source'=>'SELLER_CENTRAL','occurred_at'=>'2026-09-01 12:00:00',
    'payload'=>['claim_status'=>'DENIED','decision_text'=>'Aguarde ate 10/09/2026 para o reembolso proativo.']];
$refresh=['id'=>2,'case_id'=>505,'event_type'=>'FINANCIAL_REFRESH_CONFIRMED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-09-10 11:55:00','payload'=>['refresh_complete'=>true]];
$finance=['id'=>3,'case_id'=>505,'event_type'=>'FINANCIAL_RECONCILIATION_CONFIRMED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-09-10 11:56:00',
    'payload'=>['refresh_complete'=>true,'source_refreshed_at'=>'2026-09-10 11:55:00','source_observation_id'=>2,'credit_amount'=>'0.00']];
$policy=['eligible'=>true,'policy_version_id'=>1];$now=new DateTimeImmutable('2026-09-10T12:00:00Z');
$missed=$engine->nextAction($case,[$wait,$refresh,$finance],$policy,$now);
ersSame('HUMAN_REVIEW',$missed['action'],'missed internal appeal cannot masquerade as second-stage email review');
ersSame('INTERNAL_APPEAL_WINDOW_MISSED_REQUIRES_REVIEW',$missed['reason'],'missed first-stage appeal must be explicit');
$appealDenied=$case;$appealDenied['state']='APPEAL_DENIED_FINAL';
$secondStage=$engine->nextAction($appealDenied,[$wait,$refresh,$finance],$policy,$now);
ersSame('SAFE_T_EMAIL_REVIEW',$secondStage['action'],'denied internal appeal remains eligible for automatic second-stage email review');
ersSame('AMAZON_REQUESTED_DATE_REACHED_UNRECOVERED',$secondStage['reason'],'dated wait keeps its audited resumption reason after appeal denial');
if(!is_string($secondStage['idempotency_key']??null)||strlen($secondStage['idempotency_key'])!==64)throw new RuntimeException('second-stage email review requires a stable idempotency key');
echo "email-review-sequence-test: OK\n";
