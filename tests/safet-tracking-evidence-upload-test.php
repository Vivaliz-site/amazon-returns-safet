<?php
declare(strict_types=1);
$path=__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs';
$code=file_get_contents($path);
if(!is_string($code)){fwrite(STDERR,"bridge worker missing\n");exit(1);}
$fail=[];
foreach(['Page.captureScreenshot','DOM.setFileInputFiles','order-details-tracking-link-button','tracking_evidence_attached'] as $needle){
    if(strpos($code,$needle)===false)$fail[]='Bridge must implement '.$needle;
}
if(strpos($code,'FILE_UPLOAD_BRIDGE_NOT_PROVISIONED')!==false)$fail[]='Bridge must no longer reject valid evidence paths.';
if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}echo "safet-tracking-evidence-upload-test: OK\n";
