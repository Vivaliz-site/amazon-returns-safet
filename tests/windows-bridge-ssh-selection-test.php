<?php
declare(strict_types=1);
$installer=(string)file_get_contents(__DIR__.'/../scripts/install-amazon-returns-windows-bridge.ps1');
function wssAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
wssAssert(str_contains($installer,'[string]$SshPath ='), 'Installer must accept an explicit SSH binary path.');
wssAssert(str_contains($installer,"'Git\\usr\\bin\\ssh.exe'"), 'Installer must support Git for Windows SSH fallback.');
wssAssert(str_contains($installer,'if (-not (Test-Path $SshPath))'), 'Installer must reject a missing explicit SSH binary.');
wssAssert(str_contains($installer,'SELLER_CENTRAL_TOTP_SSH_BINARY'), 'Selected SSH path must reach the runtime environment.');
echo "windows-bridge-ssh-selection-test: OK\n";
