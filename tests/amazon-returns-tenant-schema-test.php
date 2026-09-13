<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/Schema.php';

function tsAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$statements = SvAmazonReturnsSchema::statements();
$ddl = implode("\n", $statements);
$memoryTables = ['amazon_return_reviews','amazon_return_learned_rules','amazon_return_rule_applications'];
foreach ($memoryTables as $table) {
    tsAssert(str_contains($ddl, "CREATE TABLE IF NOT EXISTS `{$table}`"), "Missing memory table {$table}.");
}
foreach (['amazon_return_tenants','amazon_return_tenant_users','amazon_return_connections','amazon_return_feature_flags'] as $table) {
    tsAssert(str_contains($ddl, "CREATE TABLE IF NOT EXISTS `{$table}`"), "Missing control-plane table {$table}.");
}

tsAssert(str_contains($ddl, 'CREATE TABLE IF NOT EXISTS `amazon_return_erp_sales_returns`'), 'Missing ERP sales return lifecycle table.');

$tenantOwned = ['amazon_return_cases','amazon_return_events','amazon_return_policies','amazon_return_evidence','amazon_return_outbox','amazon_return_dead_letters','amazon_return_source_cursors','amazon_return_overrides','amazon_return_erp_sales_returns'];
$connectionOwned = ['amazon_return_cases','amazon_return_events','amazon_return_evidence','amazon_return_outbox','amazon_return_dead_letters','amazon_return_source_cursors','amazon_return_overrides','amazon_return_erp_sales_returns'];
$tenantOwned = array_merge($tenantOwned, $memoryTables);
$connectionOwned = array_merge($connectionOwned, $memoryTables);
foreach ($statements as $statement) {
    foreach ($tenantOwned as $table) {
        if (str_contains($statement, "CREATE TABLE IF NOT EXISTS `{$table}`")) {
            tsAssert(str_contains($statement, '`tenant_id` BIGINT UNSIGNED NOT NULL'), "{$table} missing tenant_id.");
        }
    }
    foreach ($connectionOwned as $table) {
        if (str_contains($statement, "CREATE TABLE IF NOT EXISTS `{$table}`")) {
            tsAssert(str_contains($statement, '`amazon_connection_id` BIGINT UNSIGNED NOT NULL'), "{$table} missing amazon_connection_id.");
        }
    }
}

tsAssert(str_contains($ddl, 'UNIQUE KEY `uq_amazon_return_case_order_item` (`tenant_id`, `amazon_connection_id`, `amazon_order_id`, `amazon_order_item_id`)'), 'Case key is not tenant/connection scoped.');
tsAssert(str_contains($ddl, 'UNIQUE KEY `uq_amazon_return_events_idempotency` (`tenant_id`, `amazon_connection_id`, `idempotency_key`)'), 'Event key is not tenant/connection scoped.');
tsAssert(str_contains($ddl, 'UNIQUE KEY `uq_amazon_return_outbox_idempotency` (`tenant_id`, `amazon_connection_id`, `idempotency_key`)'), 'Outbox key is not tenant/connection scoped.');
tsAssert(str_contains($ddl, 'UNIQUE KEY `uq_amazon_return_source_cursor` (`tenant_id`, `amazon_connection_id`, `source`, `cursor_key`)'), 'Cursor key is not tenant/connection scoped.');
tsAssert(str_contains($ddl, 'UNIQUE KEY `uq_amazon_return_policy_version` (`tenant_id`, `policy_key`, `marketplace_id`, `program`, `effective_from`)'), 'Policy key is not tenant scoped.');
tsAssert(str_contains($ddl, 'UNIQUE KEY `uq_amazon_return_erp_sales_return_order` (`tenant_id`, `amazon_connection_id`, `amazon_order_id`)'), 'ERP sales return key is not tenant/connection/order scoped.');
tsAssert(count($statements) === 16, 'Schema must contain four control-plane and twelve data-plane tables.');
tsAssert(substr_count($ddl, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci') === 16, 'Every table must use InnoDB and utf8mb4.');

echo "amazon-returns-tenant-schema-test: OK\n";
