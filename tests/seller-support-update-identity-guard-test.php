<?php
declare(strict_types=1);
$w=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$a=strpos($w,'async function supportUpdate');
$b=$a===false?false:strpos($w,'async function ',$a+20);
if($a===false) throw new RuntimeException('supportUpdate missing');
$fn=$b===false?substr($w,$a):substr($w,$a,$b-$a);
$guard=strpos($fn,'supportCaseMatchesJob(cdp, job, caseId)');
$reply=strpos($fn,'submitSupportEmailReplyApi(cdp, caseId, narrative)');
if($guard===false) throw new RuntimeException('identity guard missing');
if($reply===false || $guard >= $reply) throw new RuntimeException('identity guard must precede reply write');
if(!str_contains($fn,"SELLER_SUPPORT_CASE_IDENTITY_MISMATCH")) throw new RuntimeException('mismatch contract missing');
if(!str_contains($fn,"bridgeResult('NOT_FOUND'")) throw new RuntimeException('mismatch must fail closed');
if(!str_contains($fn,'external_id: caseId')) throw new RuntimeException('contaminated id must be reported');
$service=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/StatusBridgeService.php');
if(!str_contains($service,"SELLER_SUPPORT_UPDATE") || !str_contains($service,"completeSupportIdentityMismatch")) throw new RuntimeException("update mismatch recovery contract missing");
echo "seller-support-update-identity-guard-test: OK\n";
