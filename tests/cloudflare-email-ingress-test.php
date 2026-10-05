<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/CloudflareEmailIngress.php';

function ceiSame($expected,$actual,string $message): void {
    if($expected!==$actual){
        fwrite(STDERR,$message."\n");
        exit(1);
    }
}

$input=[
    'message_id'=>'worker-123',
    'rfc_message_id'=>'<abc@amazon.com>',
    'from'=>'Amazon <donotreply@amazon.com.br>',
    'subject'=>'Reembolso de 46,60 BRL iniciado para o pedido 702-6823050-9173862',
    'received_at'=>'2026-10-05T02:30:00Z',
    'body_text'=>'Pedido 702-6823050-9173862',
    'source'=>'GMAIL'
];

$normalized=SvAmazonCloudflareEmailIngress::normalize($input);
ceiSame('CLOUDFLARE_EMAIL',$normalized['source']??null,'Ingress source must be server-controlled.');
ceiSame('worker-123',$normalized['message_id']??null,'Message identity must be preserved.');

$events=SvAmazonCloudflareEmailIngress::parse($input);
ceiSame(1,count($events),'Valid Amazon refund email must produce one event.');
ceiSame('CLOUDFLARE_EMAIL',$events[0]['source']??null,'Parsed event must preserve Cloudflare provenance.');
ceiSame('REFUND_ISSUED_EMAIL',$events[0]['event_type']??null,'Existing Amazon email semantics must be reused.');

echo "cloudflare-email-ingress-test: OK\n";
