<?php
declare(strict_types=1);
$path=__DIR__.'/../includes/amazon-returns/BusinessHealth.php';
if(!is_file($path))throw new RuntimeException('BusinessHealth helper must exist.');
require_once $path;
function bhSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.var_export($want,true).' actual='.var_export($got,true));}
$ready=['sp_api'=>['ready'=>true],'gmail'=>['ready'=>true],'seller_central_bridge'=>['ready'=>true]];
$flags=['SAFE_T_SUBMIT'=>true,'SAFE_T_APPEAL'=>true,'SAFE_T_EMAIL_REVIEW'=>true,'SAFE_T_EMAIL_REPLY'=>true,'SELLER_SUPPORT_OPEN'=>true,'SELLER_SUPPORT_UPDATE'=>true,'ERP_SALES_RETURN_CREATE'=>true];
$browser=['status'=>'OK'];
$ok=SvAmazonBusinessHealth::evaluate(true,'production',$ready,$flags,$browser);
bhSame('OK',$ok['status'],'all business read dependencies ready');
$erp=$flags;$erp['ERP_SALES_RETURN_CREATE']=false;
$noWrite=SvAmazonBusinessHealth::evaluate(true,'production',$ready,$erp,$browser);
bhSame('OK',$noWrite['status'],'intentionally disabled write gate must not degrade read service health');
bhSame([],$noWrite['blockers'],'disabled write gate must not enter operational blockers');
bhSame(false,$erp['ERP_SALES_RETURN_CREATE'],'write capability remains disabled');
$notReady=$ready;$notReady['sp_api']['ready']=false;
$degraded=SvAmazonBusinessHealth::evaluate(true,'production',$notReady,$erp,$browser);
bhSame('DEGRADED',$degraded['status'],'unready SP-API dependency must degrade health');
bhSame(true,in_array('READINESS_SP_API',$degraded['blockers'],true),'SP-API blocker must be explicit');
$down=SvAmazonBusinessHealth::evaluate(false,'production',$ready,$flags,$browser);
bhSame('FAILED',$down['status'],'disabled subsystem must fail health');
echo "business-health-test: OK\n";
