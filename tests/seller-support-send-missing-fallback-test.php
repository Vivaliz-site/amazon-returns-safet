<?php
declare(strict_types=1);
$src=file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
if(!is_string($src)) throw new RuntimeException('worker missing');
$needle="if (!text(sent))";
$pos=strpos($src,$needle);
if($pos===false) throw new RuntimeException('send-missing branch missing');
$window=substr($src,$pos,1800);
foreach(["supportOpen(cdp, job, { forceFreshCase: true, supportRoute: 'GENERAL_ORDER_SUPPORT' })","supportRouteFor(job) === 'FBA_RETURNS_REIMBURSEMENT'"] as $expected){
 if(strpos($window,$expected)===false) throw new RuntimeException('missing fallback contract: '.$expected);
}
echo "seller-support-send-missing-fallback-test: OK\n";
