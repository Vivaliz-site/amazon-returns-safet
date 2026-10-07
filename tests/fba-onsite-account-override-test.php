<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/Config.php';
require_once __DIR__.'/../includes/amazon-returns/SpApiEventSink.php';
require_once __DIR__.'/../includes/amazon-returns/GmailEventSink.php';

function faoSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}
function faoAssert(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException($message);
}

$genericAmazonOrder=['programs'=>[],'fulfillment'=>['fulfilledBy'=>'AMAZON']];
faoSame(
    SvAmazonReturnPrograms::FBA_ONSITE,
    SvAmazonSpApiEventSink::programFromOrder($genericAmazonOrder,SvAmazonReturnPrograms::FBA_ONSITE),
    'Account-level FBA Onsite policy must resolve generic AMAZON/FBA evidence to FBA_ONSITE.'
);

faoSame(
    SvAmazonReturnPrograms::DELIVERY_BY_AMAZON,
    SvAmazonSpApiEventSink::programFromOrder(
        ['programs'=>['DELIVERY_BY_AMAZON'],'fulfillment'=>['fulfilledBy'=>'AMAZON']],
        SvAmazonReturnPrograms::FBA_ONSITE
    ),
    'Explicit Delivery by Amazon evidence must not be overwritten by the FBA Onsite account policy.'
);

$shipmentPatch=SvAmazonGmailEventSink::casePatch(
    ['event_type'=>'FBA_SHIPMENT_EMAIL'],
    [],
    SvAmazonReturnPrograms::FBA_ONSITE
);
faoSame(
    SvAmazonReturnPrograms::FBA_ONSITE,
    $shipmentPatch['program']??null,
    'Generic FBA shipment email evidence must resolve to FBA_ONSITE for an FBA Onsite-only account.'
);

$refundPatch=SvAmazonGmailEventSink::casePatch(
    ['event_type'=>'REFUND_ISSUED_EMAIL','program'=>'FBA','amount'=>'10.00','occurred_at'=>'2026-10-07 12:00:00'],
    ['program'=>SvAmazonReturnPrograms::UNKNOWN],
    SvAmazonReturnPrograms::FBA_ONSITE
);
faoSame(
    SvAmazonReturnPrograms::FBA_ONSITE,
    $refundPatch['program']??null,
    'Generic FBA refund evidence must resolve to FBA_ONSITE for an FBA Onsite-only account.'
);

$config=new SvAmazonReturnsConfig(['AMAZON_RETURNS_FULFILLMENT_PROGRAM_OVERRIDE'=>'FBA_ONSITE']);
faoAssert(method_exists($config,'fulfillmentProgramOverride'),'Config must expose the tenant/account fulfillment program override.');
faoSame(SvAmazonReturnPrograms::FBA_ONSITE,$config->fulfillmentProgramOverride(),'Configured FBA Onsite override must normalize deterministically.');

echo "fba-onsite-account-override-test: OK\n";
