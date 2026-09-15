<?php
declare(strict_types=1);

$script=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/run-seller-central-daily.sh');
if($script==='')throw new RuntimeException('Seller Central daily script missing.');

$required=[
    'SELLER_CENTRAL_CHROMIUM_DIRECT_BINARY',
    '/snap/chromium/current/usr/lib/chromium-browser/chrome',
    'BROWSER_BIN=',
];
foreach($required as $marker){
    if(!str_contains($script,$marker)){
        throw new RuntimeException('Daily Seller Central cycle must bypass a broken snap Chromium wrapper when the direct binary is available: '.$marker);
    }
}
if(str_contains($script,'setsid "$SELLER_CENTRAL_BROWSER"')){
    throw new RuntimeException('Browser launch must use the resolved browser binary, not the raw configured snap wrapper.');
}

echo "seller-central-browser-direct-binary-fallback-test: OK\n";
