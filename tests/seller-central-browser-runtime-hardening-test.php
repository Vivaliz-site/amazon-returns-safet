<?php
declare(strict_types=1);
$run=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/run-seller-central-daily.sh');
$prov=(string)file_get_contents(__DIR__.'/../scripts/provision-seller-central-browser-host.sh');
function rhAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
rhAssert(!str_contains($run,'SELLER_CENTRAL_CHROMIUM_DIRECT_BINARY'),'Runtime must not bypass Snap by launching its private Chromium binary.');
rhAssert(!str_contains($run,'/snap/chromium/current/usr/lib/chromium-browser/chrome'),'Runtime must not depend on Snap private binary paths.');
rhAssert(str_contains($run,'SELLER_CENTRAL_BROWSER_LOG'),'Runtime must retain browser startup diagnostics.');
rhAssert(!str_contains($run,'about:blank >/dev/null 2>&1 &'),'Runtime must not discard browser startup stderr.');
rhAssert(str_contains($prov,'supported non-Snap Chromium browser not installed'),'Provisioner must fail closed when no supported native browser exists.');
echo "seller-central-browser-runtime-hardening-test: OK\n";
