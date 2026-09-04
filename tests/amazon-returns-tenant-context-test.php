<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/Config.php';
require_once __DIR__ . '/../includes/amazon-returns/TenantContext.php';
require_once __DIR__ . '/../includes/amazon-returns/TenantRegistry.php';

function tcSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) throw new RuntimeException($message);
}

$context = new SvAmazonTenantContext(7, 11, 13);
tcSame(7, $context->tenantId(), 'Tenant ID must be immutable.');
tcSame(11, $context->amazonConnectionId(), 'Connection ID must be immutable.');
tcSame(13, $context->actorId(), 'Actor ID must be optional and immutable.');
tcSame('tenant:7|connection:11', $context->scopeKey(), 'Scope key must be deterministic.');
$withActor = (new SvAmazonTenantContext(7, 11))->withActor(19);
tcSame(19, $withActor->actorId(), 'withActor must return a context with the actor.');
tcSame('tenant:7|connection:11', $withActor->scopeKey(), 'Actor must not change the storage scope.');

foreach ([[0, 1], [1, 0], [-1, 1], [1, -1]] as [$tenant, $connection]) {
    $thrown = false;
    try { new SvAmazonTenantContext($tenant, $connection); } catch (InvalidArgumentException) { $thrown = true; }
    if (!$thrown) throw new RuntimeException('Invalid tenant context accepted.');
}

$thrown = false;
try { (new SvAmazonTenantContext(1, 1))->withActor(0); } catch (InvalidArgumentException) { $thrown = true; }
if (!$thrown) throw new RuntimeException('Invalid actor accepted.');


final class TenantRegistryMemoryPdo extends PDO
{
    public array|false $row;
    public string $preparedSql = '';
    public array $executedParams = [];
    public function __construct(array|false $row) { $this->row = $row; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->preparedSql = $query;
        return new TenantRegistryMemoryStatement($this);
    }
}

final class TenantRegistryMemoryStatement extends PDOStatement
{
    public function __construct(private TenantRegistryMemoryPdo $db) {}
    public function execute(?array $params = null): bool
    {
        $this->db->executedParams = $params ?? [];
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->db->row;
    }
}

$config = new SvAmazonReturnsConfig([
    'AMAZON_RETURNS_TENANT_SLUG'=>'shopvivaliz',
    'AMAZON_RETURNS_CONNECTION_KEY'=>'amazon-br-primary',
]);
$registryDb = new TenantRegistryMemoryPdo([
    'tenant_id'=>7,
    'tenant_status'=>'ACTIVE',
    'amazon_connection_id'=>11,
    'connection_status'=>'ACTIVE',
]);
$resolved = SvAmazonTenantRegistry::resolveCurrent($registryDb, $config);
tcSame(7, $resolved->tenantId(), 'Registry must resolve the database tenant ID.');
tcSame(11, $resolved->amazonConnectionId(), 'Registry must resolve the database connection ID.');
tcSame([':tenant_slug'=>'shopvivaliz', ':connection_key'=>'amazon-br-primary'], $registryDb->executedParams, 'Registry lookup must use configured logical keys.');
if (!str_contains($registryDb->preparedSql, 'JOIN amazon_return_connections')) throw new RuntimeException('Registry must bind the connection to its tenant.');

foreach ([
    false,
    ['tenant_id'=>7,'tenant_status'=>'DISABLED','amazon_connection_id'=>11,'connection_status'=>'ACTIVE'],
    ['tenant_id'=>7,'tenant_status'=>'ACTIVE','amazon_connection_id'=>11,'connection_status'=>'REVOKED'],
] as $row) {
    $thrown = false;
    try { SvAmazonTenantRegistry::resolveCurrent(new TenantRegistryMemoryPdo($row), $config); }
    catch (RuntimeException) { $thrown = true; }
    if (!$thrown) throw new RuntimeException('Invalid registry row accepted.');
}

$source = (string)file_get_contents(__DIR__ . '/../includes/amazon-returns/TenantRegistry.php');
foreach (['AMAZON_RETURNS_TENANT_ID','AMAZON_RETURNS_CONNECTION_ID'] as $forbidden) {
    if (str_contains($source, $forbidden)) throw new RuntimeException('Registry must not trust numeric IDs from environment.');
}


echo "amazon-returns-tenant-context-test: OK\n";
