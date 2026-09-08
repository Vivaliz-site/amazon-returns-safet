<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__.'/../includes/amazon-returns/PolicyEngine.php';

$fail=[];
function draSame(mixed $want,mixed $got,string $why):void{global $fail;if($want!==$got)$fail[]=$why.' expected='.json_encode($want).' actual='.json_encode($got);}
$engine=new SvAmazonSafeTDecisionEngine();
$now=new DateTimeImmutable('2026-09-07T12:00:00Z');
$case=['id'=>501,'amazon_order_id'=>'702-0707321-6872209','program'=>'DELIVERY_BY_AMAZON','order_at'=>'2026-03-22 09:59:33','refund_at'=>'2026-05-06 00:04:03','seller_debit_at'=>'2026-05-06 00:04:03','quantity_ordered'=>1,'quantity_refunded'=>1,'quantity_received'=>0,'physical_status'=>'NOT_RECEIVED','refund_initiator'=>'UNKNOWN','expected_reimbursement_amount'=>'112.50','reconciled_credit_amount'=>'0.00','state'=>'POLICY_REVIEW_REQUIRED','safe_t_id'=>null,'customer_delivery_confirmed'=>true,'customer_tracking_ids'=>['AMZB925901732tx']];
$case['policies']=[['id'=>7441,'marketplace_id'=>'A2Q3Y263D00KWC','program'=>'STANDARD','effective_from'=>'2020-01-01','effective_to'=>null,'eligibility_days'=>45,'basis'=>'REFUND_AT','status'=>'ACTIVE']];
$case['marketplace_id']='A2Q3Y263D00KWC';
$policy=SvAmazonReturnPolicyEngine::evaluate($case,$now);
draSame(true,$policy['eligible']??null,'Independent customer-delivery proof must let the normal D45 policy be evaluated despite unknown initiator.');
$finance=['id'=>2,'case_id'=>501,'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-09-07 11:00:00','payload'=>['refresh_complete'=>true,'credit_amount'=>'0.00','outstanding_amount'=>'112.50','unclassified_transactions'=>4,'ambiguous_reimbursement_transactions'=>0,'unsettled_financial_evidence'=>false]];
$decision=$engine->nextAction($case,[$finance],$policy,$now);
draSame('SAFE_T_SUBMIT',$decision['action']??null,'Delivered + customer refunded + fresh unpaid finance must not require human review.');
draSame('DELIVERED_CUSTOMER_REFUNDED_UNPAID',$decision['reason']??null,'Automatic claim must preserve auditable business reason.');
$legacy=$finance;unset($legacy['payload']['ambiguous_reimbursement_transactions'],$legacy['payload']['unsettled_financial_evidence']);
draSame('CHECK_FINANCES',$engine->nextAction($case,[$legacy],$policy,$now)['action']??null,'Legacy finance receipts without reimbursement-specific uncertainty must fail closed until refreshed.');
draSame('CHECK_FINANCES',$engine->nextAction($case,[],$policy,$now)['action']??null,'Clear delivered/refunded case must recheck finance automatically instead of asking for review.');
$withoutDelivery=$case;$withoutDelivery['customer_delivery_confirmed']=false;
$blocked=SvAmazonReturnPolicyEngine::evaluate($withoutDelivery,$now);
draSame('POLICY_REVIEW_REQUIRED',$blocked['state']??null,'Unknown initiator remains conservative without independent delivery proof.');
if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}echo "delivered-refunded-auto-claim-test: OK\n";
