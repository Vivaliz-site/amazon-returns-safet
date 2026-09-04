#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap-env.php';
require_once dirname(__DIR__) . '/includes/Database.php';
require_once dirname(__DIR__) . '/includes/amazon-returns/TenantMigration.php';

function migration_env(string $name, bool $required = true): ?string
{
    $value = getenv($name);
    $value = is_string($value) ? trim($value) : '';
    if ($value === '' && $required) throw new RuntimeException("Missing required migration identity: {$name}.");
    return $value === '' ? null : $value;
}

function migration_mode(array $arguments): string
{
    $allowed = ['--dry-run','--apply'];
    foreach ($arguments as $argument) {
        if (!in_array($argument, $allowed, true)) throw new InvalidArgumentException('Usage: migrate-single-tenant-to-multitenant.php [--dry-run|--apply]');
    }
    if (in_array('--dry-run', $arguments, true) && in_array('--apply', $arguments, true)) {
        throw new InvalidArgumentException('Choose either --dry-run or --apply.');
    }
    return in_array('--apply', $arguments, true) ? 'apply' : 'dry-run';
}
function migration_identity(): array
{
    return [
        'tenant_slug'=>migration_env('AMAZON_RETURNS_TENANT_SLUG'),
        'tenant_name'=>migration_env('AMAZON_RETURNS_TENANT_NAME'),
        'connection_key'=>migration_env('AMAZON_RETURNS_CONNECTION_KEY'),
        'connection_label'=>migration_env('AMAZON_RETURNS_CONNECTION_LABEL'),
        'selling_partner_id'=>migration_env('AMAZON_SELLING_PARTNER_ID', false),
        'region'=>migration_env('AMAZON_SP_API_REGION'),
        'marketplace_id'=>migration_env('AMAZON_MARKETPLACE_ID'),
    ];
}

try {
    $mode = migration_mode(array_slice($argv, 1));
    $identity = migration_identity();
    $db = amazon_returns_require_pdo();

    if ($mode === 'dry-run') {
        echo json_encode([
            'status'=>'OK',
            'mode'=>'dry-run',
            'preview'=>SvAmazonTenantMigration::preview($db, $identity),
            'verification'=>'NOT_RUN_NO_MUTATION',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }

    $context = SvAmazonTenantMigration::migrate($db, $identity);
    $verification = SvAmazonTenantMigration::verify($db, $context);
    if (($verification['valid'] ?? false) !== true) throw new RuntimeException('Post-migration verification failed.');

    echo json_encode([
        'status'=>'OK',
        'mode'=>'apply',
        'tenant_id'=>$context->tenantId(),
        'amazon_connection_id'=>$context->amazonConnectionId(),
        'verification'=>$verification,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'status'=>'FAILED',
        'error_class'=>$error::class,
        'reason'=>'TENANT_MIGRATION_ABORTED',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
