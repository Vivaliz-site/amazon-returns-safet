<?php
$w=file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-safe-t-read-worker.mjs');
if(!str_contains($w,'waitForSafeTClaimOrderIdentity')){fwrite(STDERR,"missing discovery readiness wait\n");exit(1);}
echo "safe-t-discovery-order-readiness-test: OK\n";
