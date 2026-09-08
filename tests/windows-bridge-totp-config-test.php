<?php
declare(strict_types=1);
$installer=(string)file_get_contents(__DIR__.'/../scripts/install-amazon-returns-windows-bridge.ps1');
function wtcAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
foreach([
    'SELLER_CENTRAL_USERNAME_FILE',
    'SELLER_CENTRAL_PASSWORD_FILE',
    'SELLER_CENTRAL_TOTP_HOST',
    'SELLER_CENTRAL_TOTP_KEY_FILE',
    'SELLER_CENTRAL_TOTP_KNOWN_HOSTS_FILE',
    'SELLER_CENTRAL_TOTP_SSH_BINARY',
] as $name){wtcAssert(str_contains($installer,$name),'Windows installer must export '.$name.'.');}
wtcAssert(str_contains($installer,'Get-Command ssh.exe'),'Windows installer must resolve OpenSSH instead of using a Unix path.');
wtcAssert(!str_contains($installer,"'/usr/bin/ssh'"),'Windows installer must never configure the Unix SSH binary.');
echo "windows-bridge-totp-config-test: OK\n";
