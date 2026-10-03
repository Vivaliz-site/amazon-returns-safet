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
erpInvoiceSame('/home/ubuntu/amazon-returns-deploy/shared/erp.env',SvAmazonErpInvoiceLookup::defaultCredentialPath(),'ERP lookup must use a standalone local credential link without copying tokens.');

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

$multiCalls=[];
$multiHttp=static function(string $method,string $url,array $headers,?string $body) use (&$multiCalls): array {
    $multiCalls[]=compact('method','url','headers','body');
    return [
        'status'=>200,
        'json'=>[
            'itens'=>[
                [
                    'id'=>382140959,'numero'=>'003043','serie'=>'10','situacao'=>'6','tipo'=>'S',
                    'ecommerce'=>['nome'=>'Amazon Onsite','numeroPedidoEcommerce'=>'702-0646566-5244224'],
                ],
                [
                    'id'=>352726265,'numero'=>'003043','serie'=>'420','situacao'=>'6','tipo'=>'S',
                    'ecommerce'=>['nome'=>'Amazon Classic','numeroPedidoEcommerce'=>'702-3845142-2739414'],
                ],
            ],
            'paginacao'=>['limit'=>100,'offset'=>0,'total'=>2],
        ],
    ];
};
$multiErp=new SvAmazonErpInvoiceLookup(['TINY_ACCESS_TOKEN'=>'test-token'],$multiHttp);
$multi=$multiErp->findOrdersByInvoiceNumber('3043');
erpInvoiceSame(2,count($multi),'Repeated invoice number across ERP series must resolve every distinct Amazon order.');
erpInvoiceSame(
    ['702-0646566-5244224','702-3845142-2739414'],
    array_values(array_map(static fn(array $row):string=>(string)($row['order_id']??''),$multi)),
    'Repeated invoice number must keep deterministic Amazon order candidates.'
);
$ambiguous=false;
try{$multiErp->findOrderByInvoiceNumber('3043');}
catch(UnexpectedValueException){$ambiguous=true;}
erpInvoiceSame(true,$ambiguous,'Legacy single-result ERP API must still fail closed on ambiguous invoice numbers.');

final class BrokenAmazonInvoiceLookup {
    public function findOrderByInvoiceNumber(string $invoiceNumber): ?array {
        throw new RuntimeException('Amazon LWA credentials incomplete.');
    }
}
final class MultiErpInvoiceLookup {
    public int $calls=0;
    /** @param list<array<string,mixed>> $results */
    public function __construct(private array $results) {}
    /** @return list<array<string,mixed>> */
    public function findOrdersByInvoiceNumber(string $invoiceNumber): array {
        $this->calls++;
        return $this->results;
    }
}
$multiFallback=new MultiErpInvoiceLookup($multi);
$multiResolver=new SvAmazonInvoiceRemoteLookup(new BrokenAmazonInvoiceLookup(),$multiFallback);
$resolvedMany=$multiResolver->findOrdersByInvoiceNumber('3043');
erpInvoiceSame(2,count($resolvedMany),'Missing Amazon LWA runtime must not prevent read-only ERP invoice resolution.');
erpInvoiceSame(1,$multiFallback->calls,'ERP multi-order fallback must run exactly once when Amazon runtime is unavailable.');

echo "erp-invoice-fallback-test: OK\n";
