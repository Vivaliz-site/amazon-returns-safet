<?php
declare(strict_types=1);

function erpInvoiceAssert(bool $condition,string $message): void {
    if(!$condition) throw new RuntimeException($message);
}
function erpInvoiceSame(mixed $expected,mixed $actual,string $message): void {
    if($expected!==$actual){
        throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true));
    }
}

$erpPath=__DIR__.'/../includes/amazon-returns/ErpInvoiceLookup.php';
$resolverPath=__DIR__.'/../includes/amazon-returns/InvoiceRemoteLookup.php';
erpInvoiceAssert(is_file($erpPath),'ERP invoice lookup implementation is missing.');
erpInvoiceAssert(is_file($resolverPath),'Invoice remote fallback resolver is missing.');

require_once __DIR__.'/../includes/amazon-returns/SpApi.php';
require_once __DIR__.'/../includes/amazon-returns/InvoiceSearch.php';
require_once $erpPath;
require_once $resolverPath;

erpInvoiceAssert(method_exists(SvAmazonErpInvoiceLookup::class,'defaultCredentialPath'),'ERP lookup must expose its production credential fallback path.');
erpInvoiceSame('/home/ubuntu/shopvivaliz-deploy/shared/.env',SvAmazonErpInvoiceLookup::defaultCredentialPath(),'ERP lookup must reuse the existing shared Tiny/Olist credential source without copying tokens.');

$calls=[];
$http=static function(string $method,string $url,array $headers,?string $body) use (&$calls): array {
    $calls[]=compact('method','url','headers','body');
    return [
        'status'=>200,
        'json'=>[
            'itens'=>[
                [
                    'id'=>370646979,
                    'numero'=>'002214',
                    'serie'=>'10',
                    'situacao'=>'7',
                    'tipo'=>'S',
                    'ecommerce'=>[
                        'nome'=>'Amazon Onsite',
                        'numeroPedidoEcommerce'=>'702-5144267-2415462',
                    ],
                ],
                [
                    'id'=>348545604,
                    'numero'=>'002214',
                    'serie'=>'420',
                    'situacao'=>'6',
                    'tipo'=>'S',
                    'ecommerce'=>[
                        'nome'=>'',
                        'numeroPedidoEcommerce'=>'',
                    ],
                ],
            ],
            'paginacao'=>['limit'=>100,'offset'=>0,'total'=>2],
        ],
    ];
};

$erp=new SvAmazonErpInvoiceLookup(['TINY_ACCESS_TOKEN'=>'test-token'],$http);
$found=$erp->findOrderByInvoiceNumber('002214');
erpInvoiceSame('GET',$calls[0]['method'] ?? null,'ERP lookup must be read-only.');
$query=[];
parse_str((string)parse_url((string)($calls[0]['url'] ?? ''),PHP_URL_QUERY),$query);
erpInvoiceSame('002214',$query['numero'] ?? null,'ERP lookup must query the exact NF number.');
erpInvoiceSame('S',$query['tipo'] ?? null,'ERP lookup must restrict results to sales/output invoices.');
erpInvoiceSame('702-5144267-2415462',$found['order_id'] ?? null,'ERP lookup must resolve the Amazon order from ecommerce metadata.');
erpInvoiceSame('002214',$found['invoice_number'] ?? null,'ERP evidence must preserve the official NF number.');
erpInvoiceSame('10',$found['series'] ?? null,'ERP evidence must preserve the NF series.');
erpInvoiceSame('ERP_OLIST_INVOICE',$found['source'] ?? null,'ERP evidence must identify its real provenance.');

erpInvoiceSame('ERP_OLIST_INVOICE',SvAmazonInvoiceSearch::evidenceEvent(
    321,$found,new DateTimeImmutable('2026-09-09T10:30:00Z')
)['source'] ?? null,'Persisted evidence must retain ERP provenance.');

final class DeniedAmazonInvoiceLookup {
    public function findOrderByInvoiceNumber(string $invoiceNumber): ?array {
        throw new SvAmazonInvoiceAccessException('denied');
    }
}
final class CountingErpInvoiceLookup {
    public int $calls=0;
    public function __construct(private ?array $result) {}
    public function findOrderByInvoiceNumber(string $invoiceNumber): ?array {
        $this->calls++;
        return $this->result;
    }
}

$erpFallback=new CountingErpInvoiceLookup($found);
$resolver=new SvAmazonInvoiceRemoteLookup(new DeniedAmazonInvoiceLookup(),$erpFallback);
$resolved=$resolver->findOrderByInvoiceNumber('002214');
erpInvoiceSame('702-5144267-2415462',$resolved['order_id'] ?? null,'Denied Amazon invoice access must fall back to ERP.');
erpInvoiceSame(1,$erpFallback->calls,'ERP fallback must run exactly once after Amazon permission denial.');

echo "erp-invoice-fallback-test: OK\n";
