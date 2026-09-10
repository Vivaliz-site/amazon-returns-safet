<?php
declare(strict_types=1);
$w=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
foreach(['supportCaseReadbackSnapshot','SUPPORT_WRITE_WITHOUT_READBACK_ID','support_readback'] as $n){if(!str_contains($w,$n)){fwrite(STDERR,"missing readback diagnostic: $n\n");exit(1);}}
echo "seller-support-readback-diagnostics-test: OK\n";
