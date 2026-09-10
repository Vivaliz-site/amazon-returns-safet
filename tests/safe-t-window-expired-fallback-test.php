<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__.'/../includes/amazon-returns/PolicyEngine.php';
$engine=new SvAmazonSafeTDecisionEngine();
$case=['id'=>510,'amazon_order_id'=>'701-3120107-8909011','safe_t_id'=>null,'program'=>'DELIVERY_BY_AMAZON','marketplace_id'=>'A2Q3Y263D00KWC','order_at'=>'2026-04-01 10:00:00','state'=>'CREDIT_PENDING','refund_at'=>'2026-05-10 07:27:57','seller_debit_at'=>'2026-05-10 07:27:57','refund_initiator'=>'UNKNOWN','physical_status'=>'NOT_RECEIVED','quantity_ordered'=>1,'quantity_refunded'=>1,'quantity_received'=>0,'expected_reimbursement_amount'=>'29.60','reconciled_credit_amount'=>'19.00','policies'=>[['id'=>7441,'marketplace_id'=>'A2Q3Y263D00KWC','program'=>'STANDARD','effective_from'=>'2020-01-01','effective_to'=>null,'eligibility_days'=>45,'basis'=>'REFUND_AT','status'=>'ACTIVE']]];
$reimbursement=['id'=>1,'case_id'=>510,'event_type'=>'FINANCIAL_TRANSACTION_OBSERVED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-08-07 07:20:00','payload'=>['transaction'=>['transaction_type'=>'Adjustment','transaction_status'=>'RELEASED','description'=>'SERRACReimbursement','total_amount'=>['amount'=>'19.00','currency'=>'BRL']]]];
$now=new DateTimeImmutable('2026-08-07 07:27:57',new DateTimeZone('UTC'));
$fresh=['id'=>2,'case_id'=>510,'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-08-07 07:27:00','payload'=>['refresh_complete'=>true,'credit_amount'=>'19.00','outstanding_amount'=>'10.60','unclassified_transactions'=>4,'unsettled_financial_evidence'=>false,'ambiguous_reimbursement_transactions'=>0]];
$policy=SvAmazonReturnPolicyEngine::evaluate($case,$now);
$decision=$engine->nextAction($case,[$reimbursement,$fresh],$policy,$now);
if(($decision['action']??null)!=='SELLER_SUPPORT_OPEN'){fwrite(STDERR,'D+89 expected SELLER_SUPPORT_OPEN, got '.json_encode($decision).PHP_EOL);exit(1);}
if(($decision['reason']??null)!=='SAFE_T_WINDOW_EXPIRED_RESIDUAL_UNPAID'){fwrite(STDERR,'D+89 unexpected reason '.json_encode($decision).PHP_EOL);exit(1);}
if(($decision['support_route']??null)!=='GENERAL_ORDER_SUPPORT'){fwrite(STDERR,'D+89 expected GENERAL_ORDER_SUPPORT route, got '.json_encode($decision).PHP_EOL);exit(1);}
$d90=new DateTimeImmutable('2026-08-08 07:27:57',new DateTimeZone('UTC'));
$freshD90=$fresh;$freshD90['occurred_at']='2026-08-08 07:27:00';
$decisionD90=$engine->nextAction($case,[$reimbursement,$freshD90],SvAmazonReturnPolicyEngine::evaluate($case,$d90),$d90);
if(($decisionD90['action']??null)!=='SELLER_SUPPORT_OPEN'){fwrite(STDERR,'D+90 expected SELLER_SUPPORT_OPEN, got '.json_encode($decisionD90).PHP_EOL);exit(1);}
if(($decisionD90['support_route']??null)!=='GENERAL_ORDER_SUPPORT'){fwrite(STDERR,'D+90 expected GENERAL_ORDER_SUPPORT route, got '.json_encode($decisionD90).PHP_EOL);exit(1);}
$expired=new DateTimeImmutable('2026-08-08 07:27:58',new DateTimeZone('UTC'));
$decisionExpired=$engine->nextAction($case,[$reimbursement,$freshD90],SvAmazonReturnPolicyEngine::evaluate($case,$expired),$expired);
if(($decisionExpired['action']??null)!=='WAIT'){fwrite(STDERR,'after D+90 expected WAIT, got '.json_encode($decisionExpired).PHP_EOL);exit(1);}
if(($decisionExpired['reason']??null)!=='RECOVERY_WINDOW_EXPIRED'){fwrite(STDERR,'after D+90 unexpected reason '.json_encode($decisionExpired).PHP_EOL);exit(1);}
echo "safe-t-window-expired-fallback-test: OK\n";
