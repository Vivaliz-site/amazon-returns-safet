<?php
declare(strict_types=1);

$source=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-safe-t-read-worker.mjs');
$start=strpos($source,'async function safeTRead(job)');
$end=$start===false?false:strpos($source,'async function discoverActionableSupportCases()', $start);
if($start===false || $end===false){
    fwrite(STDERR,"safeTRead block not found\n");
    exit(1);
}
$block=substr($source,$start,$end-$start);
if(!str_contains($block,'await waitForSafeTClaimOrderIdentity(cdp, orderId)')){
    fwrite(STDERR,"SAFE_T_READ must wait for the claim order identity before parsing status\n");
    exit(1);
}
$wait=strpos($block,'await waitForSafeTClaimOrderIdentity(cdp, orderId)');
$parse=strpos($block,'parseSafeTStatus(');
if($wait===false || $parse===false || $wait>$parse){
    fwrite(STDERR,"SAFE_T_READ readiness wait must happen before parseSafeTStatus\n");
    exit(1);
}
echo "safe-t-read-order-readiness-test: OK\n";