<?php
declare(strict_types=1);

$presentation=__DIR__.'/../includes/amazon-returns/ErpSalesReturnPresentation.php';
if(!is_file($presentation))throw new RuntimeException('ERP sales return presentation helper is missing.');
require_once $presentation;

function erpUiAssert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function erpUiSame(mixed $expected,mixed $actual,string $message): void {if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));}

$expect=[
    'READY_TO_CREATE'=>'Devolução no ERP pronta para criar',
    'RETURN_CREATED_WAITING_INVOICE'=>'Devolução criada no ERP — aguardando NF de devolução',
    'RETURN_INVOICE_EXISTS'=>'NF de devolução já emitida',
    'BLOCKED'=>'Não foi possível criar a devolução automaticamente',
];
foreach($expect as $status=>$label){
    $row=['status'=>$status,'return_invoice_number'=>'321','return_invoice_issued_at'=>'2026-09-12 18:00:00','last_error_message'=>'diagnóstico seguro'];
    $projected=SvAmazonErpSalesReturnPresentation::project($row);
    erpUiSame($label,$projected['label']??null,'ERP return status must use plain Portuguese.');
}
$invoice=SvAmazonErpSalesReturnPresentation::project(['status'=>'RETURN_INVOICE_EXISTS','return_invoice_number'=>'321','return_invoice_issued_at'=>'2026-09-12 18:00:00']);
erpUiSame('321',$invoice['invoice_number']??null,'Projection must expose return invoice number.');
erpUiSame('2026-09-12 18:00:00',$invoice['issued_at']??null,'Projection must expose return invoice date.');

erpUiSame(null,SvAmazonErpSalesReturnPresentation::project(null),'Missing ERP return workflow should project as null.');

$caseApi=(string)file_get_contents(__DIR__.'/../admin/amazon-returns/api/case.php');
$casesApi=(string)file_get_contents(__DIR__.'/../admin/amazon-returns/api/cases.php');
$ui=(string)file_get_contents(__DIR__.'/../admin/amazon-returns/assets/cockpit-operational.js');
erpUiAssert(str_contains($caseApi,"['erp_return']") || str_contains($caseApi,"['erp_return']="),'Case API must expose erp_return.');
erpUiAssert(str_contains($casesApi,"'erp_return'=>"),'Cases API must expose erp_return.');
erpUiAssert(str_contains($ui,'erp_return'),'Cockpit must consume ERP return projection.');
erpUiAssert(str_contains($ui,'Situação da devolução no ERP'),'Cockpit must show ERP return status in Portuguese.');
erpUiAssert(str_contains($ui,'NF de devolução'),'Cockpit must show the return invoice when available.');
foreach(['Emitir NF','Gerar NF de devolução','Autorizar NF de devolução'] as $forbidden){
    erpUiAssert(!str_contains($ui,$forbidden),'Cockpit must not offer fiscal issuance action: '.$forbidden);
}

echo "erp-sales-return-ui-contract-test: OK\n";
