<?php
declare(strict_types=1);
$src=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$errors=[];
if(!str_contains($src,'kat-input[placeholder="Inserir ASIN"]'))$errors[]='Support worker must detect the current required ASIN field.';
if(!str_contains($src,"job.case?.asin"))$errors[]='Support worker must source ASIN from the scoped case payload.';
if(!str_contains($src,'SUPPORT_ASIN_INPUT_MISSING'))$errors[]='Support worker must fail audibly when required ASIN cannot be filled.';
if(!str_contains($src,"if (job.action === 'SELLER_SUPPORT_OPEN' && safeT)"))$errors[]='Support narrative must support cases without an existing SAFE-T ID.';
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "seller-support-dba-form-contract-test: OK\n";