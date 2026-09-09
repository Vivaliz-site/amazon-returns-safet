<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/GmailParser.php';
require_once __DIR__.'/../includes/amazon-returns/GmailEventSink.php';
require_once __DIR__.'/../includes/amazon-returns/Projector.php';

function riqEq(mixed $expected,mixed $actual,string $message):void
{
    if($expected!==$actual){
        throw new RuntimeException($message."\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true));
    }
}

$parser=new SvAmazonGmailParser();
$events=$parser->parse([
    'message_id'=>'1a087695ef155ae6',
    'thread_id'=>'1a087695ef155ae6',
    'from'=>'donotreply@amazon.com',
    'subject'=>'Reembolso de 39.89 BRL iniciado para o pedido 702-2491487-8294621',
    'body_text'=>"Prezado(a) ShopVivaLiz,\n\nIniciamos um reembolso de BRL 39.89 para Sabrina relativo(s) ao(s) seguinte(s) produto(s):\n\n- ID do pedido: 702-2491487-8294621\n\n- Rede logística da Amazon: Enviado pelo vendedor\n\nProduto do pedido\n\nASIN\n\nSKU\n\nQuantidade da devolução\n\nReembolso do preço do produto\n\nReembolso do custo do frete de saída\n\nMotivo para reembolso\n\nAstra TPJ/AS*BR1 Assento Sanitário Soft, Branco\n\nB076PRVLPB\n\nTpjdba\n\n1\n\n39.89\n\n0\n\nO produto não é como o descrito\n\nSua conta de vendedor será debitada adequadamente.",
    'received_at'=>'2026-09-09T18:23:42Z',
]);
riqEq(1,count($events),'Refund notice must parse exactly one event.');
$event=$events[0];
riqEq('AMAZON_INITIATED',$event['refund_initiator']??null,'Verified Amazon-authored refund must retain initiator evidence.');
riqEq(1,$event['quantity_refunded']??null,'Amazon refund table must capture explicit returned quantity even when it omits ordered quantity.');
riqEq(null,$event['quantity_ordered']??null,'Parser must not invent ordered quantity when the email omits it.');

$patchMethod=new ReflectionMethod(SvAmazonGmailEventSink::class,'casePatch');
$patch=$patchMethod->invoke(null,$event,[
    'program'=>'DELIVERY_BY_AMAZON','refund_initiator'=>'UNKNOWN',
    'quantity_ordered'=>1,'quantity_refunded'=>0,'refund_amount'=>'39.89',
]);
riqEq(1,$patch['quantity_refunded']??null,'Explicit refunded quantity must enrich an order whose ordered quantity is already known from SP-API.');

$evidenceMethod=new ReflectionMethod(SvAmazonGmailEventSink::class,'refundQuantityEvidence');
$evidence=$evidenceMethod->invoke(null,1263,$event,'702-2491487-8294621','2026-09-09 18:23:42','1a087695ef155ae6');
riqEq('REFUND_QUANTITY_CONFIRMED',$evidence['event_type']??null,'Refunded quantity must be persisted as durable evidence.');
riqEq(1,$evidence['payload']['quantity_refunded']??null,'Durable evidence must preserve the explicit refunded quantity.');
if(array_key_exists('quantity_ordered',$evidence['payload'])){
    throw new RuntimeException('Durable Gmail evidence must not invent an ordered quantity absent from the message.');
}

$projected=SvAmazonReturnProjector::projectFrom([
    'id'=>1263,'amazon_order_id'=>'702-2491487-8294621','quantity_ordered'=>1,'quantity_refunded'=>0,
    'physical_status'=>SvAmazonReturnPhysicalStatuses::NOT_RECEIVED,'state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,
],[$evidence]);
riqEq(1,$projected['quantity_refunded']??null,'Projection replay must preserve the explicit refunded quantity.');

echo "refund-issued-return-quantity-test: OK\n";
