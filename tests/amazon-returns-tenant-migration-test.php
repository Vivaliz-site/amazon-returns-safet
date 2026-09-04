<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$classPath = $root . '/includes/amazon-returns/TenantMigration.php';
$scriptPath = $root . '/scripts/migrate-single-tenant-to-multitenant.php';

if (!is_file($classPath)) throw new RuntimeException('Tenant migration class must exist.');
require_once $classPath;
$reflection = new ReflectionClass(SvAmazonTenantMigration::class);
foreach (['migrate','verify','preview'] as $method) {
    if (!$reflection->hasMethod($method)) throw new RuntimeException("Migration missing {$method}.");
}

$source = (string)file_get_contents($classPath);
foreach ([
    'information_schema.columns',
    'information_schema.statistics',
    "GET_LOCK('amazon-returns-tenant-migration',30)",
    "RELEASE_LOCK('amazon-returns-tenant-migration')",
    'tenant_id IS NULL',
    'amazon_connection_id IS NULL',
    'cross_tenant_children',
    'case_connection_mismatches',
] as $needle) {
    if (!str_contains($source, $needle)) throw new RuntimeException("Migration missing {$needle}.");
}
if (!str_contains($source, 'SvAmazonReturnsSchema::ensure($db)')) throw new RuntimeException('Migration must bootstrap control-plane schema.');
if (!str_contains($source, 'ALTER TABLE')) throw new RuntimeException('Migration must alter legacy data tables.');
if (!str_contains($source, 'updated_at=updated_at') || !str_contains($source, 'child.updated_at=child.updated_at')) throw new RuntimeException('Migration must preserve historical updated_at timestamps.');
if (!str_contains($source, 'MODIFY `tenant_id` BIGINT UNSIGNED NOT NULL')) throw new RuntimeException('Migration must enforce tenant ownership.');

if (!is_file($scriptPath)) throw new RuntimeException('Tenant migration CLI must exist.');
$script = (string)file_get_contents($scriptPath);
foreach (['--dry-run','--apply','AMAZON_RETURNS_TENANT_SLUG','AMAZON_RETURNS_CONNECTION_KEY','AMAZON_SP_API_REGION','AMAZON_MARKETPLACE_ID','verification'] as $needle) {
    if (!str_contains($script, $needle)) throw new RuntimeException("Migration CLI missing {$needle}.");
}
foreach (['AMAZON_LWA_REFRESH_TOKEN','AMAZON_LWA_CLIENT_SECRET','GMAIL_OAUTH_REFRESH_TOKEN'] as $secret) {
    if (str_contains($script, $secret)) throw new RuntimeException("Migration CLI must not read {$secret}.");
}

echo "amazon-returns-tenant-migration-test: OK\n";
