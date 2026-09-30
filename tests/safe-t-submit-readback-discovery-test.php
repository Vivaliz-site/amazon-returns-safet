<?php
declare(strict_types=1);

function stsrdAssert(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException($message);
}
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$start=strpos($worker,'async function safeTSubmit');
$end=$start===false?false:strpos($worker,'async function safeTAppeal',$start);
stsrdAssert($start!==false && $end!==false,'SAFE-T submit flow must remain auditable.');
$submit=substr($worker,(int)$start,(int)$end-(int)$start);
stsrdAssert(str_contains($submit,'SAFE_T_WRITE_WITHOUT_READBACK_ID'),'Unknown post-submit state must still fail closed when discovery also fails.');
stsrdAssert(str_contains($submit,'SAFE_T_SUBMITTED_AND_DISCOVERED_BY_ORDER'),'Post-submit readback must recover a uniquely discoverable claim by order before declaring failure.');
stsrdAssert(str_contains($submit,'claim-content-wrapper-'),'Post-submit discovery must use the same Seller Central claim-list identity surface as the read-only discovery worker.');
stsrdAssert(str_contains($submit,'MULTIPLE_SAFE_T_CLAIMS_AFTER_SUBMIT'),'Multiple post-submit claims must fail closed rather than bind an arbitrary claim.');
echo "safe-t-submit-readback-discovery-test: OK\n";
