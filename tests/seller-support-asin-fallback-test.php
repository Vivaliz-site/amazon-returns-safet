<?php
declare(strict_types=1);
$src=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$errors=[];
if(!str_contains($src,'resolveOrderAsin'))$errors[]='Support worker must resolve ASIN from Seller Central order details.';
if(!str_contains($src,"/ASIN:\\s*([A-Z0-9]{10})/"))$errors[]='ASIN fallback must parse the order detail text.';
if(!str_contains($src,'job.case?.asin || await resolveOrderAsin'))$errors[]='Support worker must prefer persisted ASIN and fall back to the order page.';
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "seller-support-asin-fallback-test: OK\n";