<?php
declare(strict_types=1);
function bsAssert(bool $ok,string $msg): void { if(!$ok)throw new RuntimeException($msg); }
$unitPath=__DIR__.'/../deploy/systemd/amazon-returns-olist-erp-browser.service';
$scriptPath=__DIR__.'/../scripts/amazon-returns/run-olist-erp-browser.sh';
$hostPath=__DIR__.'/../scripts/amazon-returns/olist-erp-browser-host.cjs';
$provisionPath=__DIR__.'/../scripts/provision-olist-erp-browser-host.sh';
bsAssert(is_file($unitPath),'Olist ERP browser systemd unit must exist.');
bsAssert(is_file($scriptPath),'Olist ERP browser runner must exist.');
bsAssert(is_file($hostPath),'Olist ERP Playwright host must exist.');
bsAssert(is_file($provisionPath),'Olist ERP browser provisioner must exist.');
$unit=(string)file_get_contents($unitPath);$script=(string)file_get_contents($scriptPath);$host=(string)file_get_contents($hostPath);$provision=(string)file_get_contents($provisionPath);
bsAssert(str_contains($unit,'User=ubuntu'),'Olist browser must run as the non-root ubuntu user.');
bsAssert(str_contains($unit,'Restart=always'),'Olist browser must persist independently of chat/PC sessions.');
bsAssert(str_contains($unit,'NoNewPrivileges=true'),'Olist browser service must use no-new-privileges hardening.');
bsAssert(str_contains($host,'launchPersistentContext'),'Olist host must launch Chromium through Playwright for stable navigation.');
bsAssert(str_contains($host,'--remote-debugging-address=127.0.0.1'),'CDP must listen only on loopback.');
bsAssert(str_contains($host,'--remote-debugging-port='),'Olist browser must expose its dedicated CDP port.');
bsAssert(str_contains($host,'https://erp.olist.com/devolucoes_vendas#list'),'Olist host must open only the sales-return module.');
bsAssert(str_contains($script,'olist-erp-browser-host.cjs'),'Runner must execute the Playwright host.');
bsAssert(str_contains($provision,'playwright-core'),'Provisioner must persist the Playwright runtime outside the npx cache.');
bsAssert(str_contains($provision,'BROWSER_ROOT="$SHARED/olist-erp-browser"') && str_contains($provision,'$BROWSER_ROOT/profile'),'Provisioner must create a dedicated persistent profile.');
bsAssert(!str_contains($host,'seller-central-browser/profile'),'Olist browser must not share Seller Central profile state.');
echo "erp-sales-return-browser-systemd-test: OK\n";
