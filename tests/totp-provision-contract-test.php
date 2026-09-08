<?php
declare(strict_types=1);
$script=(string)file_get_contents(__DIR__.'/../scripts/provision-amazon-totp-authenticator.sh');
$generator=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/totp-current.py');
foreach(['no-agent-forwarding','no-port-forwarding','no-pty','no-X11-forwarding','no-user-rc','command=\"/usr/local/lib/shopvivaliz/amazon-totp/current\"'] as $needle){
    if(!str_contains($script,$needle))throw new RuntimeException('Missing SSH restriction '.$needle);
}
if(!str_contains($script,'amazon-totp-enroll'))throw new RuntimeException('Root-only enrollment helper must be installed.');
if(!str_contains($script,'stty -echo'))throw new RuntimeException('Interactive enrollment must not echo the seed.');
if(str_contains($script,'otpauth://') || preg_match('/[A-Z2-7]{32,}/',$script))throw new RuntimeException('Provisioner must not contain a static seed.');
if(!str_contains($generator,'SEED_PERMISSIONS_INVALID'))throw new RuntimeException('Generator must reject permissive seed files.');
if(!str_contains($generator,'TEST_TIME_REQUIRES_TEST_ONLY'))throw new RuntimeException('Test-time override must require an explicit test-only gate.');
if(!str_contains($script,'TOTP_RATE_LIMITED') || !str_contains($script,'now - last < 20'))throw new RuntimeException('Forced command must rate-limit code requests.');
if(!str_contains($script,'chmod 0400 "$SEED_FILE"'))throw new RuntimeException('Existing seed must be owner-readable only.');
echo "totp-provision-contract-test: OK\n";