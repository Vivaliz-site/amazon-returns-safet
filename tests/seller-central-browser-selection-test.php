<?php
declare(strict_types=1);
function scbAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$prov=(string)file_get_contents(dirname(__DIR__).'/scripts/provision-seller-central-browser-host.sh');
$helper=(string)file_get_contents(dirname(__DIR__).'/scripts/ensure-playwright-chromium.sh');
scbAssert(str_contains($prov,'ensure-playwright-chromium.sh'),'Seller Central provisioner must use the durable Chromium bootstrap helper.');
scbAssert(str_contains($helper,'ms-playwright-arm64'),'Browser helper must use the persistent ARM64 Playwright cache.');
scbAssert(str_contains($helper,'chromium-*/chrome-linux*/chrome'),'Browser helper must discover Playwright Chromium across ARM64 layout variants.');
scbAssert(str_contains($helper,'readlink -f'),'Browser helper must inspect wrappers before accepting Chromium.');
scbAssert(str_contains($helper,'/usr/bin/snap'),'Browser helper must reject the snap-confine Chromium wrapper.');
scbAssert(str_contains($helper,'playwright@$PLAYWRIGHT_BROWSER_VERSION'),'Browser helper must pin and bootstrap a known Playwright Chromium when cache is absent.');
echo "seller-central-browser-selection-test: OK\n";
