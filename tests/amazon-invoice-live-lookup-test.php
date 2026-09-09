<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/SpApi.php';

function invoiceLookupAssert(bool $condition,string $message): void {
    if(!$condition) throw new RuntimeException($message);
}
function invoiceLookupSame(mixed $expected,mixed $actual,string $message): void {
    if($expected!==$actual){
        throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true));
    }
}

final class InvoiceLookupFakeClient {
    public array $calls=[];
    public function marketplaceId(): string { return 'A2Q3Y263D00KWC'; }
    public function request(string $method,string $path,array $query=[],?array $body=null): array {
        $this->calls[]=compact('method','path','query','body');
        return [
            'status'=>200,
            'request_id'=>'req-invoice-1',
            'data'=>['invoices'=>[[
                'id'=>'invoice-api-id-1',
                'externalInvoiceId'=>'987654',
                'series'=>'1',
                'status'=>'AUTHORIZED',
                'invoiceType'=>'CUSTOMER_SALES',
                'transactionType'=>'CUSTOMER_SALES',
                'transactionIds'=>[
                    ['name'=>'BUSINESS_TRANSACTION_ID','id'=>'702-5144267-2415462'],
                ],
            ]]],
        ];
    }
}

final class InvoiceLookupDeniedFakeClient {
    public function marketplaceId(): string { return 'A2Q3Y263D00KWC'; }
    public function request(string $method,string $path,array $query=[],?array $body=null): array {
        return [
            'status'=>403,
            'request_id'=>'req-denied',
            'data'=>['errors'=>[[
                'code'=>'Unauthorized',
                'message'=>'Access to requested resource is denied.',
                'details'=>'',
            ]]],
        ];
    }
}

$client=new InvoiceLookupFakeClient();
$api=new SvAmazonReturnsSpApi($client);
invoiceLookupAssert(
    method_exists($api,'findOrderByInvoiceNumber'),
    'SP-API facade must support live lookup of an Amazon order by NF number.'
);
$found=$api->findOrderByInvoiceNumber('987654');
invoiceLookupSame('/tax/invoices/2024-06-19/invoices',$client->calls[0]['path'] ?? null,'Invoices API path must be official v2024-06-19.');
invoiceLookupSame('987654',$client->calls[0]['query']['externalInvoiceId'] ?? null,'NF lookup must filter by exact externalInvoiceId.');
invoiceLookupSame('A2Q3Y263D00KWC',$client->calls[0]['query']['marketplaceId'] ?? null,'NF lookup must be scoped to the active marketplace.');
invoiceLookupSame('702-5144267-2415462',$found['order_id'] ?? null,'NF lookup must resolve the associated Amazon order ID.');
invoiceLookupSame('987654',$found['invoice_number'] ?? null,'NF evidence must retain the exact invoice number.');
invoiceLookupSame('invoice-api-id-1',$found['invoice_id'] ?? null,'NF evidence must retain the Amazon invoice ID.');
invoiceLookupSame('req-invoice-1',$found['request_id'] ?? null,'NF evidence must retain the Amazon request ID.');

$denied=false;
try{
    (new SvAmazonReturnsSpApi(new InvoiceLookupDeniedFakeClient()))
        ->findOrderByInvoiceNumber('987654');
}catch(SvAmazonInvoiceAccessException){
    $denied=true;
}
invoiceLookupAssert($denied,'Invoices API permission failures must be classified without leaking raw connector errors.');

echo "amazon-invoice-live-lookup-test: OK\n";
