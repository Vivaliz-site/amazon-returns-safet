<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/GmailParser.php';
require_once __DIR__.'/../includes/amazon-returns/GmailEventSink.php';

function gairSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}

$parser=new SvAmazonGmailParser();
$events=$parser->parse([
    'message_id'=>'refund-702-2491487-8294621',
    'from'=>'Amazon Seller Central <donotreply@amazon.com>',
    'subject'=>'Reembolso de 39.89 BRL iniciado para o pedido 702-2491487-8294621',
    'received_at'=>'2026-08-12T00:32:40Z',
    'body_text'=>"Prezado(a) ShopVivaLiz,\nIniciamos um reembolso de BRL 39.89 para Sabrina relativo(s) ao(s) seguinte(s) produto(s):\n- ID do pedido: 702-2491487-8294621\n- Rede logística da Amazon: Enviado pelo vendedor\nMotivo para reembolso\nO produto não é como o descrito\nSua conta de vendedor será debitada adequadamente.",
]);

gairSame(1,count($events),'Amazon refund notice must be parsed.');
gairSame('AMAZON_INITIATED',$events[0]['refund_initiator']??null,'First-person Amazon refund notice must preserve Amazon as the verified initiator when subtype is absent.');
$patch=SvAmazonGmailEventSink::casePatch($events[0],['refund_initiator'=>'UNKNOWN']);
gairSame('AMAZON_INITIATED',$patch['refund_initiator']??null,'Verified generic Amazon initiator must resolve an UNKNOWN case.');
echo "gmail-amazon-initiated-refund-test: OK\n";
