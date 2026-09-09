<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/RemoteBridge.php';

function rbpiEq(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.json_encode($expected).' actual='.json_encode($actual));
}
$row=[
    'id'=>99,'tenant_id'=>1,'amazon_connection_id'=>10,'case_id'=>13261,
    'kind'=>'SELLER_SUPPORT_OPEN','idempotency_key'=>str_repeat('a',64),'attempt_count'=>0,
    'payload'=>['decision'=>['reason'=>'APPROVED_PARTIAL_REIMBURSEMENT_SUPPORT_RECOVERY','support_route'=>'GENERAL_ORDER_SUPPORT']],
];
$case=[
    'amazon_order_id'=>'701-2279823-5272226','amazon_order_item_id'=>'161437610223761',
    'asin'=>'B0GCWWKQ67','sku'=>'I7-LTFG-UH4X','safe_t_id'=>'35001-00778-9264871',
    'support_case_id'=>null,'quantity_refunded'=>1,'quantity_received'=>0,
];
$job=SvAmazonReturnsRemoteBridge::jobEnvelope($row,$case,['SELLER_SUPPORT_OPEN'=>true]);
rbpiEq('B0GCWWKQ67',$job['case']['asin']??null,'Bridge must carry ASIN to Seller Support.');
rbpiEq('I7-LTFG-UH4X',$job['case']['sku']??null,'Bridge must carry SKU to Seller Support.');
echo "remote-bridge-product-identity-test: OK\n";
