<?php
declare(strict_types=1);

function ssdpAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$bridge=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/BridgeService.php');
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$status=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/SellerSupportStatus.php');

ssdpAssert(str_contains($worker,"SUPPORT_UPDATE_READBACK_CONFIRMED"),'Exact Seller Support reply readback must have an explicit reason code.');
ssdpAssert(str_contains($worker,"SUPPORT_CASE_ALREADY_EXISTS"),'Existing-case discovery must be distinct from reply readback.');
ssdpAssert(str_contains($bridge,"SUPPORT_UPDATE_READBACK_CONFIRMED"),'Bridge must distinguish reply readback from case discovery.');
ssdpAssert(str_contains($bridge,"completeSupportExistingCaseDiscovery"),'A discovered existing case must reconcile scope instead of marking update delivery.');
ssdpAssert(str_contains($status,"SUPPORT_UPDATE_READBACK_CONFIRMED"),'Read cadence must only treat explicit update readback as ALREADY_EXISTS delivery proof.');
ssdpAssert(str_contains($worker,"confirmSupportUpdateFallback"),'Seller Support update fallbacks that open or reuse another case must perform destination readback.');
ssdpAssert(str_contains($worker,"SUPPORT_UPDATE_FALLBACK_READBACK_CONFIRMED"),'A fallback update may only be accepted after its narrative is read back.');
ssdpAssert(str_contains($worker,"reason: 'SUPPORT_CASE_ALREADY_EXISTS'"),'A fallback that discovers a case without the narrative must reconcile scope instead of claiming delivery.');
ssdpAssert(str_contains($bridge,"SUPPORT_UPDATE_FALLBACK_READBACK_CONFIRMED"),'Bridge completion must recognize only explicitly read-back fallback updates.');
ssdpAssert(str_contains($bridge,'$supportUpdateAcceptedReasons'),'Bridge must keep an explicit allowlist of accepted Seller Support update proof reasons.');
ssdpAssert(!str_contains($bridge,"|| \$status==='ACCEPTED'"),'Bridge must not treat every ACCEPTED Seller Support update as delivery proof.');

echo "seller-support-delivery-proof-test: OK\n";
