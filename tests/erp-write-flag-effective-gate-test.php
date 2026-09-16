<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Config.php';

function ewfSame(mixed $want,mixed $got,string $why):void{
    if($want!==$got)throw new RuntimeException($why.' want='.var_export($want,true).' got='.var_export($got,true));
}

$profile=tempnam(sys_get_temp_dir(),'write-profile-');
file_put_contents($profile,json_encode([
    'version'=>'test-v1','SAFE_T_SUBMIT'=>true,'SAFE_T_APPEAL'=>true,
    'SAFE_T_EMAIL_REVIEW'=>true,'SAFE_T_EMAIL_REPLY'=>true,
    'SELLER_SUPPORT_OPEN'=>true,'SELLER_SUPPORT_UPDATE'=>true,
    'ERP_SALES_RETURN_CREATE'=>true,
],JSON_THROW_ON_ERROR));
$config=new SvAmazonReturnsConfig([
    'AMAZON_RETURNS_ENABLED'=>'1','AMAZON_RETURNS_MODE'=>'production',
    'AMAZON_RETURNS_WRITE_PROFILE_FILE'=>$profile,
    'AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED'=>'0',
]);
ewfSame(false,$config->erpSalesReturnCreateEnabled(),'Effective ERP create gate should be disabled.');
ewfSame(false,$config->writeFlags()['ERP_SALES_RETURN_CREATE'],'Health-facing ERP flag must reflect both gates.');
unlink($profile);
echo "erp-write-flag-effective-gate-test: OK\n";
