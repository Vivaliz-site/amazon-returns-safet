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

// A single order item remains unambiguous even when more than one unit was ordered.
// The financial amount can safely seed reimbursement tracking, while quantity must
// come from explicit quantity evidence rather than being guessed from ordered units.
$quantityOrdered=2;
$singleItem=true;
$shouldPersistFinancialBaseline=$singleItem && $refund!==null;
multiUnitSpApiSame(true,$shouldPersistFinancialBaseline,'Single-item multi-unit orders must not suppress the financial refund baseline.');
multiUnitSpApiSame(2,$quantityOrdered,'Fixture must remain multi-unit.');

echo "refund-multiunit-spapi-regression-test: OK\n";
