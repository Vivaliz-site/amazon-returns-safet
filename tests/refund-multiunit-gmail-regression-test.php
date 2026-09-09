<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/GmailParser.php';

function refundQtySame(mixed $expected,mixed $actual,string $message):void
{
    if($expected!==$actual){
        throw new RuntimeException($message."\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true));
    }
}

$parser=new SvAmazonGmailParser();
$events=$parser->parse([
    'message_id'=>'1a05fcfa99eb924f',
    'thread_id'=>'1a05fcfa99eb924f',
    'from'=>'donotreply@amazon.com',
    'subject'=>'Reembolso de 85.5 BRL iniciado para o pedido 701-0630116-9129834',
    'body_text'=>"Prezado(a) ShopVivaLiz,\n\nIniciou um reembolso no valor de BRL 85.5 para Thiago para os seguintes produtos:\n\nPedido : 701-0630116-9129834\nLogística: Enviado pela Amazon\n\nEmissor do reembolso: Customer Service\n\nASIN\n\nSKU\n\nQuantidade do pedido\n\nQuantidade da devolução\n\nProduto do pedido\n\nMotivo para reembolso\n\nB0CLVRBLZL\n\nBC-RTCH-B2G7\n\n2\n\n2\n\nKit 4 Rodinhas\n\nPedido não recebido\n\nSua conta de vendedor será debitada adequadamente.",
    'received_at'=>'2026-09-02T01:50:37Z',
]);

refundQtySame(1,count($events),'Target Amazon refund email must produce one event.');
$event=$events[0];
refundQtySame('FBA',$event['program']??null,'Explicit Amazon fulfillment must remain FBA.');
refundQtySame('AMAZON_CUSTOMER_SERVICE',$event['refund_initiator']??null,'Customer Service issuer must remain explicit.');
refundQtySame(2,$event['quantity_ordered']??null,'Refund email must capture ordered quantity.');
refundQtySame(2,$event['quantity_refunded']??null,'Refund email must capture refunded quantity.');

echo "refund-multiunit-gmail-regression-test: OK\n";
