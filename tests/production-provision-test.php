<?php
declare(strict_types=1);

function ppAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$scriptPath=__DIR__.'/../scripts/provision-production.sh';
$liveVerificationPath=__DIR__.'/../scripts/verify-live-tenant-foundation.sh';
$vhostPath=__DIR__.'/../deploy/apache/returns.shopvivaliz.com.br.conf';
$runbookPath=__DIR__.'/../docs/runbooks/tenant-foundation-migration.md';
ppAssert(is_file($scriptPath),'Production provision script must exist.');
ppAssert(is_file($liveVerificationPath),'Live tenant verification script must exist.');
ppAssert(is_file($vhostPath),'Dedicated Apache vhost must exist.');
ppAssert(is_file($runbookPath),'Tenant migration runbook must exist.');

$script=(string)file_get_contents($scriptPath);
$liveVerification=(string)file_get_contents($liveVerificationPath);
$vhost=(string)file_get_contents($vhostPath);
$runbook=(string)file_get_contents($runbookPath);
ppAssert(str_contains($script,'amazon_returns_safet'),'Provisioning must create the dedicated database.');
ppAssert(str_contains($script,'amazon_returns_app'),'Provisioning must create the dedicated DB user.');
ppAssert(str_contains($script,'/home/ubuntu/amazon-returns-deploy'),'Provisioning must own the isolated deploy root.');
ppAssert(str_contains($script,'AMAZON_RETURNS_SAFE_T_WRITE=0'),'New runtime must start with SAFE-T writes disabled.');
ppAssert(str_contains($script,'AMAZON_RETURNS_APPEAL_WRITE=0'),'New runtime must start with appeal writes disabled.');
ppAssert(str_contains($script,'AMAZON_RETURNS_EMAIL_REVIEW_WRITE=0'),'New runtime must start with email writes disabled.');
ppAssert(str_contains($script,'AMAZON_RETURNS_SUPPORT_WRITE=0'),'New runtime must start with support writes disabled.');
foreach([
    "ensure_env_key 'AMAZON_RETURNS_TENANT_SLUG' 'shopvivaliz'",
    "ensure_env_key 'AMAZON_RETURNS_TENANT_NAME' 'ShopVivaliz'",
    "ensure_env_key 'AMAZON_RETURNS_CONNECTION_KEY' 'amazon-br-primary'",
    "ensure_env_key 'AMAZON_RETURNS_CONNECTION_LABEL' 'Amazon Brasil principal'",
    "ensure_env_key 'AMAZON_SP_API_REGION' 'NA'",
] as $identityLine){
    ppAssert(str_contains($script,$identityLine),'Provisioning must install identity without overwriting: '.$identityLine);
}
$dryRunPos=strpos($script,'migrate-single-tenant-to-multitenant.php --dry-run');
$applyPos=strpos($script,'apply_cmd=');
$swapPos=strpos($script,'ln -sfn');
ppAssert($dryRunPos!==false,'Provisioning must execute migration dry-run preflight.');
ppAssert($applyPos!==false && $dryRunPos<$applyPos,'Dry-run must precede the printed apply command.');
ppAssert($swapPos!==false && $applyPos<$swapPos,'Release symlink must not switch before migration preflight gate.');
foreach(['backup_cmd=','apply_cmd=','verify_cmd=','rollback_cmd=','migration_preflight_required=true'] as $needle){
    ppAssert(str_contains($script,$needle),'Provisioning must print operator command '.$needle);
}
ppAssert(str_contains($script,'AMAZON_RETURNS_IMPORT_SOURCE:-0')===false,'Provisioning must not retain destructive automatic source import.');
ppAssert(str_contains($script,'amazon_return_cases'),'Provisioning must migrate existing subsystem state.');
ppAssert(str_contains($script,'amazon-returns-deploy.timer'),'Provisioning must install the independent deploy timer.');
ppAssert(str_contains($script,'verify-migration.sh'),'Source import must be verified before cutover.');
ppAssert(str_contains($script,'expected_cases="$(mysql --protocol=socket -uroot -Nse'),'Provisioning must snapshot the live eligible case count at preflight instead of hard-coding 37.');
ppAssert(str_contains($script,'SELECT COUNT(*) FROM amazon_return_cases'),'Provisioning live case-count snapshot must query the target cases.');
ppAssert(!str_contains($script,'AMAZON_RETURNS_EXPECTED_CASES=37'),'Provisioning must not freeze a historical case count while live ingestion continues.');
ppAssert(str_contains($script,'expected_cases" -eq 0'),'A zero live case snapshot must route to the explicit onboarding gate.');
ppAssert(str_contains($script,'printf -v verify_cmd'),'Operator migration commands must be shell-quoted before printing.');
ppAssert(str_contains($script,'%q'),'Operator migration command values must use shell-safe quoting.');
ppAssert(str_contains($script,'.release-sha'),'Provisioning must record the deployed target commit.');
ppAssert(str_contains($script,'python3-certbot-dns-cloudflare'),'Provisioning must support Cloudflare DNS-01 instead of relying on origin port 80.');
ppAssert(str_contains($script,'--dns-cloudflare'),'TLS issuance must use Cloudflare DNS validation.');
ppAssert(str_contains($script,'CLOUDFLARE_DNS_API_TOKEN_FILE'),'TLS bootstrap must accept the DNS token from a protected file.');
ppAssert(str_contains($script,'cloudflare-certbot.ini'),'TLS renewals must retain root-only DNS credentials outside the release.');
ppAssert(str_contains($script,'-o root -g www-data -m 0770 "$shared"'),'Shared runtime directory must be group-writable by the service.');
ppAssert(str_contains($script,'-o www-data -g www-data -m 0750 "$shared/evidence"'),'Evidence directory must be writable by the service user.');
ppAssert(str_contains($script,'-o root -g root -m 0700 "$shared/private"'),'Private bootstrap secrets must remain root-only.');
ppAssert(substr_count($script,'runuser -u ubuntu -- git -C "$repo"') >= 2,'Root provisioning must run repository git reads as the checkout owner.');
ppAssert(!str_contains($script,'--webroot'),'TLS issuance must not depend on public origin port 80.');
ppAssert(str_contains($script,'SvAmazonReturnsRuntime::bootstrap($db,$context)'),'Provisioning must bootstrap only after resolving tenant context.');
ppAssert(str_contains($script,'verify-live-tenant-foundation.sh'),'Subsequent releases must verify live tenant invariants without comparing mutable runtime state to the migration snapshot.');
foreach(['tenant_count','connection_count','target_current_cases','ownership_nulls','cross_tenant_mismatch_count','processing_jobs','write_flags_disabled','live_tenant_verification=ok'] as $needle){
    ppAssert(str_contains($liveVerification,$needle),'Live tenant verification missing '.$needle);
}
foreach(['amazon_return_connections','amazon_return_tenant_users','amazon_return_feature_flags','updated_by_user_id'] as $needle){
    ppAssert(str_contains($liveVerification,$needle),'Live tenant verification must cover '.$needle);
}
ppAssert(str_contains($vhost,'ServerName returns.shopvivaliz.com.br'),'Vhost must own the isolated hostname.');
ppAssert(str_contains($vhost,'/home/ubuntu/amazon-returns-deploy/current'),'Vhost must serve the isolated release.');
ppAssert(!str_contains($vhost,'shopvivaliz-deploy/current'),'Vhost must not serve website code.');
foreach(['--dry-run','mysqldump','--apply','verify-migration.sh','rollback','37/37','ownership_nulls=0','cross_tenant_mismatch_count=0','processing_jobs=0','write_flags_disabled=true','shadow','SAFE_T_READ'] as $runbookNeedle){
    ppAssert(str_contains($runbook,$runbookNeedle),'Runbook missing '.$runbookNeedle);
}

echo "production-provision-test: OK\n";
