<?php
declare(strict_types=1);

function invoiceIntakeAssert(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}
$path=__DIR__.'/../admin/amazon-returns/api/intake-lookup.php';
$source=file_get_contents($path);
invoiceIntakeAssert(is_string($source),'Unable to read intake lookup endpoint.');

$localPos=strpos($source,'SvAmazonInvoiceSearch::caseIdsExact');
$livePos=strpos($source,'->findOrderByInvoiceNumber($invoiceNumber)');
invoiceIntakeAssert(is_int($localPos),'NF lookup must check immutable local evidence first.');
invoiceIntakeAssert(is_int($livePos),'NF lookup must query Amazon Invoices API when local evidence is absent.');
invoiceIntakeAssert($localPos<$livePos,'Local NF evidence must be consulted before the live Amazon lookup.');
invoiceIntakeAssert(
    str_contains($source,"$orderId=(string)$invoiceLookup['order_id'];")
    || str_contains($source,"$orderId=(string)($invoiceLookup['order_id']"),
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
