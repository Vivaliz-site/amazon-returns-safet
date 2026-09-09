<?php
declare(strict_types=1);
function brrAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$root=dirname(__DIR__);
$prod=(string)file_get_contents($root.'/scripts/provision-production.sh');
brrAssert(str_contains($prod,'SELLER_CENTRAL_BROWSER'),'Production readiness must inspect the configured browser executable.');
brrAssert(str_contains($prod,'SELLER_CENTRAL_BRIDGE_TOKEN_FILE'),'Production readiness must accept the provisioned bridge token file.');
brrAssert(!str_contains($prod,'command -v chromium >/dev/null 2>&1 || browser_runtime_ready=0'),'Production readiness must not require chromium to be on PATH.');
brrAssert(str_contains($prod,'seller-central-browser.env'),'Production readiness must derive browser/TOTP paths from the VM browser environment.');
brrAssert(str_contains($prod,'browser_auth_ready=1'),'Production readiness must keep the authentication smoke gate.');
echo "browser-runtime-readiness-contract-test: OK\n";
