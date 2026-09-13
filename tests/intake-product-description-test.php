<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Projector.php';
function ipdAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$case=['id'=>91,'amazon_order_id'=>'702-7477329-8417814','amazon_order_item_id'=>'item-1','sku'=>'Sabatinidba','asin'=>'B0TEST123','quantity_ordered'=>1,'physical_status'=>'NOT_RECEIVED','state'=>'AWAITING_RETURN'];
$events=[['id'=>1,'case_id'=>91,'event_type'=>'ORDER_SYNCED','source'=>'SP_API_ORDERS','occurred_at'=>'2026-09-01 12:00:00','payload'=>['quantity_ordered'=>1,'product_title'=>'Sabatina Madeira Completa para Porta 80 cm']]];
$projected=SvAmazonReturnProjector::projectFrom($case,$events);
ipdAssert(($projected['product_title']??null)==='Sabatina Madeira Completa para Porta 80 cm','Projected intake data must preserve the Amazon product title.');
$root=dirname(__DIR__);
$lookup=file_get_contents($root.'/admin/amazon-returns/api/intake-lookup.php');
ipdAssert(is_string($lookup) && str_contains($lookup,'product_title'),'Intake lookup must expose product_title.');
ipdAssert(str_contains($lookup,'syncOrder'),'Intake lookup must be able to enrich legacy local cases whose product title is still missing.');
ipdAssert(str_contains($lookup,'PRODUCT_DETAILS_SYNCED'),'Manual enrichment must persist the product title as immutable event evidence.');
$page=file_get_contents($root.'/admin/amazon-returns/intake.php');
ipdAssert(is_string($page) && str_contains($page,'Descrição do produto') && str_contains($page,'c.product_title'),'Receipt confirmation must show the real product description.');
ipdAssert(str_contains($page,'SKU / ASIN'),'SKU and ASIN must remain visible as secondary identifiers.');
echo "intake-product-description-test: OK\n";
