<?php
declare(strict_types=1);
$src=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$errors=[];
if(substr_count($src,"clickFrameButtonTrustedByText('Contact an associate')")<2)$errors[]='Both Seller Support contact transitions must prefer a trusted CDP click.';
if(!str_contains($src,"SUPPORT_CONTACT_ASSOCIATE_CLICK_FAILED"))$errors[]='A failed trusted/untrusted transition must remain observable and retry-safe.';
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}
echo "seller-support-trusted-contact-test: OK\n";
