<?php
declare(strict_types=1);

function ppAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$scriptPath=__DIR__.'/../scripts/provision-production.sh';
$vhostPath=__DIR__.'/../deploy/apache/returns.shopvivaliz.com.br.conf';
ppAssert(is_file($scriptPath),'Production provision script must exist.');
ppAssert(is_file($vhostPath),'Dedicated Apache vhost must exist.');

$script=(string)file_get_contents($scriptPath);
$vhost=(string)file_get_contents($vhostPath);
ppAssert(str_contains($script,'amazon_returns_safet'),'Provisioning must create the dedicated database.');
ppAssert(str_contains($script,'amazon_returns_app'),'Provisioning must create the dedicated DB user.');
ppAssert(str_contains($script,'/home/ubuntu/amazon-returns-deploy'),'Provisioning must own the isolated deploy root.');
ppAssert(str_contains($script,'AMAZON_RETURNS_SAFE_T_WRITE=0'),'New runtime must start with SAFE-T writes disabled.');
ppAssert(str_contains($script,'AMAZON_RETURNS_APPEAL_WRITE=0'),'New runtime must start with appeal writes disabled.');
ppAssert(str_contains($script,'AMAZON_RETURNS_EMAIL_REVIEW_WRITE=0'),'New runtime must start with email writes disabled.');
ppAssert(str_contains($script,'AMAZON_RETURNS_SUPPORT_WRITE=0'),'New runtime must start with support writes disabled.');
ppAssert(str_contains($script,'amazon_return_cases'),'Provisioning must migrate existing subsystem state.');
ppAssert(str_contains($script,'amazon-returns-deploy.timer'),'Provisioning must install the independent deploy timer.');
ppAssert(str_contains($script,'verify-migration.sh'),'Source import must be verified before cutover.');
ppAssert(str_contains($script,'.release-sha'),'Provisioning must record the deployed target commit.');
ppAssert(str_contains($script,'python3-certbot-dns-cloudflare'),'Provisioning must support Cloudflare DNS-01 instead of relying on origin port 80.');
ppAssert(str_contains($script,'--dns-cloudflare'),'TLS issuance must use Cloudflare DNS validation.');
ppAssert(str_contains($script,'CLOUDFLARE_DNS_API_TOKEN_FILE'),'TLS bootstrap must accept the DNS token from a protected file.');
ppAssert(str_contains($script,'cloudflare-certbot.ini'),'TLS renewals must retain root-only DNS credentials outside the release.');
ppAssert(str_contains($script,'-o root -g www-data -m 0770 "$shared"'),'Shared runtime directory must be group-writable by the service.');
ppAssert(str_contains($script,'-o www-data -g www-data -m 0750 "$shared/evidence"'),'Evidence directory must be writable by the service user.');
ppAssert(str_contains($script,'-o root -g root -m 0700 "$shared/private"'),'Private bootstrap secrets must remain root-only.');
ppAssert(!str_contains($script,'--webroot'),'TLS issuance must not depend on public origin port 80.');
ppAssert(substr_count($script,'SvAmazonReturnsRuntime::bootstrap') >= 2,'Provisioning must bootstrap once before import and again after import to enforce D+75 policy.');
ppAssert(str_contains($vhost,'ServerName returns.shopvivaliz.com.br'),'Vhost must own the isolated hostname.');
ppAssert(str_contains($vhost,'/home/ubuntu/amazon-returns-deploy/current'),'Vhost must serve the isolated release.');
ppAssert(!str_contains($vhost,'shopvivaliz-deploy/current'),'Vhost must not serve website code.');

echo "production-provision-test: OK\n";