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
]);
eaAssert($cfg->erpSalesReturnCreateEnabled()===true,'Dedicated canary config must make the ERP gate effective.');
$global=json_decode((string)file_get_contents(__DIR__.'/../deploy/write-profile.json'),true);
eaAssert(($global['ERP_SALES_RETURN_CREATE']??null)===false,'Global daemon ERP gate must stay disabled during canary.');
$canaryEnvPath=__DIR__.'/../deploy/erp-canary-execute.env';
$canaryEnv=is_file($canaryEnvPath)?(string)file_get_contents($canaryEnvPath):'';
eaAssert(!str_contains($canaryEnv,'AMAZON_RETURNS_WRITE_CANARY_CASE_IDS='),'Versioned canary env must not pin a stale case ID.');
eaAssert(str_contains($canaryEnv,'AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED=1'),'Versioned canary env must enable the dedicated ERP gate.');
eaAssert(str_contains($canaryEnv,'AMAZON_RETURNS_WRITE_PROFILE_FILE=/home/ubuntu/amazon-returns-deploy/current/deploy/write-profile-erp-canary.json'),'Versioned canary env must pin the isolated write profile.');
$unitPath=__DIR__.'/../deploy/systemd/amazon-returns-erp-canary-execute.service';
$unit=is_file($unitPath)?(string)file_get_contents($unitPath):'';
eaAssert(str_contains($unit,'User=www-data'),'Canary execute must run as application identity.');
$sharedEnvPos=strpos($unit,'EnvironmentFile=-/home/ubuntu/amazon-returns-deploy/shared/.env');
$canaryEnvPos=strpos($unit,'EnvironmentFile=/home/ubuntu/amazon-returns-deploy/current/deploy/erp-canary-execute.env');
eaAssert(is_int($sharedEnvPos)&&is_int($canaryEnvPos)&&$canaryEnvPos>$sharedEnvPos,'Canary override env file must load after shared .env.');
eaAssert(!str_contains($unit,'Environment=AMAZON_RETURNS_WRITE_CANARY_CASE_IDS='),'Case scope must not rely on Environment= because EnvironmentFile can override it.');
eaAssert(str_contains($unit,'--mode=execute --auto'),'Canary execute command must select one currently safe candidate at runtime.');
eaAssert(!str_contains($unit,'--case-id='),'Canary execute command must not pin a stale case ID.');
$provision=(string)file_get_contents(__DIR__.'/../scripts/provision-production.sh');
eaAssert(str_contains($provision,'amazon-returns-erp-canary-execute.service'),'Production provision must install the execute unit.');
$discoverPos=strpos($provision,'systemctl start amazon-returns-erp-canary-discovery.service');
$executePos=strpos($provision,'systemctl start amazon-returns-erp-canary-execute.service');
eaAssert(is_int($discoverPos)&&is_int($executePos)&&$executePos>$discoverPos,'Execute canary must start only after read-only discovery.');
$marker='/home/ubuntu/amazon-returns-deploy/shared/erp-canary-execute-once';
eaAssert(str_contains($provision,$marker),'ERP canary execute must require an explicit one-shot marker.');
$markerCheckPos=strpos($provision,'[[ -f "$erp_canary_execute_marker" ]]');
$markerRemovePos=strpos($provision,'rm -f -- "$erp_canary_execute_marker"');
eaAssert(is_int($markerCheckPos)&&$markerCheckPos<$executePos,'One-shot marker must guard canary execution.');
eaAssert(is_int($markerRemovePos)&&$markerRemovePos<$executePos,'One-shot marker must be consumed before external write to prevent automatic retries.');
eaAssert(str_contains($provision,'erp_canary_execute=skipped_not_armed'),'Unarmed deploy must explicitly skip ERP canary writes.');
echo "erp-canary-activation-systemd-test: OK\n";
