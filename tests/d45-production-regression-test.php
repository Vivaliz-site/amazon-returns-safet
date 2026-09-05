<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/PolicySeeder.php';
require_once __DIR__.'/../includes/amazon-returns/PolicyEngine.php';
require_once __DIR__.'/../includes/amazon-returns/SafeTStatusService.php';
require_once __DIR__.'/../includes/amazon-returns/FinancialReconciler.php';
$failures=[];
function d45eq(mixed $expected,mixed $actual,string $name):void {
    global $failures;
    if($expected!==$actual)$failures[]=$name.' expected='.json_encode($expected).' actual='.json_encode($actual);
}
$definitions=SvAmazonReturnPolicySeeder::definitions();
$policies=[];
foreach($definitions as $index=>$definition)$policies[]=['id'=>$index+1]+$definition;
foreach(['STANDARD','FBA_ONSITE','DELIVERY_BY_AMAZON'] as $program){
    $case=['marketplace_id'=>'A2Q3Y263D00KWC','program'=>$program,'order_at'=>'2026-05-01 08:00:00','seller_debit_at'=>'2026-05-03 12:00:00','refund_at'=>'2026-05-02 12:00:00','refund_initiator'=>'AMAZON_AUTOMATIC','quantity_ordered'=>1,'quantity_refunded'=>1,'quantity_received'=>0,'physical_status'=>'NOT_RECEIVED','policies'=>$policies];
    foreach(['2026-06-16T11:59:59Z'=>false,'2026-06-16T12:00:00Z'=>true,'2026-06-16T09:00:00-03:00'=>true] as $at=>$expected){
        $result=SvAmazonReturnPolicyEngine::evaluate($case,new DateTimeImmutable($at));
        d45eq($expected,$result['eligible'],$program.' D45 boundary '.$at);
        d45eq('2026-06-16 12:00:00',$result['eligibility_at'],$program.' operational date');
    }
    $damaged=$case;$damaged['quantity_received']=1;$damaged['physical_status']='RECEIVED_DISCREPANT';
    d45eq('RECEIVED_DISCREPANT',SvAmazonReturnPolicyEngine::evaluate($damaged,new DateTimeImmutable('2026-09-05T00:00:00Z'))['state'],'Full damaged receipt must not become RECEIVED_OK');
}
$old=$policies[0];$old['id']=9;$old['eligibility_days']=75;
$new=$old;$new['id']=10;$new['eligibility_days']=45;
$case['program']='STANDARD';$case['policies']=[$old,$new];
d45eq(10,SvAmazonReturnPolicyEngine::evaluate($case,new DateTimeImmutable('2026-06-16T12:00:00Z'))['policy_version_id'],'Numeric policy revision 10 after 9');
foreach(['APPEAL_SUBMITTED','APPEAL_DENIED_FINAL','EMAIL_REVIEW_SENT','EMAIL_REVIEW_RESPONSE_PENDING','SUPPORT_ESCALATION','RECOVERED'] as $state){
    d45eq($state,SvAmazonSafeTStatusService::nextState($state,'DENIED',false),'Generic denial must not rewind '.$state);
}
$r=new SvAmazonFinancialReconciler();
$financialCase=['state'=>'SAFE_T_APPROVED','expected_reimbursement_amount'=>'100.00','currency'=>'BRL'];
$tx=['transaction_id'=>'t1','transaction_type'=>'SAFE_T_REIMBURSEMENT','transaction_status'=>'DEFERRED','posted_at'=>'2026-09-01T00:00:00Z','total_amount'=>['amount'=>'100.00','currency'=>'BRL']];
d45eq('0.00',$r->reconcile($financialCase,[$tx])['credit_amount'],'DEFERRED is not released money');
$tx['transaction_status']='RELEASED';$tx['total_amount']['amount']='-100.00';
d45eq('0.00',$r->reconcile($financialCase,[$tx])['credit_amount'],'Negative reimbursement cannot become positive credit');
$tx['total_amount']['amount']='40.00';
d45eq('40.00',$r->reconcile($financialCase,[$tx,$tx])['credit_amount'],'Duplicate transaction ID counts once');
$tx['total_amount']['amount']='100.00';$tx['total_amount']['currency']='USD';
d45eq('0.00',$r->reconcile($financialCase,[$tx])['credit_amount'],'Different currency cannot recover BRL exposure');
if($failures!==[]){fwrite(STDERR,implode("\n",$failures)."\n");exit(1);}
echo "d45-production-regression-test: OK\n";
