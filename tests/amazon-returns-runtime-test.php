<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/Config.php';
require_once __DIR__ . '/../includes/amazon-returns/PolicySeeder.php';
require_once __DIR__ . '/../includes/amazon-returns/Runtime.php';

function rtSame(mixed $expected, mixed $actual, string $message): void { if ($expected !== $actual) throw new RuntimeException($message.'\nExpected: '.var_export($expected,true).'\nActual: '.var_export($actual,true)); }
function rtAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$defaults = new SvAmazonReturnsConfig([]);
rtSame(false, $defaults->enabled(), 'Master switch defaults off.');
rtSame('dry-run', $defaults->mode(), 'Mode defaults dry-run.');
foreach (['gmail_ingest','safe_t_write','appeal_write','email_review_write','support_write','policy_monitor'] as $flag) rtSame(false, $defaults->flag($flag), "{$flag} defaults off.");
rtSame(false, $defaults->externalWriteAllowed('SAFE_T_SUBMIT'), 'Default cannot write SAFE-T.');

$prodOnly = new SvAmazonReturnsConfig(['AMAZON_RETURNS_MODE'=>'production']);
rtSame(false, $prodOnly->externalWriteAllowed('SAFE_T_SUBMIT'), 'Production mode alone cannot enable writes.');
$enabled = new SvAmazonReturnsConfig([
 'AMAZON_RETURNS_ENABLED'=>'1','AMAZON_RETURNS_MODE'=>'production','AMAZON_RETURNS_SAFE_T_WRITE'=>'1','AMAZON_RETURNS_APPEAL_WRITE'=>'1','AMAZON_RETURNS_EMAIL_REVIEW_WRITE'=>'1','AMAZON_RETURNS_SUPPORT_WRITE'=>'1',
]);
rtSame(true, $enabled->externalWriteAllowed('SAFE_T_SUBMIT'), 'Explicit safe-t flag enables submit only with master+production.');
rtSame(true, $enabled->externalWriteAllowed('SAFE_T_APPEAL'), 'Explicit appeal flag.');
rtSame(true, $enabled->externalWriteAllowed('SAFE_T_EMAIL_REVIEW'), 'Explicit email review flag.');
rtSame(true, $enabled->externalWriteAllowed('SELLER_SUPPORT_OPEN'), 'Explicit support flag.');
rtSame(false, $enabled->externalWriteAllowed('DELETE'), 'Unknown action never writes.');

$missing = $defaults->readiness();
rtSame(false, $missing['sp_api']['ready'], 'SP-API without LWA credentials is blocked.');
rtSame(false, $missing['gmail']['ready'], 'Gmail without OAuth is blocked.');
rtSame(false, $missing['seller_central_bridge']['ready'], 'Browser bridge absent is blocked for writes.');
$lwaOnly = new SvAmazonReturnsConfig([
 'AMAZON_LWA_CLIENT_ID'=>'id','AMAZON_LWA_CLIENT_SECRET'=>'secret','AMAZON_LWA_REFRESH_TOKEN'=>'refresh',
]);
rtSame(true, $lwaOnly->readiness()['sp_api']['ready'], 'Returns SP-API readiness requires only LWA credentials; seller/marketplace are optional discovery data.');
$ready = new SvAmazonReturnsConfig([
 'AMAZON_LWA_CLIENT_ID'=>'id','AMAZON_LWA_CLIENT_SECRET'=>'secret','AMAZON_LWA_REFRESH_TOKEN'=>'refresh','AMAZON_SELLER_ID'=>'seller',
 'GMAIL_OAUTH_CLIENT_ID'=>'gid','GMAIL_OAUTH_CLIENT_SECRET'=>'gsecret','GMAIL_OAUTH_REFRESH_TOKEN'=>'grefresh',
 'SELLER_CENTRAL_BROWSER_BRIDGE_URL'=>'http://127.0.0.1:19191/amazon/seller-central',
]);
rtSame(true, $ready->readiness()['sp_api']['ready'], 'Complete LWA credentials make SP-API ready.');
rtSame(true, $ready->readiness()['gmail']['ready'], 'Dedicated Gmail OAuth credentials make Gmail ready.');
$googleOauth = new SvAmazonReturnsConfig([
 'GOOGLE_OAUTH_CLIENT_ID'=>'gid','GOOGLE_OAUTH_CLIENT_SECRET'=>'gsecret','GOOGLE_OAUTH_REFRESH_TOKEN'=>'grefresh',
]);
rtSame(true, $googleOauth->readiness()['gmail']['ready'], 'Existing Google OAuth credentials are valid aliases for Gmail readiness.');
rtSame('gid', $googleOauth->first('GMAIL_OAUTH_CLIENT_ID','GOOGLE_OAUTH_CLIENT_ID'), 'Alias resolution prefers first configured credential.');
rtSame(true, $ready->readiness()['seller_central_bridge']['ready'], 'Configured local browser bridge is ready.');

