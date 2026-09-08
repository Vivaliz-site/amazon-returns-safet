<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__.'/../includes/amazon-returns/PolicyEngine.php';

$engine=new SvAmazonSafeTDecisionEngine();
$now=new DateTimeImmutable('2026-09-08 18:05:00',new DateTimeZone('UTC'));
$case=[
  'id'=>510,'amazon_order_id'=>'701-3120107-8909011','safe_t_id'=>null,
  'program'=>'DELIVERY_BY_AMAZON','marketplace_id'=>'A2Q3Y263D00KWC','order_at'=>'2026-04-01 10:00:00',
  'state'=>'CREDIT_PENDING','refund_at'=>'2026-05-10 07:27:57','seller_debit_at'=>'2026-05-10 07:27:57',
  'refund_initiator'=>'UNKNOWN','physical_status'=>'NOT_RECEIVED','quantity_ordered'=>1,'quantity_refunded'=>1,'quantity_received'=>0,
  'expected_reimbursement_amount'=>'29.60','reconciled_credit_amount'=>'19.00',
  'policies'=>[['id'=>7441,'marketplace_id'=>'A2Q3Y263D00KWC','program'=>'STANDARD','effective_from'=>'2020-01-01','effective_to'=>null,'eligibility_days'=>45,'basis'=>'REFUND_AT','status'=>'ACTIVE']],
];
$reimbursement=['id'=>1,'case_id'=>510,'event_type'=>'FINANCIAL_TRANSACTION_OBSERVED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-09-08 18:00:00','payload'=>['transaction'=>['transaction_type'=>'Adjustment','transaction_status'=>'RELEASED','description'=>'SERRACReimbursement','total_amount'=>['amount'=>'19.00','currency'=>'BRL']]]];
$errors=[];
$policy=SvAmazonReturnPolicyEngine::evaluate($case,$now);
if(($policy['eligible']??null)!==true)$errors[]='A trusted partial reimbursement must let the normal eligibility policy run despite unknown initiator. got='.json_encode($policy);
$first=$engine->nextAction($case,[$reimbursement],$policy,$now);
if(($first['action']??null)!=='CHECK_FINANCES')$errors[]='Partial reimbursement without a fresh residual check must verify finances first. got='.json_encode($first);
$fresh=['id'=>2,'case_id'=>510,'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-09-08 18:04:00','payload'=>['refresh_complete'=>true,'credit_amount'=>'19.00','outstanding_amount'=>'10.60','unclassified_transactions'=>4,'unsettled_financial_evidence'=>false,'ambiguous_reimbursement_transactions'=>0]];
$ready=$engine->nextAction($case,[$reimbursement,$fresh],$policy,$now);
if(($ready['action']??null)!=='SAFE_T_SUBMIT')$errors[]='Fresh confirmed partial reimbursement residual must advance to SAFE-T automatically. got='.json_encode($ready);
if(($ready['reason']??null)!=='PARTIAL_REIMBURSEMENT_RESIDUAL_UNPAID')$errors[]='Residual auto-claim must preserve an auditable reason. got='.json_encode($ready);
$unpaid=$case;$unpaid['reconciled_credit_amount']='0.00';
$blockedPolicy=SvAmazonReturnPolicyEngine::evaluate($unpaid,$now);
$blocked=$engine->nextAction($unpaid,[],$blockedPolicy,$now);
if(($blocked['action']??null)!=='BLOCKED_REVIEW')$errors[]='Unknown initiator without delivery or reimbursement evidence must remain conservative. got='.json_encode($blocked);
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "partial-reimbursement-no-review-test: OK\n";
