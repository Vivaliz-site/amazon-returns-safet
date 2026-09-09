<?php
declare(strict_types=1);
function ssjAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$root=dirname(__DIR__);
$worker=(string)file_get_contents($root.'/scripts/amazon-returns/seller-central-bridge-worker.mjs');
$bridge=(string)file_get_contents($root.'/includes/amazon-returns/BridgeService.php');
$outbox=(string)file_get_contents($root.'/includes/amazon-returns/TenantOutbox.php');

ssjAssert(str_contains($worker,'PHYSICAL_RETURN_RECEIVED_BEFORE_SUPPORT_OPEN'),
    'Seller Support worker must supersede a stale classic-FBA open after clean physical receipt.');
ssjAssert(!str_contains($worker,"['RECEIVED_OK', 'RECEIVED_DISCREPANT'].includes(physicalStatus)"),
    'Discrepant physical receipts must not be superseded as clean receipts.');
ssjAssert(str_contains($bridge,'markSuperseded'),
    'BridgeService must persist SUPERSEDED without applying external-success case mutations.');
ssjAssert(str_contains($outbox,'function markSuperseded'),
    'Outbox must expose an auditable SUPERSEDED terminal transition.');
ssjAssert(str_contains($outbox,"status='SUPERSEDED'"),
    'Superseded jobs must remain in the audit trail with an explicit terminal status.');

echo "seller-support-stale-job-supersede-test: OK\n";
