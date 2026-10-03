<?php
declare(strict_types=1);
$service=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/BridgeService.php');
$accept=strpos($service,'public function acceptResult');
$success=strpos($service,'private function completeSuccess');
if($accept===false||$success===false)throw new RuntimeException('BridgeService contract missing');
$flow=substr($service,$accept,$success-$accept);
$guard=strpos($flow,"SELLER_SUPPORT_CASE_IDENTITY_MISMATCH");
$append=strpos($flow,"appendResultEvent",$guard);
if($guard===false)throw new RuntimeException('write bridge identity mismatch guard missing');
if($append===false||$guard>$append)throw new RuntimeException('identity mismatch must be handled before generic failure event');
if(!str_contains($flow,'completeSupportIdentityMismatch($row,$result)'))throw new RuntimeException('transactional mismatch recovery not routed');
if(!str_contains($service,"'event_type'=>'SELLER_SUPPORT_IDENTITY_MISMATCH'"))throw new RuntimeException('audit event missing');
if(!str_contains($service,"support_case_id'=>null"))throw new RuntimeException("stale binding clear missing");
if(!str_contains($service,'hash_equals($known,$mismatchedId)'))throw new RuntimeException('binding race guard missing');
echo "seller-support-write-bridge-identity-mismatch-test: OK\n";
