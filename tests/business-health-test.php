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
bhSame('OK',$ok['status'],'all mandatory business capabilities ready');
$erp=$flags;$erp['ERP_SALES_RETURN_CREATE']=false;
$degraded=SvAmazonBusinessHealth::evaluate(true,'production',$ready,$erp,$browser);
bhSame('DEGRADED',$degraded['status'],'disabled mandatory ERP gate must degrade health');
bhSame(true,in_array('WRITE_GATE_ERP_SALES_RETURN_CREATE_DISABLED',$degraded['blockers'],true),'ERP blocker must be explicit');
$down=SvAmazonBusinessHealth::evaluate(false,'production',$ready,$flags,$browser);
bhSame('FAILED',$down['status'],'disabled subsystem must fail health');
echo "business-health-test: OK\n";