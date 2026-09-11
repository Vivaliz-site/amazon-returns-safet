<?php
declare(strict_types=1);

function invoiceIntakeAssert(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}
$path=__DIR__.'/../admin/amazon-returns/api/intake-lookup.php';
$source=file_get_contents($path);
invoiceIntakeAssert(is_string($source),'Unable to read intake lookup endpoint.');

$localPos=strpos($source,'SvAmazonCaseReferenceSearch::caseIds');
$livePos=strpos($source,'->findOrderByInvoiceNumber($invoiceNumber)');
invoiceIntakeAssert(is_int($localPos),'NF lookup must check the shared immutable local reference evidence first.');
invoiceIntakeAssert(is_int($livePos),'NF lookup must query Amazon Invoices API when local evidence is absent.');
invoiceIntakeAssert($localPos<$livePos,'Shared local reference evidence must be consulted before the live Amazon lookup.');
$referenceSource=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/CaseReferenceSearch.php');
invoiceIntakeAssert(str_contains($referenceSource,'SvAmazonInvoiceSearch::caseIdsExact'),'Structured NF resolution must remain exact in the shared resolver.');
invoiceIntakeAssert(
    str_contains($source,'$orderId=trim((string)($invoiceLookup[\'order_id\']??\'\'));'),
    'Live invoice lookup must hand the resolved Amazon order to the order sync flow.'
);
invoiceIntakeAssert(str_contains($source,'->syncOrder($orderId)'),'Resolved NF order must be synchronized immediately.');
invoiceIntakeAssert(str_contains($source,'SvAmazonInvoiceSearch::evidenceEvent'),'Resolved NF/order relation must be persisted as immutable evidence.');
invoiceIntakeAssert(str_contains($source,'SvAmazonInvoiceAccessException'),'NF authorization failures must have a dedicated safe handling path.');
invoiceIntakeAssert(
    str_contains($source,'A Amazon ainda não autorizou a consulta por NF nesta conta.'),
    'Operator must receive a simple Portuguese authorization message.'
);

echo "invoice-intake-live-lookup-contract-test: OK\n";
