<?php
declare(strict_types=1);
function brbAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$helper=(string)file_get_contents(__DIR__.'/../scripts/ensure-playwright-chromium.sh');
$production=(string)file_get_contents(__DIR__.'/../scripts/provision-production.sh');
brbAssert(str_contains($helper,'PLAYWRIGHT_BROWSERS_PATH'),'Chromium bootstrap must install into an explicit persistent Playwright cache.');
brbAssert(str_contains($helper,'playwright@$PLAYWRIGHT_BROWSER_VERSION'),'Chromium bootstrap must use the pinned Playwright package.');
brbAssert(str_contains($helper,'runuser -u ubuntu'),'Browser downloads must be owned by the runtime user, not root.');
brbAssert(str_contains($helper,'discover_browser'),'Chromium bootstrap must verify discovery again after installation.');
brbAssert(str_contains($production,'provision-seller-central-browser-host.sh'),'Production deploy must restore the Seller Central browser runtime when protected enrollment already exists.');
brbAssert(str_contains($production,'seller_central_browser_runtime=reprovisioned'),'Production deploy must emit evidence when Seller Central browser runtime is repaired.');
echo "browser-runtime-bootstrap-test: OK\n";
