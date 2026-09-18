<?php
declare(strict_types=1);
function popupAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
popupAssert(str_contains($worker,'hillPopupSupportState'),'Worker must inspect separate Hill support popup targets.');
popupAssert(str_contains($worker,"'/hill/website/chat'"),'Worker must identify Hill chat popup URL.');
popupAssert(str_contains($worker,'Support currently unavailable'),'Worker must recognize observed unavailable-agent text.');
$start=strpos($worker,'async function hillPopupSupportState');
$end=$start===false?false:strpos($worker,'async function supportOpen',$start);
popupAssert($start!==false && $end!==false,'Popup support-state helper must remain auditable.');
$section=substr($worker,(int)$start,(int)$end-(int)$start);
popupAssert(str_contains($section,"return phrases.some(phrase => body.includes(phrase)) ? 'UNAVAILABLE' : 'AVAILABLE';"),'Popup body must classify the observed unavailable-agent text.');
popupAssert(str_contains($section,"return await hillPopupSupportState() === 'UNAVAILABLE';"),'Embedded Hill check must include separate popup unavailability.');
$resultStart=strpos($worker,'function sellerSupportUnavailableResult');
$resultEnd=$resultStart===false?false:strpos($worker,'async function hillPopupSupportState',$resultStart);
popupAssert($resultStart!==false && $resultEnd!==false,'Unavailable result helper must remain auditable.');
$resultSection=substr($worker,(int)$resultStart,(int)$resultEnd-(int)$resultStart);
popupAssert(str_contains($resultSection,"return bridgeResult('BLOCKED_UNTIL'"),'Unavailable popup must become retryable BLOCKED_UNTIL, not UI drift.');
popupAssert(str_contains($resultSection,'SELLER_SUPPORT_CURRENTLY_UNAVAILABLE'),'Unavailable popup must use the existing retry reason.');
echo "seller-support-popup-unavailable-test: OK\n";
