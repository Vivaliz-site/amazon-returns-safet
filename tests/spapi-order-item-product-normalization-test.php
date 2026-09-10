<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SpApi.php';

function spiEq(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.json_encode($expected).' actual='.json_encode($actual));
}

final class NestedProductOrderClient {
    public function marketplaceId(): string { return 'A2Q3Y263D00KWC'; }
    public function request(string $method,string $path,array $query=[],?array $body=null): array {
        return ['status'=>200,'request_id'=>'nested-product','data'=>[
            'orderId'=>'701-2279823-5272226',
            'salesChannel'=>['marketplaceId'=>'A2Q3Y263D00KWC'],
            'orderItems'=>[[
                'orderItemId'=>'161437610223761',
                'quantityOrdered'=>1,
                'product'=>[
                    'asin'=>'B0GCWWKQ67',
                    'sellerSku'=>'I7-LTFG-UH4X',
                    'title'=>'Aquatools Vedante para Porta',
                ],
            ]],
        ]];
    }
}

$order=(new SvAmazonReturnsSpApi(new NestedProductOrderClient()))->syncOrder('701-2279823-5272226');
$item=$order['order_items'][0]??[];
spiEq('B0GCWWKQ67',$item['asin']??null,'Orders v2026 nested product ASIN must normalize to the order item.');
spiEq('I7-LTFG-UH4X',$item['sellerSku']??null,'Orders v2026 nested product sellerSku must normalize to the order item.');
spiEq('Aquatools Vedante para Porta',$item['title']??null,'Orders v2026 nested product title must normalize to the order item.');
echo "spapi-order-item-product-normalization-test: OK\n";
