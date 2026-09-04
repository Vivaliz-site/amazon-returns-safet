<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/amazon-returns/Config.php';
require_once __DIR__ . '/../includes/amazon-returns/PolicySeeder.php';
require_once __DIR__ . '/../includes/amazon-returns/Runtime.php';

function rtSame(mixed $expected,mixed $actual,string $message):void{if($expected!==$actual)throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true));}
function rtAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$defaults=new SvAmazonReturnsConfig([]);
rtSame(false,$defaults->enabled(),'Master switch defaults off.');
rtSame('dry-run',$defaults->mode(),'Mode defaults dry-run.');
foreach(['gmail_ingest','safe_t_write','appeal_write','email_review_write','support_write','policy_monitor'] as $flag)rtSame(false,$defaults->flag($flag),$flag.' defaults off.');
$enabled=new SvAmazonReturnsConfig(['AMAZON_RETURNS_ENABLED'=>'1','AMAZON_RETURNS_MODE'=>'production','AMAZON_RETURNS_SAFE_T_WRITE'=>'1','AMAZON_RETURNS_APPEAL_WRITE'=>'1','AMAZON_RETURNS_EMAIL_REVIEW_WRITE'=>'1','AMAZON_RETURNS_SUPPORT_WRITE'=>'1']);
foreach(['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN'] as $action)rtSame(true,$enabled->externalWriteAllowed($action),$action.' explicit flag.');
rtSame(false,$enabled->externalWriteAllowed('DELETE'),'Unknown action never writes.');

$policies=SvAmazonReturnPolicySeeder::definitions();
rtSame(3,count($policies),'BR policy seed count.');
foreach($policies as $policy){
    rtSame(75,(int)$policy['eligibility_days'],'All active return-not-received policies must be D+75.');
    rtAssert(preg_match('/^[a-f0-9]{64}$/',(string)$policy['source_hash'])===1,'Policy hash required.');
}

$service=(string)file_get_contents(__DIR__.'/../deploy/systemd/amazon-returns-safet.service');
foreach(['/home/ubuntu/amazon-returns-deploy/current','/home/ubuntu/amazon-returns-deploy/shared/.env','amazon-returns-safet'] as $needle)rtAssert(str_contains($service,$needle),'Standalone service missing '.$needle);
rtAssert(!str_contains($service,'shopvivaliz-deploy'),'Standalone service cannot use website deployment.');
$installer=(string)file_get_contents(__DIR__.'/../scripts/install-service.sh');
rtAssert(str_contains($installer,'amazon-returns-safet.service'),'Installer must own standalone service.');
rtAssert(!str_contains($installer,'SHOPVIVALIZ_DEPLOY_ROOT'),'Installer cannot use website deploy root.');

$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
rtAssert(str_contains($daemon,"claimBatch($this->db,10,['SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY'])"),'Gmail worker must claim only email writes.');
rtAssert(str_contains($daemon,"['SAFE_T_SUBMIT','SAFE_T_APPEAL','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE']"),'Seller Central worker must not claim Gmail writes.');
rtAssert(str_contains($daemon,'amazon_returns_pdo()'),'Daemon must use standalone DB bootstrap.');
rtAssert(!str_contains($daemon,'config/constants.php'),'Daemon cannot load website constants.');
rtAssert(!str_contains($daemon,'includes/pdo-database.php'),'Daemon cannot load website DB layer.');

$cadence=SvAmazonReturnsRuntime::cadences();
rtSame(300,$cadence['gmail'],'Gmail cadence.');
rtSame(600,$cadence['scheduler'],'Scheduler cadence.');
rtSame(1800,$cadence['financial'],'Financial cadence.');

echo "amazon-returns-runtime-test: OK\n";
