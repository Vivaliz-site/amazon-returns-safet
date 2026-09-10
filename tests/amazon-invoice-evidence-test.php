<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/InvoiceSearch.php';

function invoiceEvidenceSame(mixed $expected,mixed $actual,string $message): void {
    if($expected!==$actual){
        throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true));
    }
}

if(!method_exists(SvAmazonInvoiceSearch::class,'evidenceEvent')){
    throw new RuntimeException('Invoice lookup must expose an immutable tenant-scoped evidence event builder.');
}
$lookup=[
    'source'=>'SP_API_INVOICES',
    'request_id'=>'req-invoice-1',
    'invoice_id'=>'invoice-api-id-1',
    'invoice_number'=>'987654',
    'series'=>'1',
    'status'=>'AUTHORIZED',
    'invoice_type'=>'CUSTOMER_SALES',
    'transaction_type'=>'CUSTOMER_SALES',
    'order_id'=>'702-5144267-2415462',
];
$event=SvAmazonInvoiceSearch::evidenceEvent(321,$lookup,new DateTimeImmutable('2026-09-09T10:30:00Z'));
invoiceEvidenceSame(321,$event['case_id'] ?? null,'Evidence must attach to the resolved case.');
invoiceEvidenceSame('SALES_INVOICE_LINKED',$event['event_type'] ?? null,'Evidence must use a dedicated immutable event type.');
invoiceEvidenceSame('SP_API_INVOICES',$event['source'] ?? null,'Evidence source must identify the official Invoices API.');
invoiceEvidenceSame('987654',$event['payload']['invoice_number'] ?? null,'Evidence must retain the invoice number.');
invoiceEvidenceSame('702-5144267-2415462',$event['payload']['order_id'] ?? null,'Evidence must retain the linked Amazon order.');
invoiceEvidenceSame('2026-09-09 10:30:00',$event['occurred_at'] ?? null,'Evidence timestamp must normalize to UTC.');
if(preg_match('/^[a-f0-9]{64}$/',(string)($event['idempotency_key'] ?? ''))!==1){
    throw new RuntimeException('Invoice evidence must use a deterministic SHA-256 idempotency key.');
}

echo "amazon-invoice-evidence-test: OK\n";
