<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/BusinessHealth.php';
function bhoSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.var_export($want,true).' actual='.var_export($got,true));}
$ready=['sp_api'=>['ready'=>true],'gmail'=>['ready'=>true],'seller_central_bridge'=>['ready'=>true]];
$flags=['SAFE_T_SUBMIT'=>true,'SAFE_T_APPEAL'=>true,'SAFE_T_EMAIL_REVIEW'=>true,'SAFE_T_EMAIL_REPLY'=>true,'SELLER_SUPPORT_OPEN'=>true,'SELLER_SUPPORT_UPDATE'=>true,'ERP_SALES_RETURN_CREATE'=>true];
$result=SvAmazonBusinessHealth::evaluate(true,'production',$ready,$flags,['status'=>'OK'],['TASK_SCHEDULER_STALE']);
bhoSame('DEGRADED',$result['status'],'operational blocker must degrade business health');
bhoSame(true,in_array('TASK_SCHEDULER_STALE',$result['blockers'],true),'operational blocker must be preserved');
echo "business-health-operational-blocker-test: OK\n";