<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Config.php';
function eaAssert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
$profilePath=__DIR__.'/../deploy/write-profile-erp-canary.json';
$profile=is_file($profilePath)?json_decode((string)file_get_contents($profilePath),true):null;
eaAssert(is_array($profile),'Dedicated ERP canary write profile must exist.');
eaAssert(($profile['ERP_SALES_RETURN_CREATE']??null)===true,'Dedicated canary profile must enable only the ERP sales-return action.');
foreach(['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'] as $action){eaAssert(($profile[$action]??null)===false,'Dedicated canary profile must disable '.$action);}
$cfg=new SvAmazonReturnsConfig([
    'AMAZON_RETURNS_ENABLED'=>'1','AMAZON_RETURNS_MODE'=>'production',
    'AMAZON_RETURNS_WRITE_PROFILE_FILE'=>$profilePath,
    'AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED'=>'1',
    'AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'=>'13233',
]);
eaAssert($cfg->erpSalesReturnCreateEnabled()===true,'Dedicated canary config must make the ERP gate effective.');
eaAssert($cfg->writeCaseAllowed(13233)===true && $cfg->writeCaseAllowed(13232)===false,'Dedicated canary config must allow only case 13233.');
$global=json_decode((string)file_get_contents(__DIR__.'/../deploy/write-profile.json'),true);
eaAssert(($global['ERP_SALES_RETURN_CREATE']??null)===false,'Global daemon ERP gate must stay disabled during canary.');
$unitPath=__DIR__.'/../deploy/systemd/amazon-returns-erp-canary-execute.service';
$unit=is_file($unitPath)?(string)file_get_contents($unitPath):'';
eaAssert(str_contains($unit,'User=www-data'),'Canary execute must run as application identity.');
eaAssert(str_contains($unit,'AMAZON_RETURNS_WRITE_PROFILE_FILE=/home/ubuntu/amazon-returns-deploy/current/deploy/write-profile-erp-canary.json'),'Canary execute must use the isolated write profile.');
eaAssert(str_contains($unit,'AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED=1'),'Canary execute must enable the dedicated environment gate only for the oneshot.');
eaAssert(str_contains($unit,'AMAZON_RETURNS_WRITE_CANARY_CASE_IDS=13233'),'Canary execute must be scoped exactly to case 13233.');
eaAssert(str_contains($unit,'--mode=execute --case-id=13233'),'Canary execute command must target only case 13233.');
$provision=(string)file_get_contents(__DIR__.'/../scripts/provision-production.sh');
eaAssert(str_contains($provision,'amazon-returns-erp-canary-execute.service'),'Production provision must install the execute unit.');
$discoverPos=strpos($provision,'systemctl start amazon-returns-erp-canary-discovery.service');
$executePos=strpos($provision,'systemctl start amazon-returns-erp-canary-execute.service');
eaAssert(is_int($discoverPos)&&is_int($executePos)&&$executePos>$discoverPos,'Execute canary must start only after read-only discovery.');
echo "erp-canary-activation-systemd-test: OK\n";
