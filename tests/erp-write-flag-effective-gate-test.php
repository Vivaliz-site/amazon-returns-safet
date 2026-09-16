<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Config.php';
function ewfSame(mixed $want,mixed $got,string $why):void{
    if($want!==$got)throw new RuntimeException($why.' expected='.var_export($want,true).' actual='.var_export($got,true));
}
$path=tempnam(sys_get_temp_dir(),'erp-write-profile-');
$profile=[
 'version'=>'erp-effective-gate-test',
 'SAFE_T_SUBMIT'=>true,'SAFE_T_APPEAL'=>true,
 'SAFE_T_EMAIL_REVIEW'=>true,'SAFE_T_EMAIL_REPLY'=>true,
 'SELLER_SUPPORT_OPEN'=>true,'SELLER_SUPPORT_UPDATE'=>true,
 'ERP_SALES_RETURN_CREATE'=>true,
];
file_put_contents($path,json_encode($profile,JSON_THROW_ON_ERROR));
$base=['AMAZON_RETURNS_ENABLED'=>'1','AMAZON_RETURNS_MODE'=>'production','AMAZON_RETURNS_WRITE_PROFILE_FILE'=>$path];
$off=new SvAmazonReturnsConfig($base+['AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED'=>'0']);
ewfSame(false,$off->writeFlags()['ERP_SALES_RETURN_CREATE'],'reported ERP flag must include the dedicated environment gate');
$on=new SvAmazonReturnsConfig($base+['AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED'=>'1']);
ewfSame(true,$on->writeFlags()['ERP_SALES_RETURN_CREATE'],'reported ERP flag must be true only when both gates allow writes');
unlink($path);
echo "erp-write-flag-effective-gate-test: OK\n";