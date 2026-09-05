<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/PolicySeeder.php';
require_once __DIR__.'/../includes/amazon-returns/PolicyEngine.php';
$errors=[];
function pmEq(mixed $want,mixed $got,string $label):void{global $errors;if($want!==$got)$errors[]=$label.' expected='.json_encode($want).' actual='.json_encode($got);}
$defs=SvAmazonReturnPolicySeeder::definitions();
$rows=[];foreach($defs as $i=>$p){$rows[]=array_replace($p,['id'=>$i+1]);}
$by=array_column($rows,null,'program');
pmEq(45,$by['STANDARD']['eligibility_days'],'STANDARD policy source says45');
pmEq(60,$by['FBA_ONSITE']['eligibility_days'],'Onsite post-cutover says60');
pmEq(60,$by['DELIVERY_BY_AMAZON']['eligibility_days'],'DBA post-cutover says60');
$base=['marketplace_id'=>'A2Q3Y263D00KWC','quantity_ordered'=>1,'quantity_refunded'=>1,'quantity_received'=>0,'physical_status'=>'NOT_RECEIVED','refund_initiator'=>'AMAZON_AUTOMATIC','refund_at'=>'2026-06-01 10:00:00','seller_debit_at'=>'2026-06-01 10:00:00','policies'=>$rows];
foreach(['STANDARD','FBA_ONSITE','DELIVERY_BY_AMAZON'] as $program){
 foreach(['2026-04-20 23:59:59'=>45,'2026-04-21 00:00:00'=>($program==='STANDARD'?45:60)] as $orderAt=>$days){
  $case=$base+['program'=>$program,'order_at'=>$orderAt];$at=(new DateTimeImmutable($base['refund_at'],new DateTimeZone('UTC')))->modify('+'.$days.' days');
  pmEq(false,SvAmazonReturnPolicyEngine::evaluate($case,$at->modify('-1 second'))['eligible'],$program.' not early '.$orderAt);
  pmEq(true,SvAmazonReturnPolicyEngine::evaluate($case,$at)['eligible'],$program.' exact threshold '.$orderAt);
 }
}
$c=$base+['program'=>'DELIVERY_BY_AMAZON','order_at'=>'2026-05-01 00:00:00'];$c['seller_debit_at']='2026-06-03 10:00:00';
pmEq('2026-07-31 10:00:00',SvAmazonReturnPolicyEngine::evaluate($c,new DateTimeImmutable('2026-08-01T00:00:00Z'))['eligibility_at'],'DBA60 is after refund, not later debit');
$c['order_at']=null;pmEq(false,SvAmazonReturnPolicyEngine::evaluate($c,new DateTimeImmutable('2026-12-01T00:00:00Z'))['eligible'],'missing order date fails closed');
$c=$base+['program'=>'FBA','order_at'=>'2026-05-01 00:00:00'];pmEq(false,SvAmazonReturnPolicyEngine::evaluate($c,new DateTimeImmutable('2026-12-01T00:00:00Z'))['eligible'],'classic FBA is separate');
$c=$base+['program'=>'STANDARD','order_at'=>'2026-05-01 00:00:00'];$old=$by['STANDARD'];$old['id']=9;$new=$old;$new['id']=10;$new['eligibility_days']=44;$c['policies']=[$old,$new];
pmEq(10,SvAmazonReturnPolicyEngine::evaluate($c,new DateTimeImmutable('2026-08-01T00:00:00Z'))['policy_version_id'],'numeric policy version10 must beat9');
$matrix=__DIR__.'/../includes/amazon-returns/PolicyMatrix.php';
if(!is_file($matrix)){$errors[]='Missing exact program/date/basis matrix validator';}else{
 require_once $matrix;
 pmEq([],SvAmazonReturnPolicyMatrix::violations($rows),'approved matrix validates');
 $bad=$rows;$bad[1]['eligibility_days']=45;pmEq(true,SvAmazonReturnPolicyMatrix::violations($bad)!==[],'all45 is not the approved matrix');
 $bad=$rows;$bad[0]['eligibility_days']=75;pmEq(true,SvAmazonReturnPolicyMatrix::violations($bad)!==[],'stale75 rejected');
 pmEq(true,SvAmazonReturnPolicyMatrix::violations(array_slice($rows,0,2))!==[],'missing program rejected');
 $bad=$rows;$bad[2]['effective_from']='2026-04-20';pmEq(true,SvAmazonReturnPolicyMatrix::violations($bad)!==[],'wrong cutover rejected');
}
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "policy-matrix-production-test: OK\n";
