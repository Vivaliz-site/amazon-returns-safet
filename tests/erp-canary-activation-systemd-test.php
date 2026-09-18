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
eaAssert(($global['ERP_SALES_RETURN_CREATE']??null)===true,'Global daemon ERP gate must be enabled after the proven production canary.');
$canaryEnv=(string)file_get_contents(__DIR__.'/../deploy/erp-canary-execute.env');
eaAssert(!str_contains($canaryEnv,'AMAZON_RETURNS_WRITE_CANARY_CASE_IDS='),'Versioned canary env must not pin a stale case ID.');
eaAssert(str_contains($canaryEnv,'AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED=1'),'Dedicated ERP gate must remain isolated to canary execution.');
$unit=(string)file_get_contents(__DIR__.'/../deploy/systemd/amazon-returns-erp-canary-execute.service');
eaAssert(str_contains($unit,'User=www-data'),'Canary execute must run as application identity.');
eaAssert(str_contains($unit,'--mode=execute --handoff'),'Canary execute must consume the discovery handoff, not select a new candidate.');
eaAssert(!str_contains($unit,'--auto'),'Runtime auto-selection must not race the read-only discovery result.');
eaAssert(!str_contains($unit,'--case-id='),'Canary execute must not pin a stale case ID.');
$marker='/home/ubuntu/amazon-returns-deploy/shared/erp-canary-execute-once';
$handoff='/home/ubuntu/amazon-returns-deploy/shared/erp-canary-candidate.json';
eaAssert(str_contains($unit,'ExecCondition=/usr/bin/test -f '.$marker),'Systemd unit must require explicit one-shot arming.');
$provision=(string)file_get_contents(__DIR__.'/../scripts/provision-production.sh');
$discoverPos=strpos($provision,'systemctl start amazon-returns-erp-canary-discovery.service');
$executePos=strpos($provision,'systemctl start amazon-returns-erp-canary-execute.service');
eaAssert(is_int($discoverPos)&&is_int($executePos)&&$executePos>$discoverPos,'Execute must start only after read-only discovery.');
eaAssert(str_contains($provision,$marker),'Provision must preserve the explicit one-shot write arm.');
$runner=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/erp-sales-return-canary.php');
eaAssert(str_contains($runner,$handoff),'Runner must use a durable discovery handoff path.');
eaAssert(str_contains($runner,'CANARY_HANDOFF_REQUIRED'),'Execute without discovery handoff must fail closed.');
eaAssert(str_contains($runner,'CANARY_HANDOFF_EXPIRED'),'Stale discovery handoff must fail closed.');
eaAssert(str_contains($runner,'CANARY_HANDOFF_MISMATCH'),'Any revalidation drift from the discovered candidate must fail closed.');
eaAssert(str_contains($runner,"'generated_at'=>gmdate('c')"),'Discovery handoff must record an explicit UTC generation time.');
eaAssert(str_contains($runner,"'case_id'=>(int)\$candidate['case_id']") && str_contains($runner,"'order_id'=>(string)\$candidate['order_id']"),'Discovery handoff must bind case and order identity.');
eaAssert(str_contains($runner,"'original_invoice_id'=>(string)\$candidate['original_invoice_id']"),'Discovery handoff must bind original invoice identity.');
$loadPos=strpos($runner,'CANARY_HANDOFF_REQUIRED');
$consumeMarkerPos=strpos($runner,'unlink($erpCanaryMarker)');
$candidateQueryPos=strpos($runner,'SELECT * FROM amazon_return_erp_sales_returns');
eaAssert(is_int($loadPos)&&is_int($consumeMarkerPos)&&is_int($candidateQueryPos),'Runner must expose handoff load, arm consumption and candidate revalidation.');
eaAssert($consumeMarkerPos<$loadPos,'Every execute attempt must consume the one-shot arm before validating the handoff.');
eaAssert($loadPos<$candidateQueryPos,'Execute must load the handoff before re-querying candidate state.');
eaAssert($consumeMarkerPos<$candidateQueryPos,'One-shot arm must be consumed before revalidation or any external write path.');
echo "erp-canary-activation-systemd-test: OK\n";
