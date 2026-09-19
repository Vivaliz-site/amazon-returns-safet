<?php
declare(strict_types=1);
$run=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/run-seller-central-daily.sh');
$prov=(string)file_get_contents(__DIR__.'/../scripts/provision-seller-central-browser-host.sh');
$helper=(string)file_get_contents(__DIR__.'/../scripts/ensure-playwright-chromium.sh');
function rhAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
rhAssert(!str_contains($run,'SELLER_CENTRAL_CHROMIUM_DIRECT_BINARY'),'Runtime must not bypass Snap by launching its private Chromium binary.');
rhAssert(!str_contains($run,'/snap/chromium/current/usr/lib/chromium-browser/chrome'),'Runtime must not depend on Snap private binary paths.');
rhAssert(str_contains($run,'SELLER_CENTRAL_BROWSER_LOG'),'Runtime must retain browser startup diagnostics.');
rhAssert(!str_contains($run,'about:blank >/dev/null 2>&1 &'),'Runtime must not discard browser startup stderr.');
rhAssert(str_contains($prov,'ensure-playwright-chromium.sh'),'Provisioner must delegate native-browser enforcement to the durable bootstrap helper.');
rhAssert(str_contains($helper,'supported non-Snap Chromium browser not installed after bootstrap'),'Browser bootstrap must still fail closed if installation cannot produce a supported native browser.');
echo "seller-central-browser-runtime-hardening-test: OK\n";
