<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/TenantContext.php';
require_once __DIR__ . '/../includes/amazon-returns/CaseRepository.php';

function crAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function crSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

function crThrows(callable $operation, string $message): void
{
    try { $operation(); } catch (InvalidArgumentException|RuntimeException) { return; }
    throw new RuntimeException($message);
}

final class TenantCaseMemoryPdo extends PDO
{
    /** @var list<array<string,mixed>> */
    public array $responses = [];
    /** @var list<array{sql:string,params:array<string,mixed>}> */
    public array $executed = [];
    public string $lastId = '88';

    public function __construct() {}

    /** @param array<string,mixed> $response */
    public function queue(array $response): void
    {
        $this->responses[] = $response;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $response = array_shift($this->responses) ?? [];
        return new TenantCaseMemoryStatement($this, $query, $response);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->lastId;
    }
}

final class TenantCaseMemoryStatement extends PDOStatement
{
    /** @param array<string,mixed> $response */
    public function __construct(
        private TenantCaseMemoryPdo $db,
        private string $sql,
        private array $response
    ) {}

    public function execute(?array $params = null): bool
    {
        $this->db->executed[] = ['sql'=>$this->sql, 'params'=>$params ?? []];
        if (($this->response['throw'] ?? null) instanceof Throwable) throw $this->response['throw'];
        return (bool)($this->response['execute'] ?? true);
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        return $this->response['fetch'] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return is_array($this->response['rows'] ?? null) ? $this->response['rows'] : [];
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->response['column'] ?? false;
    }

    public function rowCount(): int
    {
        return (int)($this->response['row_count'] ?? 0);
    }
}

$db = new TenantCaseMemoryPdo();
$context = new SvAmazonTenantContext(1, 10);
$repo = new SvAmazonReturnCaseRepository($db, $context);
$owned = ['id'=>41,'tenant_id'=>1,'amazon_connection_id'=>10,'amazon_order_id'=>'702-1111111-2222222'];
$db->queue(['fetch'=>$owned]);
crSame(41, $repo->find(41)['id'] ?? null, 'Owned case must resolve.');
$db->queue(['fetch'=>false]);
crSame(null, $repo->find(42), 'A case outside the scope must not resolve.');

$db->queue(['fetch'=>$owned]);
crSame(41, $repo->findByOrderItem('702-1111111-2222222', 'item-1')['id'] ?? null, 'Order-item lookup must resolve in scope.');

$db->queue(['rows'=>[$owned, ['id'=>43]]]);
crSame(null, $repo->findSingleByOrder('702-1111111-2222222'), 'Ambiguous order lookup must fail closed.');
$db->queue(['rows'=>[$owned]]);
crSame(41, $repo->findSingleByOrder('702-1111111-2222222')['id'] ?? null, 'Single case order lookup must resolve.');

$db->queue(['rows'=>[$owned]]);
crSame([41], array_column($repo->openCases(25), 'id'), 'Open case list must return scoped rows.');
$db->queue(['rows'=>[
    ['amazon_order_id'=>'702-1111111-2222222','id'=>41],
    ['amazon_order_id'=>'702-1111111-2222222','id'=>44],
    ['amazon_order_id'=>'702-3333333-4444444','id'=>45],
]]);
crSame([
    '702-1111111-2222222'=>[41,44],
    '702-3333333-4444444'=>[45],
], $repo->caseIdsForOrders(['702-3333333-4444444','702-1111111-2222222']), 'Case IDs must remain grouped by order.');

$db->queue(['column'=>'2']);
crSame(2, $repo->countOpen(), 'Open case count must normalize to integer.');
$db->queue(['column'=>'2026-06-01 10:00:00']);
crSame('2026-06-01 10:00:00', $repo->earliestObservedDate(), 'Earliest case date must remain exact.');

