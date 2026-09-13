<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/Schema.php';
require_once __DIR__.'/../includes/amazon-returns/TenantContext.php';

function erpSalesReturnRepoAssert(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}

$repositoryPath=__DIR__.'/../includes/amazon-returns/ErpSalesReturnRepository.php';
erpSalesReturnRepoAssert(is_file($repositoryPath),'ERP sales return repository implementation is missing.');
require_once $repositoryPath;

$ddl=implode("\n",SvAmazonReturnsSchema::statements());
erpSalesReturnRepoAssert(str_contains($ddl,'CREATE TABLE IF NOT EXISTS `amazon_return_erp_sales_returns`'),'ERP sales return lifecycle table is missing.');
erpSalesReturnRepoAssert(str_contains($ddl,'`tenant_id` BIGINT UNSIGNED NOT NULL'),'ERP sales return lifecycle must be tenant scoped.');
erpSalesReturnRepoAssert(str_contains($ddl,'`amazon_connection_id` BIGINT UNSIGNED NOT NULL'),'ERP sales return lifecycle must be Amazon-connection scoped.');
erpSalesReturnRepoAssert(str_contains($ddl,'`amazon_order_id` VARCHAR(32) NOT NULL'),'ERP sales return lifecycle must keep the Amazon order identity.');
erpSalesReturnRepoAssert(str_contains($ddl,'UNIQUE KEY `uq_amazon_return_erp_sales_return_order` (`tenant_id`, `amazon_connection_id`, `amazon_order_id`)'),'ERP sales return lifecycle must allow only one workflow per tenant/connection/order.');
erpSalesReturnRepoAssert(str_contains($ddl,'UNIQUE KEY `uq_amazon_return_erp_sales_return_idempotency` (`tenant_id`, `amazon_connection_id`, `idempotency_key`)'),'ERP sales return lifecycle must have a scoped idempotency key.');

foreach(['findByOrder','ensureWorkflow','markReady','markReturnCreated','linkReturnInvoice','markBlocked'] as $method){
    erpSalesReturnRepoAssert(method_exists(SvAmazonErpSalesReturnRepository::class,$method),'ERP sales return repository is missing '.$method.'().');
}

$source=(string)file_get_contents($repositoryPath);
erpSalesReturnRepoAssert(str_contains($source,'tenant_id=:tenant_id'),'Repository SQL must explicitly scope tenant_id.');
erpSalesReturnRepoAssert(str_contains($source,'amazon_connection_id=:amazon_connection_id'),'Repository SQL must explicitly scope amazon_connection_id.');
erpSalesReturnRepoAssert(!str_contains($source,'TINY_ACCESS_TOKEN'),'Lifecycle persistence must never store ERP credentials.');
erpSalesReturnRepoAssert(!str_contains($source,'OLIST_ACCESS_TOKEN'),'Lifecycle persistence must never store ERP credentials.');

echo "erp-sales-return-repository-test: OK\n";
