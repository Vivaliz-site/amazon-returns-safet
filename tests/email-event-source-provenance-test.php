<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/GmailEventSink.php';

function espSame(mixed $expected,mixed $actual,string $message): void
{
    if($expected!==$actual){
        fwrite(STDERR,$message.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");
        exit(1);
    }
}

$method=new ReflectionMethod(SvAmazonGmailEventSink::class,'refundInitiatorEvidence');
$base=[
    'event_type'=>'REFUND_ISSUED_EMAIL',
    'refund_initiator'=>'AMAZON_CUSTOMER_SERVICE',
    'idempotency_key'=>hash('sha256','base'),
    'content_sha256'=>hash('sha256','content'),
];

$gmail=$method->invoke(null,101,$base+['source'=>'GMAIL'],'702-6823050-9173862','2026-10-05 02:30:00','msg-1');
$cloudflare=$method->invoke(null,101,$base+['source'=>'CLOUDFLARE_EMAIL'],'702-6823050-9173862','2026-10-05 02:30:00','msg-1');

espSame('GMAIL',$gmail['source']??null,'Gmail evidence provenance must remain Gmail.');
espSame('CLOUDFLARE_EMAIL',$cloudflare['source']??null,'Cloudflare evidence provenance must be preserved.');
if(($gmail['idempotency_key']??'')===($cloudflare['idempotency_key']??'')){
    fwrite(STDERR,"Ingress source must participate in evidence idempotency.\n");
    exit(1);
}

echo "email-event-source-provenance-test: OK\n";
