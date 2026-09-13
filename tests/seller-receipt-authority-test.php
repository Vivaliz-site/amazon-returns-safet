<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Projector.php';
require_once __DIR__.'/../includes/amazon-returns/PolicyEngine.php';
require_once __DIR__.'/../includes/amazon-returns/PolicySeeder.php';
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
$errors=[];
function sraSame(mixed $want,mixed $got,string $why):void {global $errors;if($want!==$got)$errors[]=$why.' expected='.json_encode($want).' actual='.json_encode($got);}
$case=['id'=>77,'quantity_ordered'=>2,'physical_status'=>'NOT_RECEIVED','state'=>'AWAITING_RETURN','marketplace_id'=>'A2Q3Y263D00KWC','amazon_order_id'=>'702-1111111-2222222','expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00'];
$base=[['id'=>1,'case_id'=>77,'event_type'=>'ORDER_SYNCED','source'=>'SP_API_ORDERS','occurred_at'=>'2026-06-01 12:00:00','payload'=>['program'=>'STANDARD','order_at'=>'2026-06-01 12:00:00']],['id'=>2,'case_id'=>77,'event_type'=>'REFUND_CONFIRMED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-07-01 12:00:00','payload'=>['refund_at'=>'2026-07-01 12:00:00','seller_debit_at'=>'2026-07-01 12:00:00','refund_initiator'=>'AMAZON_AUTOMATIC','quantity_refunded'=>2,'refund_amount'=>'100.00']]];
$receipt=['id'=>3,'case_id'=>77,'event_type'=>'PHYSICAL_RECEIVED','source'=>'WAREHOUSE','source_event_id'=>'bbaeff81-d87f-494a-9b34-0b010301c022','occurred_at'=>'2026-07-10 12:00:00','payload'=>['quantity'=>2,'condition'=>'OK','operator_id'=>1234]];
foreach(['SELLER_CENTRAL','SP_API_REPORTS','CARRIER','GMAIL','UNKNOWN'] as $source){
 foreach(['PHYSICAL_RECEIVED','WAREHOUSE_RECEIVED','RECEIVED_OK'] as $type){
  $fake=$receipt;$fake['source']=$source;$fake['event_type']=$type;
  $p=SvAmazonReturnProjector::projectFrom($case,[...$base,$fake]);
  sraSame(0,$p['quantity_received'],$source.'/'.$type.' cannot assert physical intake');
  sraSame('NOT_RECEIVED',$p['physical_status'],$source.'/'.$type.' cannot close non-return exposure');
 }
}
foreach(['operator_id','operation_id'] as $missing){$fake=$receipt;if($missing==='operator_id')unset($fake['payload']['operator_id']);else unset($fake['source_event_id']);$p=SvAmazonReturnProjector::projectFrom($case,[...$base,$fake]);sraSame(0,$p['quantity_received'],'app receipt missing '.$missing.' is not verified');}
$foreign=$receipt;$foreign['case_id']=78;sraSame(0,SvAmazonReturnProjector::projectFrom($case,[...$base,$foreign])['quantity_received'],'other case receipt cannot close this item');
$stale=$case;$stale['quantity_received']=2;$stale['physical_status']='RECEIVED_OK';$stale['state']='RECEIVED_OK';$stale['terminal_reason']='PHYSICAL_RETURN_RECEIVED';$stale['closed_at']='2026-07-10 12:00:00';
$p=SvAmazonReturnProjector::projectFrom($stale,$base);sraSame('NOT_RECEIVED',$p['physical_status'],'stored status alone is not seller confirmation');sraSame(null,$p['closed_at'],'stale physical closure needs authoritative intake evidence');

$verified=SvAmazonReturnProjector::projectFrom($case,[...$base,$receipt]);
sraSame(2,$verified['quantity_received'],'verified warehouse intake records physical receipt');
sraSame('RECEIVED_OK',$verified['physical_status'],'verified full warehouse intake closes physical exposure');
$verifiedBeforeRefund=SvAmazonReturnProjector::projectFrom($case,[$base[0],$receipt]);
sraSame(2,$verifiedBeforeRefund['quantity_received'],'verified intake before refund projection must retain received quantity');
sraSame('RECEIVED_OK',$verifiedBeforeRefund['physical_status'],'full verified intake before refund projection must close physical exposure');
sraSame('RECEIVED_OK',$verifiedBeforeRefund['state'],'full verified intake before refund projection must close the case');
$recovered=array_replace($case,['state'=>'RECOVERED','closed_at'=>'2026-07-05 09:30:00','terminal_reason'=>'REIMBURSEMENT_CREDIT_CONFIRMED']);
$recoveredAfterReceipt=SvAmazonReturnProjector::projectFrom($recovered,[...$base,$receipt]);
sraSame(2,$recoveredAfterReceipt['quantity_received'],'late warehouse intake must still record receipt on a recovered case');
sraSame('RECEIVED_OK',$recoveredAfterReceipt['physical_status'],'late warehouse intake must update recovered case physical status');
sraSame('RECOVERED',$recoveredAfterReceipt['state'],'late warehouse intake must preserve recovered financial state');
sraSame('REIMBURSEMENT_CREDIT_CONFIRMED',$recoveredAfterReceipt['terminal_reason'],'late warehouse intake must preserve recovered terminal reason');
sraSame('2026-07-05 09:30:00',$recoveredAfterReceipt['closed_at'],'late warehouse intake must preserve recovered financial closure timestamp');
$policies=[];foreach(SvAmazonReturnPolicySeeder::definitions() as $i=>$row)$policies[]=['id'=>$i+1]+$row;
$eligibleCase=array_replace($verified,['program'=>'STANDARD','marketplace_id'=>'A2Q3Y263D00KWC','policies'=>$policies,'expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00']);
$policy=SvAmazonReturnPolicyEngine::evaluate($eligibleCase,new DateTimeImmutable('2026-08-20T12:00:00Z'));
sraSame(false,$policy['eligible'],'authoritative seller intake blocks non-return opening');
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "seller-receipt-authority-test: OK\n";
