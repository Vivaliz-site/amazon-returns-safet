<?php
declare(strict_types=1);
$installer=file_get_contents(__DIR__.'/../scripts/install-amazon-returns-windows-bridge.ps1');
if(!is_string($installer)){fwrite(STDERR,"installer missing\n");exit(1);}
$fail=[];
foreach(['TrackingEvidence.mjs','Copy-Item -Force $TrackingEvidenceSource $trackingEvidence'] as $needle){
    if(strpos($installer,$needle)===false)$fail[]='Windows bridge installer must provision '.$needle;
}
if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}echo "windows-bridge-tracking-helper-install-test: OK\n";
