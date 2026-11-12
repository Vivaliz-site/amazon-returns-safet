<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/BusinessHealth.php';
function checkHealth(bool $ok,string $why): void { if(!$ok)throw new RuntimeException($why); }
$ready=['sp_api'=>['ready'=>true],'gmail'=>['ready'=>true],'seller_central_bridge'=>['ready'=>true]];
$flags=['SAFE_T_SUBMIT'=>false,'SAFE_T_APPEAL'=>false,'SAFE_T_EMAIL_REVIEW'=>false,'SAFE_T_EMAIL_REPLY'=>false,'SELLER_SUPPORT_OPEN'=>false,'SELLER_SUPPORT_UPDATE'=>false,'ERP_SALES_RETURN_CREATE'=>false];
$result=SvAmazonBusinessHealth::evaluate(true,'production',$ready,$flags,['status'=>'OK']);
checkHealth($result['status']==='OK','Configured-off external write actions do not make the read-only service unhealthy.');
checkHealth($result['blockers']===[],'Configured-off write actions must not be operational blockers.');
checkHealth(count(array_filter($flags))===0,'Write flags must stay disabled (no mutation).');
$degraded=SvAmazonBusinessHealth::evaluate(true,'production',$ready,$flags,['status'=>'OK'],['TASK_GMAIL_STALE']);
checkHealth($degraded['status']==='DEGRADED' && in_array('TASK_GMAIL_STALE',$degraded['blockers'],true),'Real operational failure must still degrade health.');
$notReady=$ready;$notReady['sp_api']['ready']=false;
$down=SvAmazonBusinessHealth::evaluate(true,'production',$notReady,$flags,['status'=>'OK']);
checkHealth($down['status']==='DEGRADED' && in_array('READINESS_SP_API',$down['blockers'],true),'Real dependency failure must still degrade health.');
echo "business-health-write-gates-test: OK\n";
