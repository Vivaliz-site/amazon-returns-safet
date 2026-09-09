<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__.'/../includes/amazon-returns/SafeTStatusService.php';
$errors=[];function riEq(mixed $want,mixed $got,string $why):void{global $errors;if($want!==$got)$errors[]=$why.' expected='.json_encode($want).' actual='.json_encode($got);}
$engine=new SvAmazonSafeTDecisionEngine();$now=new DateTimeImmutable('2026-09-05T15:00:00Z');
$case=['id'=>77,'amazon_order_id'=>'702-1111111-2222222','program'=>'STANDARD','order_at'=>'2026-05-01 00:00:00','refund_at'=>'2026-06-10 12:00:00','seller_debit_at'=>'2026-06-10 12:00:00','physical_status'=>'NOT_RECEIVED','refund_initiator'=>'AMAZON_AUTOMATIC','expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00','state'=>'SAFE_T_ELIGIBLE'];
$policy=['eligible'=>true,'policy_version_id'=>1,'eligibility_at'=>'2026-07-25 12:00:00'];
$event=['id'=>1,'case_id'=>77,'event_type'=>'RETURN_REPORT_OBSERVED','source'=>'SP_API_REPORTS','occurred_at'=>'2026-09-01 12:00:00','payload'=>['return_status'=>'Perdido no Transporte']];
riEq('SAFE_T_SUBMIT',$engine->nextAction($case,[$event],$policy,$now)['action'],'D45 Amazon-refunded loss submits SAFE-T even on proactive transport status');
riEq('SAFE_T_SUBMIT',$engine->nextAction($case,[],$policy,$now)['action'],'D45 Amazon-refunded loss does not require a transport status to submit SAFE-T');
$event['payload']['return_status']='Retornando ao Vendedor';riEq('SAFE_T_SUBMIT',$engine->nextAction($case,[$event],$policy,$now)['action'],'legitimate return-to-seller still uses SAFE-T');
$damaged=$case;$damaged['physical_status']='RECEIVED_DISCREPANT';riEq('HUMAN_REVIEW',$engine->nextAction($damaged,[],$policy,$now)['action'],'damaged/discrepant return initial SAFE-T opening is manual-only');
foreach(['EMAIL_REVIEW_SENT','EMAIL_REVIEW_RESPONSE_PENDING','SUPPORT_ESCALATION','RECOVERED'] as $state){
 riEq($state,SvAmazonSafeTStatusService::nextState($state,'DENIED',true),'a later UI denial cannot erase completed channel progress '.$state);
}

$refunded=$case;$refunded['refund_initiator']='AMAZON_AUTOMATIC';
foreach([[],[['id'=>21,'case_id'=>77,'event_type'=>'RETURN_REPORT_OBSERVED','source'=>'SP_API_REPORTS','occurred_at'=>'2026-09-01 12:00:00','payload'=>['return_status'=>'Perdido no Transporte']]]] as $timeline){
 riEq('SAFE_T_SUBMIT',$engine->nextAction($refunded,$timeline,$policy,$now)['action'],'D45 Amazon-refunded seller loss submits SAFE-T independent of transport evidence');
}
$returnedLoss=$refunded;$returnedLoss['physical_status']='RECEIVED_OK';
riEq('SAFE_T_SUBMIT',$engine->nextAction($returnedLoss,[],$policy,$now)['action'],'D45 unresolved seller financial loss submits even when physical return exists');
$paid=$refunded;$paid['reconciled_credit_amount']='100.00';
riEq('WAIT',$engine->nextAction($paid,[],$policy,$now)['action'],'real full credit suppresses new SAFE-T');

foreach(['AMAZON_AUTOMATIC','AMAZON_CUSTOMER_SERVICE','A_TO_Z'] as $initiator){
 $c=$case;$c['refund_initiator']=$initiator;$c['physical_status']='NOT_RECEIVED';
 riEq('SAFE_T_SUBMIT',$engine->nextAction($c,[], $policy,$now)['action'],'Amazon-side customer refund is eligible for D45 SAFE-T: '.$initiator);
}
$sellerRefund=$case;$sellerRefund['refund_initiator']='SELLER';
riEq('WAIT',$engine->nextAction($sellerRefund,[], $policy,$now)['action'],'seller-initiated refund alone is not the owner-approved Amazon-refund D45 trigger');
$before=$policy;$before['eligible']=false;$before['state']='AWAITING_RETURN';
riEq('WAIT',$engine->nextAction($refunded,[], $before,$now)['action'],'Amazon refund before D45 still waits until D45');

$appReceipt=['id'=>90,'case_id'=>77,'event_type'=>'PHYSICAL_RECEIVED','source'=>'WAREHOUSE','occurred_at'=>'2026-09-05 14:30:00','payload'=>['quantity'=>1,'condition'=>'OK','operator_id'=>123]];
riEq('WAIT',$engine->nextAction($refunded,[$appReceipt],$policy,$now)['action'],'seller app physical receipt is the only physical-arrival blocker');
$carrierReceipt=$appReceipt;$carrierReceipt['source']='SP_API_REPORTS';
riEq('SAFE_T_SUBMIT',$engine->nextAction($refunded,[$carrierReceipt],$policy,$now)['action'],'carrier/system receipt is not seller app confirmation');
$genericReceived=$refunded;$genericReceived['physical_status']='RECEIVED_OK';
riEq('SAFE_T_SUBMIT',$engine->nextAction($genericReceived,[],$policy,$now)['action'],'generic projected RECEIVED_OK without app event does not block D45 SAFE-T');
$partialCredit=$refunded;$partialCredit['reconciled_credit_amount']='99.99';
riEq('SAFE_T_SUBMIT',$engine->nextAction($partialCredit,[],$policy,$now)['action'],'partial seller credit does not block D45 SAFE-T');
$noDebit=$refunded;$noDebit['seller_debit_at']=null;
riEq('SAFE_T_SUBMIT',$engine->nextAction($noDebit,[],$policy,$now)['action'],'once D45 policy is eligible, missing separate debit field does not create a third blocker');
$damagedManual=$case;$damagedManual['physical_status']='RECEIVED_DISCREPANT';$damagedManual['safe_t_id']=null;$damagedManual['state']='RECEIVED_DISCREPANT';
riEq('HUMAN_REVIEW',$engine->nextAction($damagedManual,[],$policy,$now)['action'],'damaged return initial SAFE-T opening is manual-only');
$damagedDenied=$damagedManual;$damagedDenied['safe_t_id']='12345-67890-1234567';$damagedDenied['state']='SAFE_T_DENIED';$damagedDenied['appeal_deadline_at']='2026-09-08 18:00:00';$damagedDenied['latest_denial_text']='Negamos a reivindicacao';
riEq('SAFE_T_APPEAL',$engine->nextAction($damagedDenied,[],$policy,$now)['action'],'after user manually opened and Amazon denied, app may continue the denial lifecycle');
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "return-routing-integration-test: OK\n";
