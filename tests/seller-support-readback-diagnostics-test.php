<?php
declare(strict_types=1);
$w=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
foreach(['supportCaseReadbackSnapshot','SUPPORT_WRITE_WITHOUT_READBACK_ID','support_readback'] as $n){if(!str_contains($w,$n)){fwrite(STDERR,"missing readback diagnostic: $n\n");exit(1);}}
if(!str_contains($w,'findSupportCase(cdp, job, { includeTerminal: true })')){fwrite(STDERR,"post-write readback must include resolved support cases\n");exit(1);}
if(!str_contains($w,'includeTerminal ? true : activeSupportStatus')){fwrite(STDERR,"history scan must allow terminal cases only for post-write readback\n");exit(1);}
echo "seller-support-readback-diagnostics-test: OK\n";
