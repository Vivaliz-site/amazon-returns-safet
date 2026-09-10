<?php
declare(strict_types=1);
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$start=strpos($worker,'async function findSupportCase');
$end=strpos($worker,'async function clickFrameIncludes',$start);
$body=substr($worker,$start,$end-$start);
foreach(['const docs=[document]','f.contentDocument','for(const d of docs)','a[href*="view-case"]'] as $needle){if(!str_contains($body,$needle)){fwrite(STDERR,"support case readback must inspect framed case lobby: $needle\n");exit(1);}}
echo "seller-support-case-readback-frames-test: OK\n";