$db->queue(['rows'=>[$owned, ['id'=>44,'tenant_id'=>1,'amazon_connection_id'=>10,'amazon_order_id'=>'702-1111111-2222222','amazon_order_item_id'=>'item-2']]]);
crSame([41,44], array_column($repo->forOrder('702-1111111-2222222'), 'id'), 'Order lookup must return all rows only in the bound scope.');
$db->queue(['column'=>'A2Q3Y263D00KWC']);
crSame('A2Q3Y263D00KWC', $repo->marketplaceId(), 'Repository must resolve the marketplace from the bound connection.');
$db->queue(['row_count'=>1]);
$db->queue(['fetch'=>['id'=>46,'tenant_id'=>1,'amazon_connection_id'=>10,'amazon_order_id'=>'702-1111111-2222222','amazon_order_item_id'=>'item-resolved']]);
crSame(46, $repo->resolvePlaceholder('702-1111111-2222222','UNRESOLVED_EMAIL','item-resolved'), 'Placeholder resolution must remain scoped and return the resolved ID.');

$base = [
    'amazon_order_id'=>'702-5555555-6666666',
    'amazon_order_item_id'=>'item-55',
    'marketplace_id'=>'A2Q3Y263D00KWC',
    'sku'=>'SKU-55',
    'asin'=>'B000TEST55',
    'quantity_ordered'=>1,
    'program'=>'STANDARD',
    'refund_initiator'=>'UNKNOWN',
    'physical_status'=>'NOT_RECEIVED',
    'state'=>'POLICY_REVIEW_REQUIRED',
];
$db->queue(['row_count'=>1]);
crSame(88, $repo->insert($base), 'Insert must return the generated ID.');
$insertExecution = $db->executed[array_key_last($db->executed)];
crSame(1, $insertExecution['params'][':tenant_id'] ?? null, 'Insert must inject tenant ownership.');
crSame(10, $insertExecution['params'][':amazon_connection_id'] ?? null, 'Insert must inject connection ownership.');

$db->queue(['row_count'=>1]);
$db->queue(['fetch'=>['id'=>89]]);
crSame(89, $repo->upsertOrderItem($base + ['program'=>'DELIVERY_BY_AMAZON']), 'Upsert must read back the scoped case ID.');

$db->queue(['fetch'=>$owned]);
$db->queue(['row_count'=>1]);
$repo->update(41, ['state'=>'SAFE_T_ELIGIBLE','next_action_at'=>'2026-09-05 12:00:00']);

$db->queue(['fetch'=>$owned]);
$repo->assertOwned(41);
$db->queue(['fetch'=>false]);
crThrows(fn()=>$repo->assertOwned(99), 'Missing owned case must be rejected.');
crThrows(fn()=>$repo->find(0), 'Non-positive case ID must be rejected.');
crThrows(fn()=>$repo->insert($base + ['tenant_id'=>2]), 'Caller-supplied tenant ownership must be rejected.');
crThrows(fn()=>$repo->upsertOrderItem($base + ['amazon_connection_id'=>20]), 'Caller-supplied connection ownership must be rejected.');
crThrows(fn()=>$repo->update(41, []), 'Empty patch must be rejected.');
crThrows(fn()=>$repo->update(41, ['tenant_id'=>2]), 'Tenant ownership patch must be rejected.');
crThrows(fn()=>$repo->update(41, ['made_up_field'=>'x']), 'Unknown patch field must be rejected.');

foreach ($db->executed as $execution) {
    $sql = $execution['sql'];
    crAssert(str_contains($sql, 'tenant_id'), 'Every case SQL statement must include tenant_id.');
    crAssert(str_contains($sql, 'amazon_connection_id'), 'Every case SQL statement must include amazon_connection_id.');
    crSame(1, $execution['params'][':tenant_id'] ?? null, 'Every case SQL execution must bind tenant_id.');
    crSame(10, $execution['params'][':amazon_connection_id'] ?? null, 'Every case SQL execution must bind amazon_connection_id.');
}

$source = (string)file_get_contents(__DIR__ . '/../includes/amazon-returns/CaseRepository.php');
foreach (['tenant_id','amazon_connection_id','PATCHABLE','FOR UPDATE'] as $needle) {
    crAssert(str_contains($source, $needle), 'Repository source missing structural guard: ' . $needle);
}

crAssert(!str_contains($source, 'AMAZON_RETURNS_TENANT_ID'), 'Repository must not resolve ownership from environment.');
crAssert(!str_contains($source, 'AMAZON_RETURNS_CONNECTION_ID'), 'Repository must not resolve ownership from environment.');

echo "amazon-returns-tenant-case-repository-test: OK\n";
