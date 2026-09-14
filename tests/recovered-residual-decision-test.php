<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
function rrdSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
$engine=new SvAmazonSafeTDecisionEngine();
$case=['id'=>13234,'amazon_order_id'=>'702-1219724-9878614','program'=>'DELIVERY_BY_AMAZON','state'=>'RECOVERED','terminal_reason'=>'FINANCIAL_RECOVERED','safe_t_id'=>null,'physical_status'=>'NOT_RECEIVED','refund_at'=>'2026-07-30 05:37:05','refund_initiator'=>'AMAZON_CUSTOMER_SERVICE','expected_reimbursement_amount'=>'40.14','reconciled_credit_amount'=>'39.60'];
$timeline=[['id'=>500,'case_id'=>13234,'event_type'=>'FINANCIAL_RECONCILIATION_CONFIRMED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-09-09 15:52:55','payload'=>['refresh_complete'=>true,'credit_amount'=>'39.60','outstanding_amount'=>'0.00','residual_tolerance_applied'=>true,'tolerated_residual_amount'=>'0.54']]];
$policy=['eligible'=>true,'policy_version_id'=>1,'eligibility_at'=>'2026-09-13 05:37:05'];
$decision=$engine->nextAction($case,$timeline,$policy,new DateTimeImmutable('2026-09-13 12:00:00',new DateTimeZone('UTC')));
rrdSame('WAIT',$decision['action']??null,'An auditable tolerated financial settlement must remain terminal and never reopen recovery.');
rrdSame('ALREADY_REIMBURSED',$decision['reason']??null,'Tolerated recovered settlement must use the same financial terminal reason as a full credit.');
echo "recovered-residual-decision-test: OK\n";
