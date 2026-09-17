<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/RemoteBridge.php';
function sleEq(mixed $e,mixed $a,string $m):void{if($e!==$a)throw new RuntimeException($m.' expected='.var_export($e,true).' actual='.var_export($a,true));}
$row=[
  'id'=>326049,'tenant_id'=>1,'amazon_connection_id'=>1,'case_id'=>13236,
  'kind'=>'SELLER_SUPPORT_OPEN','idempotency_key'=>str_repeat('a',64),'attempt_count'=>2,
  'created_at'=>'2026-09-09 15:44:06','payload'=>[],
];
$case=[
  'amazon_order_id'=>'701-2474306-0966605','amazon_order_item_id'=>'item-1',
  'program'=>'FBA','physical_status'=>'NOT_RECEIVED','refund_at'=>'2026-07-28 20:20:24',
  'safe_t_id'=>null,'support_case_id'=>null,'quantity_refunded'=>1,'quantity_received'=>0,
];
$job=SvAmazonReturnsRemoteBridge::jobEnvelope($row,$case,['SELLER_SUPPORT_OPEN'=>true]);
sleEq('2026-09-09 15:44:06',$job['created_at']??null,'Bridge job must expose outbox creation time for retry reconciliation.');
sleEq('2026-07-28 20:20:24',$job['case']['refund_at']??null,'Bridge job must expose refund time for bounded pre-write dedupe.');
echo "seller-support-lookup-envelope-test: OK\n";
