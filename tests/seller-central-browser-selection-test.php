<?php
declare(strict_types=1);
function scbAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$prov=(string)file_get_contents(dirname(__DIR__).'/scripts/provision-seller-central-browser-host.sh');
$pw=strpos($prov,'ms-playwright-arm64/chromium-*');
$snap=strpos($prov,'/usr/bin/chromium');
scbAssert($pw!==false,'Provisioner must discover a native Playwright ARM64 Chromium.');
scbAssert($snap===false || $pw<$snap,'Native Playwright Chromium must be preferred before Ubuntu Chromium/Snap wrappers.');
scbAssert(str_contains($prov,'readlink -f'),'Provisioner must inspect browser wrappers before accepting Chromium.');
scbAssert(str_contains($prov,'/usr/bin/snap'),'Provisioner must reject the snap-confine Chromium wrapper under NoNewPrivileges.');
echo "seller-central-browser-selection-test: OK\n";
