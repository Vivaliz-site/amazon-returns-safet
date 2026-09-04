<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/TenantContext.php';
require_once __DIR__ . '/../includes/amazon-returns/TenantEventStore.php';
require_once __DIR__ . '/../includes/amazon-returns/EvidenceStore.php';

function eeAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function eeSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

function duplicateException(): PDOException
{
    $exception = new PDOException('Duplicate scoped key', 23000);
    $exception->errorInfo = ['23000', 1062, 'Duplicate scoped key'];
    return $exception;
}

final class TenantStoreMemoryPdo extends PDO
{
    /** @var list<array<string,mixed>> */
    public array $responses = [];
    /** @var list<array{sql:string,params:array<string,mixed>}> */
    public array $executed = [];
    public string $lastId = '101';

    public function __construct() {}

    /** @param array<string,mixed> $response */
    public function queue(array $response): void
    {
        $this->responses[] = $response;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new TenantStoreMemoryStatement($this, $query, array_shift($this->responses) ?? []);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->lastId;
    }
}

final class TenantStoreMemoryStatement extends PDOStatement
{
    /** @param array<string,mixed> $response */
    public function __construct(
        private TenantStoreMemoryPdo $db,
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
}

$db = new TenantStoreMemoryPdo();
$context = new SvAmazonTenantContext(1, 10);
$events = new SvAmazonTenantReturnEventStore($db, $context);
$evidence = new SvAmazonReturnEvidenceStore($db, $context);

$baseEvent = [
    'case_id'=>42,
    'event_type'=>'REFUND_CONFIRMED',
    'source'=>'SP_API_FINANCES',
    'source_event_id'=>'txn-42',
    'idempotency_key'=>hash('sha256', 'same-across-tenants'),
    'occurred_at'=>'2026-09-04 12:00:00',
    'payload'=>['amount'=>'77.98','currency'=>'BRL'],
    'evidence_sha256'=>hash('sha256', 'event-evidence'),
];
$db->queue(['fetch'=>['id'=>42]]);
$db->queue([]);
$firstEvent = $events->append($baseEvent);
eeSame(101, $firstEvent, 'Event insert must return its generated ID.');

$db->queue(['fetch'=>['id'=>42]]);
$db->queue(['throw'=>duplicateException()]);
$db->queue(['fetch'=>['id'=>101]]);
$duplicateEvent = $events->append(array_replace($baseEvent, ['payload'=>['amount'=>'999.99']]));
eeSame($firstEvent, $duplicateEvent, 'Tenant-local duplicate event must resolve to the original ID.');

$db->queue(['fetch'=>['id'=>42]]);
$db->queue(['rows'=>[[
    'id'=>101,'tenant_id'=>1,'amazon_connection_id'=>10,'case_id'=>42,
    'event_type'=>'REFUND_CONFIRMED','source'=>'SP_API_FINANCES','source_event_id'=>'txn-42',
    'idempotency_key'=>$baseEvent['idempotency_key'],'occurred_at'=>'2026-09-04 12:00:00',
    'payload_json'=>'{"amount":"77.98","currency":"BRL"}',
    'evidence_sha256'=>$baseEvent['evidence_sha256'],'created_at'=>'2026-09-04 12:00:01',
]]]);
$eventRows = $events->eventsForCase(42);
eeSame(1, count($eventRows), 'Event read must return only scoped rows.');
eeSame('77.98', $eventRows[0]['payload']['amount'] ?? null, 'Event payload JSON must decode.');
eeAssert(!array_key_exists('payload_json', $eventRows[0]), 'Raw event payload JSON must not escape the store.');

$baseEvidence = [
    'kind'=>'WAREHOUSE_PHOTO',
    'source'=>'ADMIN_INTAKE',
    'external_id'=>'intake-42',
    'content_sha256'=>hash('sha256', 'same-photo-across-tenants'),
    'storage_ref'=>'case-42/photo.jpg',
    'metadata'=>['mime'=>'image/jpeg','size'=>1234],
    'captured_at'=>'2026-09-04 12:05:00',
];
$db->lastId = '201';
$db->queue(['fetch'=>['id'=>42]]);
$db->queue([]);
$firstEvidence = $evidence->record(42, $baseEvidence);
eeSame(201, $firstEvidence, 'Evidence insert must return its generated ID.');

$db->queue(['fetch'=>['id'=>42]]);
$db->queue(['throw'=>duplicateException()]);
$db->queue(['fetch'=>['id'=>201]]);
$duplicateEvidence = $evidence->record(42, $baseEvidence);
eeSame($firstEvidence, $duplicateEvidence, 'Tenant-local duplicate evidence must resolve to the original ID.');

$db->queue(['fetch'=>['id'=>42]]);
$db->queue(['rows'=>[[
    'id'=>201,'tenant_id'=>1,'amazon_connection_id'=>10,'case_id'=>42,
    'kind'=>'WAREHOUSE_PHOTO','source'=>'ADMIN_INTAKE','external_id'=>'intake-42',
    'content_sha256'=>$baseEvidence['content_sha256'],'storage_ref'=>'case-42/photo.jpg',
    'metadata_json'=>'{"mime":"image/jpeg","size":1234}',
    'captured_at'=>'2026-09-04 12:05:00','created_at'=>'2026-09-04 12:05:01',
]]]);
$evidenceRows = $evidence->forCase(42);
eeSame(1, count($evidenceRows), 'Evidence read must return only scoped rows.');
eeSame('image/jpeg', $evidenceRows[0]['metadata']['mime'] ?? null, 'Evidence metadata JSON must decode.');
eeAssert(!array_key_exists('metadata_json', $evidenceRows[0]), 'Raw evidence metadata JSON must not escape the store.');
$thrown = false;
try { $evidence->record(42, $baseEvidence + ['tenant_id'=>2]); }
catch (InvalidArgumentException) { $thrown = true; }
eeAssert($thrown, 'Evidence store must reject caller-supplied ownership or unknown fields.');

$thrown = false;
try { $events->append($baseEvent + ['tenant_id'=>2]); }
catch (InvalidArgumentException) { $thrown = true; }
eeAssert($thrown, 'Event store must reject caller-supplied ownership or unknown fields.');

$thrown = false;
try { $evidence->record(42, array_replace($baseEvidence, ['metadata'=>['refreshToken'=>'secret']])); }
catch (InvalidArgumentException) { $thrown = true; }
eeAssert($thrown, 'Evidence metadata must reject camelCase secret keys before database access.');

foreach ($db->executed as $execution) {
    eeAssert(str_contains($execution['sql'], 'tenant_id'), 'Every store SQL statement must include tenant_id.');
    eeAssert(str_contains($execution['sql'], 'amazon_connection_id'), 'Every store SQL statement must include amazon_connection_id.');
    eeSame(1, $execution['params'][':tenant_id'] ?? null, 'Every store SQL execution must bind tenant_id.');
    eeSame(10, $execution['params'][':amazon_connection_id'] ?? null, 'Every store SQL execution must bind amazon_connection_id.');
}

foreach (['TenantEventStore.php','EvidenceStore.php'] as $file) {
    $source = (string)file_get_contents(__DIR__ . '/../includes/amazon-returns/' . $file);
    foreach (['tenant_id','amazon_connection_id'] as $needle) {
        eeAssert(str_contains($source, $needle), $file . ' is missing ownership guard ' . $needle . '.');
    }
    eeAssert(!str_contains($source, 'AMAZON_RETURNS_TENANT_ID'), $file . ' must not resolve tenant from environment.');
    eeAssert(!str_contains($source, 'AMAZON_RETURNS_CONNECTION_ID'), $file . ' must not resolve connection from environment.');
}

eeSame(
    hash('sha256', 'finances|txn-42|item-1'),
    SvAmazonTenantReturnEventStore::deterministicKey('finances','txn-42','item-1'),
    'Event deterministic key must remain stable across tenants.'
);

echo "amazon-returns-tenant-event-evidence-test: OK\n";
