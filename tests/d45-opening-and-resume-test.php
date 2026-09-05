<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/PolicySeeder.php';
require_once __DIR__.'/../includes/amazon-returns/PolicyEngine.php';
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
$errors=[];
function dorSame(mixed $want,mixed $got,string $why):void {global $errors;if($want!==$got)$errors[]=$why.' expected='.json_encode($want).' actual='.json_encode($got);}
$policies=[];foreach(SvAmazonReturnPolicySeeder::definitions() as $i=>$row){$policies[]=['id'=>$i+1]+$row;dorSame(45,$row['eligibility_days'],'Owner requires first opening D45 for '.$row['program']);}
$case=['id'=>77,'amazon_order_id'=>'702-1111111-2222222','marketplace_id'=>'A2Q3Y263D00KWC','order_at'=>'2026-05-01 08:00:00','seller_debit_at'=>'2026-07-01 12:00:00','refund_at'=>'2026-07-01 12:00:00','refund_initiator'=>'AMAZON_AUTOMATIC','quantity_ordered'=>1,'quantity_refunded'=>1,'quantity_received'=>0,'physical_status'=>'NOT_RECEIVED','expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00','state'=>'AWAITING_RETURN','policies'=>$policies];
$engine=new SvAmazonSafeTDecisionEngine();
foreach(['STANDARD','FBA_ONSITE','DELIVERY_BY_AMAZON'] as $program){
 $row=$case+['program'=>$program];
 $before=SvAmazonReturnPolicyEngine::evaluate($row,new DateTimeImmutable('2026-08-15T11:59:59Z'));
 $due=SvAmazonReturnPolicyEngine::evaluate($row,new DateTimeImmutable('2026-08-15T12:00:00Z'));
 dorSame(false,$before['eligible'],$program.' no early D45');dorSame(true,$due['eligible'],$program.' no silent D60/D75');
 dorSame('SAFE_T_SUBMIT',$engine->nextAction($row,[],$due,new DateTimeImmutable('2026-08-15T12:00:00Z'))['action'],$program.' first opening at D45');
}
$claim=$case+['program'=>'STANDARD','safe_t_id'=>'11111-22222-3333333','appeal_deadline_at'=>'2026-09-15 18:00:00'];$claim['state']='SAFE_T_DENIED';
$wait=['id'=>101,'case_id'=>77,'event_type'=>'SAFE_T_STATUS_OBSERVED','source'=>'SELLER_CENTRAL','occurred_at'=>'2026-09-01 12:00:00','payload'=>['claim_status'=>'DENIED','decision_text'=>'Nenhuma acao e necessaria neste momento. Voce sera reembolsado proativamente ate 10 de setembro de 2026.']];
$policy=['eligible'=>true,'policy_version_id'=>1,'eligibility_at'=>'2026-08-15 12:00:00'];
$future=$engine->nextAction($claim,[$wait],$policy,new DateTimeImmutable('2026-09-09T18:00:00Z'));
 dorSame('WAIT',$future['action'],'Amazon requested future date must suspend appeal');
 dorSame('2026-09-10 03:00:00',$future['next_action_at']??null,'resume on requested Brazil calendar date, not a fresh 45/60-day delay');
$onDate=$engine->nextAction($claim,[$wait],$policy,new DateTimeImmutable('2026-09-10T12:00:00Z'));
 dorSame('CHECK_FINANCES',$onDate['action'],'read actual credit before reopening on the due date');
$sourceFinance=['id'=>102,'case_id'=>77,'event_type'=>'FINANCIAL_REFRESH_CONFIRMED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-09-10 11:55:00','payload'=>['refresh_complete'=>true]];
$finance=['id'=>103,'case_id'=>77,'event_type'=>'FINANCIAL_RECONCILIATION_CONFIRMED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-09-10 11:55:00','payload'=>['refresh_complete'=>true,'source_refreshed_at'=>'2026-09-10 11:55:00','source_observation_id'=>102,'credit_amount'=>'0.00']];
$resume=$engine->nextAction($claim,[$wait,$sourceFinance,$finance],$policy,new DateTimeImmutable('2026-09-10T12:00:00Z'));
dorSame('SAFE_T_APPEAL',$resume['action'],'resume existing claim after requested date and refreshed unpaid finance');
$again=$engine->nextAction($claim,[$wait,$sourceFinance,$finance],$policy,new DateTimeImmutable('2026-09-10T12:01:00Z'));
dorSame($resume['idempotency_key']??'first',$again['idempotency_key']??'second','one followup per request/date, not one per poll');
$paid=$claim;$paid['reconciled_credit_amount']='100.00';dorSame('WAIT',$engine->nextAction($paid,[$wait,$sourceFinance,$finance],$policy,new DateTimeImmutable('2026-09-10T12:00:00Z'))['action'],'do not reopen a financially recovered case');
$unknown=$wait;$unknown['payload']['decision_text']='Aguarde nossa resposta.';
dorSame('HUMAN_REVIEW',$engine->nextAction($claim,[$unknown],$policy,new DateTimeImmutable('2026-09-10T12:00:00Z'))['action'],'no date must not become a guessed retry');
$changed=$wait;$changed['id']=103;$changed['occurred_at']='2026-09-10 12:30:00';$changed['payload']['decision_text']='Aguarde ate 18/09/2026.';
$newDate=$engine->nextAction($claim,[$wait,$sourceFinance,$finance,$changed],$policy,new DateTimeImmutable('2026-09-10T13:00:00Z'));
dorSame('WAIT',$newDate['action'],'a newer requested date supersedes the previous one');dorSame('2026-09-18 03:00:00',$newDate['next_action_at']??null,'new date persists exactly');
$old=$wait;$old['occurred_at']='2026-08-01 12:00:00';$old['payload']['decision_text']='Aguarde ate 02/08/2026.';
dorSame('WAIT',$engine->nextAction($claim,[$changed,$old],$policy,new DateTimeImmutable('2026-09-10T13:00:00Z'))['action'],'late-arriving older response must not replace newer wait');
$blocked=['id'=>200,'case_id'=>77,'event_type'=>'SELLER_CENTRAL_ACTION_RESULT','source'=>'SELLER_CENTRAL','occurred_at'=>'2026-09-10 12:00:00','payload'=>['status'=>'BLOCKED_UNTIL','block_reason'=>'NOT_ELIGIBLE']];
dorSame('HUMAN_REVIEW',$engine->nextAction($claim,[$blocked],$policy,new DateTimeImmutable('2026-09-10T12:05:00Z'))['action'],'explicit vendor block without date cannot silently retry');
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "d45-opening-and-resume-test: OK\n";
