<?php
declare(strict_types=1);
$script=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/run-seller-central-daily.sh');
if($script==='')throw new RuntimeException('Seller Central daily script missing.');
if(str_contains($script,'SELLER_CENTRAL_CHROMIUM_DIRECT_BINARY') || str_contains($script,'/snap/chromium/current/usr/lib/chromium-browser/chrome')){
    throw new RuntimeException('Daily Seller Central cycle must not bypass Snap wrappers through private Snap binary paths.');
}
if(!str_contains($script,'supported non-Snap Chromium binary')){
    throw new RuntimeException('Daily Seller Central cycle must reject unsupported Snap browser binaries.');
}
echo "seller-central-browser-direct-binary-fallback-test: OK\n";
