<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Config.php';
function wgrSame(mixed $a,mixed $b,string $m):void{if($a!==$b)throw new RuntimeException($m.' expected='.var_export($a,true).' actual='.var_export($b,true));}
$path=tempnam(sys_get_temp_dir(),'write-revision-');
$profile=['version'=>'write-revision-test','SAFE_T_SUBMIT'=>true,'SAFE_T_APPEAL'=>true,'SAFE_T_EMAIL_REVIEW'=>true,'SAFE_T_EMAIL_REPLY'=>true,'SELLER_SUPPORT_OPEN'=>true,'SELLER_SUPPORT_UPDATE'=>true,'ERP_SALES_RETURN_CREATE'=>true];
file_put_contents($path,json_encode($profile,JSON_THROW_ON_ERROR));
$base=['AMAZON_RETURNS_ENABLED'=>'1','AMAZON_RETURNS_MODE'=>'production','AMAZON_RETURNS_WRITE_PROFILE_FILE'=>$path];
$off=new SvAmazonReturnsConfig($base+['AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED'=>'0']);
$on=new SvAmazonReturnsConfig($base+['AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED'=>'1']);
wgrSame(true,method_exists($off,'writeConfigurationRevision'),'config must expose effective write configuration revision');
wgrSame(false,$off->writeConfigurationRevision()===$on->writeConfigurationRevision(),'ERP dedicated gate change must change effective write revision');
unlink($path);
echo "write-gate-revision-test: OK\n";