$policies = SvAmazonReturnPolicySeeder::definitions();
rtSame(3, count($policies), 'Initial policy seed has standard + FBA Onsite + DBA.');
$byProgram=[]; foreach($policies as $p){$byProgram[$p['program']]=$p; rtAssert(preg_match('/^[a-f0-9]{64}$/',$p['source_hash'])===1,'Policy source hash required.'); rtAssert(str_starts_with($p['source_url'],'https://sellercentral.amazon.com.br/'),'Policy source must be Amazon Seller Central.');}
rtSame('A2Q3Y263D00KWC',$byProgram['STANDARD']['marketplace_id'],'Brazil policy seed must use the SP-API marketplace ID, not the country code.');
rtSame(75,$byProgram['STANDARD']['eligibility_days'],'Standard rule D+75.');
rtSame(75,$byProgram['FBA_ONSITE']['eligibility_days'],'FBA Onsite D+75.');
rtSame(75,$byProgram['DELIVERY_BY_AMAZON']['eligibility_days'],'DBA D+75.');
rtSame('2026-04-21',$byProgram['FBA_ONSITE']['effective_from'],'D+60 effective date.');
rtSame('2026-04-21',$byProgram['DELIVERY_BY_AMAZON']['effective_from'],'DBA D+60 effective date.');

$cadence=SvAmazonReturnsRuntime::cadences();
rtSame(300,$cadence['gmail'],'Gmail every 5m.'); rtSame(600,$cadence['scheduler'],'Eligibility every 10m.'); rtSame(300,$cadence['seller_central'],'Seller Central outbox every 5m.'); rtSame(1800,$cadence['financial'],'Financial reconcile every 30m.'); rtSame(7200,$cadence['returns_report'],'Returns reports every 2h.'); rtSame(86400,$cadence['policy_monitor'],'Policy monitor daily.');
$due=SvAmazonReturnsRuntime::dueTasks([],new DateTimeImmutable('2026-09-01T12:00:00Z')); rtAssert(in_array('bootstrap',$due,true),'Bootstrap always due first.'); rtAssert(in_array('health',$due,true),'Health due without state.');
$state=['gmail'=>'2026-09-01T11:58:00Z','scheduler'=>'2026-09-01T11:40:00Z','seller_central'=>'2026-09-01T11:58:00Z','financial'=>'2026-09-01T11:45:00Z','returns_report'=>'2026-09-01T11:00:00Z','policy_monitor'=>'2026-09-01T00:01:00Z','health'=>'2026-09-01T11:50:00Z','sp_api'=>'2026-09-01T11:45:00Z'];
$due=SvAmazonReturnsRuntime::dueTasks($state,new DateTimeImmutable('2026-09-01T12:00:00Z')); rtAssert(in_array('scheduler',$due,true),'Overdue scheduler selected.'); rtAssert(!in_array('gmail',$due,true),'Fresh Gmail not selected.');

$service=(string)file_get_contents(__DIR__.'/../deploy/systemd/shopvivaliz-amazon-returns.service');
foreach(['User=www-data','Group=www-data','WorkingDirectory=/home/ubuntu/shopvivaliz-deploy/current','EnvironmentFile=-/home/ubuntu/shopvivaliz-deploy/shared/.env','ExecStart=/usr/bin/php /home/ubuntu/shopvivaliz-deploy/current/workers/amazon-returns/daemon.php','Restart=always','NoNewPrivileges=true'] as $needle) rtAssert(str_contains($service,$needle),'Systemd service missing '.$needle);
$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php'); rtAssert(str_contains($daemon,'--once'),'Daemon must support one-shot validation.'); rtAssert(str_contains($daemon,'AMAZON_RETURNS_RUNTIME_STATE_FILE'),'Daemon state path must be configurable/outside repo.'); rtAssert(str_contains($daemon,"sellerCentralBridgeMode() === 'polling'"),'Linux daemon must not claim Seller Central outbox in remote polling mode.');
rtAssert(!str_contains($daemon, 'schedule($this->db, $projected, [], $policy)'), 'Scheduler must not discard SAFE-T status timeline when enqueuing appeal/email actions.');
rtAssert(str_contains($daemon, 'dependencyForAction'), 'Scheduler must route email review through Gmail readiness and Seller Central writes through browser readiness.');
rtAssert(str_contains($daemon, 'claimBatch($this->db, 10, [\'SAFE_T_EMAIL_REVIEW\'])'), 'Gmail task must claim only SAFE-T email review outbox jobs.');
rtAssert(str_contains($daemon, "['SAFE_T_SUBMIT','SAFE_T_APPEAL','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE']"), 'Seller Central task must be isolated from email review jobs.');
rtAssert(str_contains($daemon, "require_once __DIR__ . '/../../includes/amazon-returns/ReturnsReport.php'"), 'Daemon must load official returns report ingestion.');
rtAssert(str_contains($daemon, "'pending_report'"), 'Daemon must retain a pending report across cycles.');
rtAssert(str_contains($daemon, 'getReport(') && str_contains($daemon, 'downloadReportDocument('), 'Daemon must complete the Reports lifecycle.');
rtAssert(str_contains($daemon, 'SvAmazonReturnsReport::parse') && str_contains($daemon, 'persistRows'), 'Daemon must parse and persist completed reports.');
$installer=(string)file_get_contents(__DIR__.'/../scripts/install-amazon-returns-service.sh');
foreach(['shopvivaliz-amazon-returns.service','AMAZON_RETURNS_ENABLED','AMAZON_RETURNS_SAFE_T_WRITE','systemctl enable --now'] as $needle) rtAssert(str_contains($installer,$needle),'Amazon returns installer missing '.$needle);
$pipeline=(string)file_get_contents(__DIR__.'/../.github/workflows/master-production-pipeline.yml');
rtAssert(str_contains($pipeline,'install-amazon-returns-service.sh'),'Master deploy must install Amazon returns service.');

echo "amazon-returns-runtime-test: OK\n";

rtAssert(str_contains($installer, 'systemctl restart "$service_name"'), 'Amazon returns installer must restart the daemon so EnvironmentFile changes are loaded.');
