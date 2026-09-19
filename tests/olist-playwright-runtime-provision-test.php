<?php
declare(strict_types=1);
function oprAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$script=(string)file_get_contents(__DIR__.'/../scripts/provision-olist-erp-browser-host.sh');
oprAssert(str_contains($script,"PLAYWRIGHT_CORE_VERSION='1.63.0'"),'Olist provisioner must pin the Playwright core version that matches browser revision 1243.');
oprAssert(str_contains($script,'PERSISTED_PLAYWRIGHT="$BROWSER_ROOT/runtime/node_modules/playwright-core"'),'Olist provisioner must recognize its persistent Playwright runtime as the canonical target.');
oprAssert(str_contains($script,' install --prefix "$BROWSER_ROOT/runtime"') && str_contains($script,'"playwright-core@$PLAYWRIGHT_CORE_VERSION"'),'Olist provisioner must bootstrap the persistent Playwright runtime when ephemeral npx/global copies are absent.');
oprAssert(!str_contains($script,'[[ -n "$playwright" ]] || { echo "playwright-core runtime not installed on host" >&2; exit 69; }'),'Missing ephemeral npx/global Playwright must not make production deploy permanently fail.');
echo "olist-playwright-runtime-provision-test: OK\n";
