<?php
declare(strict_types=1);

function ssadAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$endpoint=(string)file_get_contents(__DIR__.'/../api/amazon-returns/status-bridge.php');
$service=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/StatusBridgeService.php');
$probe=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-support-lookup-probe.mjs');
$payload=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/ExternalWritePayload.php');

ssadAssert(str_contains($endpoint,"'discover_support_actions'"),'Status bridge must accept actionable Seller Support discovery.');
ssadAssert(str_contains($service,'function discoverSupportActions'),'Status bridge service must ingest actionable Seller Support siblings.');
ssadAssert(str_contains($service,"'PENDINGMERCHANTACTION'"),'Discovery must recognize merchant-action status.');
ssadAssert(str_contains($service,'findSingleByOrder'),'Discovery must correlate a support case to exactly one scoped Amazon order case.');
ssadAssert(str_contains($probe,"statusBridge('discover_support_actions'"),'Case-lobby probe must submit discovered actionable cases to the status bridge.');
ssadAssert(str_contains($probe,"'PENDINGMERCHANTACTION'"),'Case-lobby probe must scan for merchant-action cases.');
ssadAssert(str_contains($payload,"physical_status"),'Seller response payload must use trusted physical return state.');
ssadAssert(str_contains($payload,'não retornaram ao nosso estoque'),'Missing-return clarification must state that the units did not return when evidence says NOT_RECEIVED.');

echo "seller-support-action-discovery-contract-test: OK\n";
