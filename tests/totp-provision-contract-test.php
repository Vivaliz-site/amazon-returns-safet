<?php
declare(strict_types=1);

function tpAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$scriptPath=__DIR__.'/../scripts/provision-amazon-totp-authenticator.sh';
$totpPath=__DIR__.'/../scripts/amazon-returns/totp-current.py';
tpAssert(is_file($scriptPath),'Authenticator provisioner must exist.');
tpAssert(is_file($totpPath),'TOTP generator must exist.');
$script=(string)file_get_contents($scriptPath);
foreach([
    'no-agent-forwarding',
    'no-port-forwarding',
    'no-pty',
    'no-X11-forwarding',
] as $needle){
    tpAssert(str_contains($script,$needle),'Forced-key restriction missing: '.$needle);
}
$forcedCommandPlain='command="/usr/local/lib/shopvivaliz/amazon-totp/current"';
$forcedCommandEscaped='command=\\"/usr/local/lib/shopvivaliz/amazon-totp/current\\"';
tpAssert(str_contains($script,$forcedCommandPlain)||str_contains($script,$forcedCommandEscaped),'Forced command must be pinned to the TOTP wrapper.');

tpAssert(str_contains($script,'flock'),'Authenticator command must serialize/rate-limit requests.');
tpAssert(str_contains($script,'SEED_NOT_CONFIGURED'),'Missing production seed must fail closed.');
tpAssert(str_contains($script,'/var/lib/shopvivaliz/amazon-totp/seed.base32'),'Seed must use the dedicated private path.');
tpAssert(!str_contains($script,'StrictHostKeyChecking=no'),'Provisioner must never weaken SSH host verification.');
tpAssert(!preg_match('/otpauth:\/\//i',$script),'Provisioner must not embed an enrollment URI.');
tpAssert(!preg_match('/GEZDGNBVGY3TQOJQ/i',$script),'Provisioner must not embed even the test seed.');

$generator=(string)file_get_contents($totpPath);
tpAssert(!str_contains($generator,'print(seed)'),'Generator must never print its seed.');
tpAssert(!str_contains($generator,'logging.basicConfig'),'Generator must not configure verbose logging.');
echo "totp-provision-contract-test: OK\n";
