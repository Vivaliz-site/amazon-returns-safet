<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/RemoteBridge.php';
require_once __DIR__.'/../includes/amazon-returns/Projector.php';

function ssoSame(mixed $expected,mixed $actual,string $why):void{
    if($expected!==$actual)throw new RuntimeException($why.': '.var_export($actual,true));
}
function ssoThrows(callable $fn,string $why):void{
    try{$fn();}catch(RuntimeException){return;}
    throw new RuntimeException($why);
}
$shipping=[
    'order_id'=>'702-4837920-7734605',
    'order_item_id'=>'1234567890',
    'customer_delivery_status'=>'DELIVERED',
    'customer_delivery_confirmed'=>true,
    'customer_tracking_ids'=>['BR123456789XY'],
    'customer_delivery_carriers'=>['Correios'],
    'identity_verified'=>true,
    'status_evidence'=>true,
];
$observed=SvAmazonReturnsRemoteBridge::validateResult([
    'status'=>'ACCEPTED','submitted'=>false,
    'shipment'=>$shipping,
    'evidence'=>['snapshot_sha256'=>str_repeat('b',64)],
]);
ssoSame('DELIVERED',$observed['shipment']['customer_delivery_status'],'Status must be normalized');
ssoSame(['BR123456789XY'],$observed['shipment']['customer_tracking_ids'],'Official tracking must be retained');
ssoThrows(static function()use($shipping):void{
    SvAmazonReturnsRemoteBridge::normalizeShipment(array_replace($shipping,['customer_delivery_confirmed'=>false]));
},'DELIVERED requires confirmed evidence');
ssoThrows(static function()use($shipping):void{
    SvAmazonReturnsRemoteBridge::normalizeShipment(array_replace($shipping,['status_evidence'=>false]));
},'Delivered cannot be inferred from generic order page text');
ssoThrows(static function()use($shipping):void{
    SvAmazonReturnsRemoteBridge::normalizeShipment(array_replace($shipping,['identity_verified'=>false]));
},'Unverified order/item identity must be refused');
ssoThrows(static function()use($shipping):void{
    SvAmazonReturnsRemoteBridge::normalizeShipment(array_replace($shipping,['order_id'=>'702-0000000-0000000','order_item_id'=>'']));
},'Item attribution must be present');

$job=SvAmazonReturnsRemoteBridge::jobEnvelope([
    'id'=>101,'tenant_id'=>1,'amazon_connection_id'=>1,'case_id'=>77,
    'kind'=>'ORDER_DELIVERY_READ','idempotency_key'=>str_repeat('a',64),
    'payload'=>['read_only'=>true],
],[
    'amazon_order_id'=>$shipping['order_id'],'amazon_order_item_id'=>$shipping['order_item_id'],
],[]);
ssoSame('ORDER_DELIVERY_READ',$job['action'],'Outbox must carry the new read kind');
ssoSame(false,$job['write_enabled'],'Shipment read must not permit external write');
ssoSame(true,$job['payload']['read_only'],'Shipping read must be read only');

$case=[
    'id'=>77,'amazon_order_id'=>$shipping['order_id'],'amazon_order_item_id'=>$shipping['order_item_id'],
    'quantity_ordered'=>1,'marketplace_id'=>'A2Q3Y263D00KWC',
    'program'=>'FBA_ONSITE','state'=>'AWAITING_RETURN',
    'physical_status'=>'NOT_RECEIVED','expected_reimbursement_amount'=>'0.00',
    'reconciled_credit_amount'=>'0.00',
];
$event=[
    'id'=>101,'case_id'=>77,'event_type'=>'CUSTOMER_SHIPMENT_STATUS_OBSERVED',
    'source'=>'SELLER_CENTRAL','occurred_at'=>'2026-11-09 21:55:00',
    'payload'=>array_replace($shipping,['order_item_id'=>'1234567890']),
];
$projected=SvAmazonReturnProjector::projectFrom($case,[$event]);
ssoSame(true,$projected['customer_delivery_confirmed'],'Official seller read may prove buyer delivery');
ssoSame('DELIVERED',$projected['customer_delivery_status'],'Buyer delivery status must project');
ssoSame(['BR123456789XY'],$projected['customer_tracking_ids'],'Official tracking must project');
ssoSame('NOT_RECEIVED',$projected['physical_status'],'Buyer delivery is not seller physical return');
ssoSame(0,$projected['quantity_received'],'Buyer delivery cannot create warehouse receipt');
$unknown=SvAmazonReturnProjector::projectFrom($case,[array_replace($event,[
    'payload'=>array_replace($shipping,['customer_delivery_status'=>'UNKNOWN','customer_delivery_confirmed'=>false]),
])]);
ssoSame(false,$unknown['customer_delivery_confirmed'],'Tracking without delivery event is not confirmed');
ssoSame(['BR123456789XY'],$unknown['customer_tracking_ids'],'Tracking remains useful even when status unknown');

echo "seller-central-shipment-result-test: OK\n";
