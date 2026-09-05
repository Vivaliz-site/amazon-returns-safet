<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__.'/../includes/amazon-returns/SafeTStatusService.php';
$errors=[];function riEq(mixed $want,mixed $got,string $why):void{global $errors;if($want!==$got)$errors[]=$why.' expected='.json_encode($want).' actual='.json_encode($got);}
$engine=new SvAmazonSafeTDecisionEngine();$now=new DateTimeImmutable('2026-09-05T15:00:00Z');
$case=['id'=>77,'amazon_order_id'=>'702-1111111-2222222','program'=>'STANDARD','order_at'=>'2026-05-01 00:00:00','refund_at'=>'2026-06-01 12:00:00','seller_debit_at'=>'2026-06-01 12:00:00','physical_status'=>'NOT_RECEIVED','refund_initiator'=>'AMAZON_AUTOMATIC','expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00','state'=>'SAFE_T_ELIGIBLE'];
$policy=['eligible'=>true,'policy_version_id'=>1,'eligibility_at'=>'2026-07-16 12:00:00'];
$event=['id'=>1,'case_id'=>77,'event_type'=>'RETURN_REPORT_OBSERVED','source'=>'SP_API_REPORTS','occurred_at'=>'2026-09-01 12:00:00','payload'=>['return_status'=>'Perdido no Transporte']];
riEq('CHECK_FINANCES',$engine->nextAction($case,[$event],$policy,$now)['action'],'engine must call the proactive route instead of submitting SAFE-T');
riEq('HUMAN_REVIEW',$engine->nextAction($case,[],$policy,$now)['action'],'engine must not infer return transport state');
$event['payload']['return_status']='Retornando ao Vendedor';riEq('SAFE_T_SUBMIT',$engine->nextAction($case,[$event],$policy,$now)['action'],'legitimate return-to-seller still uses SAFE-T');
$damaged=$case;$damaged['physical_status']='RECEIVED_DISCREPANT';riEq('DAMAGE_EVIDENCE_REVIEW',$engine->nextAction($damaged,[],$policy,$now)['action'],'damage route is separate from non-return');
foreach(['EMAIL_REVIEW_SENT','EMAIL_REVIEW_RESPONSE_PENDING','SUPPORT_ESCALATION','RECOVERED'] as $state){
 riEq($state,SvAmazonSafeTStatusService::nextState($state,'DENIED',true),'a later UI denial cannot erase completed channel progress '.$state);
}
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "return-routing-integration-test: OK\n";
