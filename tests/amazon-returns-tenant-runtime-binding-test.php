<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/TenantContext.php';
require_once __DIR__ . '/../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__ . '/../includes/amazon-returns/Runtime.php';
require_once __DIR__ . '/../workers/amazon-returns/daemon.php';

function tbAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class TenantRuntimeMemoryPdo extends PDO
{
    public function __construct() {}
}

$db = new TenantRuntimeMemoryPdo();
$config = new SvAmazonReturnsConfig([
    'AMAZON_RETURNS_RUNTIME_STATE_FILE'=>'/tmp/amazon-returns-runtime.json',
]);
$one = new SvAmazonReturnsDaemon($db, new SvAmazonTenantContext(1, 10), $config);
$two = new SvAmazonReturnsDaemon($db, new SvAmazonTenantContext(2, 20), $config);

tbAssert(str_contains($one->stateFilePath(), 'tenant-1-connection-10'), 'Tenant 1 state file lacks scope.');
tbAssert(str_contains($two->stateFilePath(), 'tenant-2-connection-20'), 'Tenant 2 state file lacks scope.');
tbAssert($one->stateFilePath() !== $two->stateFilePath(), 'Tenant runtime state files must differ.');

$runtime = new ReflectionMethod(SvAmazonReturnsRuntime::class, 'bootstrap');
$type = (string)$runtime->getParameters()[1]->getType();
tbAssert(str_contains($type, 'SvAmazonTenantContext'), 'Runtime bootstrap must require tenant context.');
$health = new ReflectionMethod(SvAmazonReturnsRuntime::class, 'health');
tbAssert(str_contains((string)$health->getParameters()[0]->getType(), 'SvAmazonTenantPersistence'), 'Health must require scoped persistence.');

$entrypoints = [
    'workers/amazon-returns/daemon.php',
    'api/amazon-returns/bridge.php',
    'api/amazon-returns/status-bridge.php',
    'admin/amazon-returns/api/case.php',
    'admin/amazon-returns/api/intake.php',
    'admin/amazon-returns/api/summary.php',
    'scripts/shadow-audit.php',
];
foreach ($entrypoints as $relative) {
    $source = (string)file_get_contents(__DIR__ . '/../' . $relative);
    tbAssert(str_contains($source, 'TenantRegistry') || str_contains($source, 'TenantPersistence'), $relative . ' is not tenant-bound.');
    tbAssert(!str_contains($source, "\$_GET['tenant_id']"), $relative . ' must not trust tenant_id from request.');
    tbAssert(!str_contains($source, "\$input['tenant_id']"), $relative . ' must not trust tenant_id from payload.');
    tbAssert(!str_contains($source, "\$input['amazon_connection_id']"), $relative . ' must not trust connection ownership from payload.');
}

echo "amazon-returns-tenant-runtime-binding-test: OK\n";
