<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';

$fail=[];
function draSame(mixed $want,mixed $got,string $why):void{global $fail;if($want!==$got)$fail[]=$why.' expected='.json_encode($want).' actual='.json_encode($got);}
$engine=new SvAmazonSafeTDecisionEngine();
$now=new DateTimeImmutable('2026-09-07T12:00:00Z');
$case=['id'=>501,'amazon_order_id'=>'702-0707321-6872209','program'=>'DELIVERY_BY_AMAZON','order_at'=>'2026-03-22 09:59:33','refund_at'=>'2026-05-06 00:04:03','seller_debit_at'=>'2026-05-06 00:04:03','quantity_ordered'=>1,'quantity_refunded'=>1,'quantity_received'=>0,'physical_status'=>'NOT_RECEIVED','refund_initiator'=>'UNKNOWN','expected_reimbursement_amount'=>'112.50','reconciled_credit_amount'=>'0.00','state'=>'POLICY_REVIEW_REQUIRED','safe_t_id'=>null];
$policy=['eligible'=>true,'policy_version_id'=>7443,'eligibility_at'=>'2026-06-20 00:04:03','state'=>'SAFE_T_ELIGIBLE'];
$delivery=['id'=>1,'case_id'=>501,'event_type'=>'CUSTOMER_DELIVERY_CONFIRMED','source'=>'SELLER_CENTRAL','occurred_at'=>'2026-05-25 17:47:00','payload'=>['tracking_id'=>'AMZB925901732tx','status'=>'DELIVERED','delivered_at'=>'2026-05-25 17:47:00']];
$finance=['id'=>2,'case_id'=>501,'event_type'=>'FINANCIAL_RECONCILIATION_CONFIRMED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-09-07 10:00:00','payload'=>['refresh_complete'=>true,'credit_amount'=>'0.00','outstanding_amount'=>'112.50']];
$decision=$engine->nextAction($case,[$delivery,$finance],$policy,$now);
draSame('SAFE_T_SUBMIT',$decision['action']??null,'Delivered + customer refunded + fresh unpaid finance must not require human review.');
draSame('DELIVERED_CUSTOMER_REFUNDED_UNPAID',$decision['reason']??null,'Automatic claim must preserve auditable business reason.');
$withoutDelivery=$engine->nextAction($case,[$finance],$policy,$now);
draSame('BLOCKED_REVIEW',$withoutDelivery['action']??null,'Unknown initiator alone remains blocked without independent delivery proof.');
if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}echo "delivered-refunded-auto-claim-test: OK\n";
