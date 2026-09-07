<?php
declare(strict_types=1);
$workerPath=__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs';
$helperPath=__DIR__.'/../scripts/amazon-returns/TrackingEvidence.mjs';
$worker=file_get_contents($workerPath);
$helper=file_get_contents($helperPath);
if(!is_string($worker)||!is_string($helper)){fwrite(STDERR,"bridge tracking evidence files missing\n");exit(1);}
$fail=[];
foreach(['Page.captureScreenshot','DOM.setFileInputFiles','order-details-tracking-link-button','tracking_evidence_attached'] as $needle){
    if(strpos($helper,$needle)===false)$fail[]='Tracking helper must implement '.$needle;
}
foreach(['TrackingEvidence.mjs','captureTrackingEvidence','attachFiles','withTrackingEvidence'] as $needle){
    if(strpos($worker,$needle)===false)$fail[]='Bridge worker must integrate '.$needle;
}
if(strpos($worker,'FILE_UPLOAD_BRIDGE_NOT_PROVISIONED')!==false)$fail[]='Bridge must no longer reject valid evidence paths.';
if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}echo "safet-tracking-evidence-upload-test: OK\n";
