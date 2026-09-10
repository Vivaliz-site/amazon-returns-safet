<?php
declare(strict_types=1);
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$needles=['supportOrderInputReady','SUPPORT_GENERAL_CATEGORY_ORDER_INPUT_MISSING','placeholder*="112-"','supportOrderInputReady(cdp)','hillChatReady(cdp)'];
foreach($needles as $needle){if(!str_contains($worker,$needle)){fwrite(STDERR,"missing observed category-order transition: $needle\n");exit(1);}}
echo "seller-support-order-category-route-test: OK\n";
