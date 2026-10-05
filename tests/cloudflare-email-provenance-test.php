<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/GmailParser.php';

function cepSame(mixed $expected,mixed $actual,string $message): void
{
    if($expected!==$actual){
        fwrite(STDERR,$message.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");
        exit(1);
    }
}

$base=[
    'message_id'=>'cf-msg-1',
    'rfc_message_id'=>'<cf-msg-1@example.test>',
    'from'=>'Amazon <donotreply@amazon.com.br>',
    'subject'=>'Reembolso de 46,60 BRL iniciado para o pedido 702-6823050-9173862',
    'received_at'=>'2026-10-05T02:30:00Z',
    'body_text'=>"Emissor do reembolso: Customer Service\nRede logistica da Amazon: Enviado pela Amazon\nPedido 702-6823050-9173862",
];

$parser=new SvAmazonGmailParser();
$gmail=$parser->parse($base);
$cloudflare=$parser->parse($base+['source'=>'CLOUDFLARE_EMAIL']);

cepSame(1,count($gmail),'Gmail baseline must parse exactly one event.');
cepSame(1,count($cloudflare),'Cloudflare ingress must parse exactly one event.');
cepSame('GMAIL',$gmail[0]['source']??null,'Default provenance must remain Gmail.');
cepSame('CLOUDFLARE_EMAIL',$cloudflare[0]['source']??null,'Cloudflare provenance must be preserved.');
if(($gmail[0]['idempotency_key']??'')===($cloudflare[0]['idempotency_key']??'')){
    fwrite(STDERR,"Ingress source must participate in idempotency identity.\n");
    exit(1);
}

echo "cloudflare-email-provenance-test: OK\n";
