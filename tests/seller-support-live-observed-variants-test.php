<?php
declare(strict_types=1);
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$needles=['supportFrameSnapshot','SUPPORT_GENERAL_TROUBLESHOOTER_EXHAUSTED','SUPPORT_GENERAL_PRODUCT_FIELDS_MISSING','placeholder*=','ASIN','SKU'];
foreach($needles as $needle){if(!str_contains($worker,$needle)){fwrite(STDERR,"missing live support diagnostic/variant contract: $needle\n");exit(1);}}
if(!str_contains($worker,"'Get help'")){fwrite(STDERR,"troubleshooter must recognize the observed Get help transition\n");exit(1);}
echo "seller-support-live-observed-variants-test: OK\n";
