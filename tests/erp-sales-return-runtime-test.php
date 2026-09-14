<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/Runtime.php';

function erpRuntimeAssert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function erpRuntimeSame(mixed $expected,mixed $actual,string $message): void {if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));}

$cadences=SvAmazonReturnsRuntime::cadences();
erpRuntimeSame(43200,$cadences['erp_sales_returns']??null,'ERP sales returns must use the approved 12-hour business cadence.');

$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
$task=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/ErpSalesReturnTask.php');
erpRuntimeAssert(str_contains($daemon,"'erp_sales_returns'=>\$this->runErpSalesReturns()"),'Daemon must dispatch ERP sales return reconciliation.');
erpRuntimeAssert(str_contains($daemon,'SvAmazonErpSalesReturnTask::run'),'Daemon must delegate ERP return reconciliation to the dedicated task.');
erpRuntimeAssert(str_contains($task,'SvAmazonErpSalesReturnService'),'ERP task must use the guarded ERP sales return service.');
erpRuntimeAssert(str_contains($task,'SvAmazonErpReturnInvoiceLookup'),'ERP task must check existing return NFs.');
erpRuntimeAssert(str_contains($task,'erpSalesReturnCreateEnabled()'),'ERP task must use the dedicated write gate.');
erpRuntimeAssert(str_contains($task,'SvAmazonOlistBrowserErpSalesReturnGateway'),'ERP task must route verified sales-return writes through the dedicated Olist browser gateway.');
erpRuntimeAssert(str_contains($task,'quantity_refunded'),'ERP task must only consider refunded orders.');

echo "erp-sales-return-runtime-test: OK\n";
