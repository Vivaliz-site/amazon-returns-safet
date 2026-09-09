<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/SpApiEventSink.php';

function multiUnitSpApiSame(mixed $expected,mixed $actual,string $message):void
{
    if($expected!==$actual){
        throw new RuntimeException($message."\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true));
    }
}

$transactions=[[
    'transaction_id'=>'refund-1',
    'transaction_type'=>'Refund',
    'transaction_status'=>'DEFERRED_RELEASED',
    'posted_at'=>'2026-09-02T02:05:29Z',
    'total_amount'=>['amount'=>'-81.00','currency'=>'BRL'],
    'related_identifiers'=>[
        ['name'=>'REFUND_ID','value'=>'refund-group-1'],
        ['name'=>'ORDER_ID','value'=>'701-0630116-9129834'],
    ],
]];

$refund=SvAmazonSpApiEventSink::refundObservation($transactions);
multiUnitSpApiSame('81.00',$refund['refund_amount']??null,'Released financial refund must expose the seller debit baseline.');
multiUnitSpApiSame('2026-09-02 02:05:29',$refund['seller_debit_at']??null,'Released financial refund must expose the debit timestamp.');

if(!method_exists(SvAmazonSpApiEventSink::class,'financialRefundPayload')){
    throw new RuntimeException('Single-item multi-unit refunds need an attributable financial payload without guessed quantity.');
}
$payloadMethod=new ReflectionMethod(SvAmazonSpApiEventSink::class,'financialRefundPayload');
$multi=$payloadMethod->invoke(null,$refund,SvAmazonReturnPrograms::FBA,2);
multiUnitSpApiSame('81.00',$multi['refund_amount']??null,'Multi-unit financial payload must retain the authoritative seller debit amount.');
multiUnitSpApiSame('2026-09-02 02:05:29',$multi['seller_debit_at']??null,'Multi-unit financial payload must retain the debit timestamp.');
if(array_key_exists('quantity_refunded',$multi)){
    throw new RuntimeException('Financial transaction alone must not guess that every ordered unit was refunded.');
}
$single=$payloadMethod->invoke(null,$refund,SvAmazonReturnPrograms::FBA,1);
multiUnitSpApiSame(1,$single['quantity_refunded']??null,'Single-unit financial evidence may confirm its only possible refunded quantity.');

$source=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/SpApiEventSink.php');
if(substr_count($source,'self::financialRefundPayload(')<1){
    throw new RuntimeException('SP-API persistence must use the quantity-safe financial payload.');
}
if(str_contains($source,'$single && $quantity === 1 && $refund !== null')){
    throw new RuntimeException('Single-item multi-unit refunds must not be suppressed from financial baseline persistence.');
}

echo "refund-multiunit-spapi-regression-test: OK\n";
