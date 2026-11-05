<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/CaseRepository.php';

function assertDiscoveryPagination(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// More than the old 250-row cap; no live database, order or claim is touched.
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE amazon_return_cases (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    amazon_connection_id INTEGER NOT NULL,
    closed_at TEXT,
    safe_t_id TEXT
)');
$insert = $db->prepare('INSERT INTO amazon_return_cases
    (id, tenant_id, amazon_connection_id, closed_at, safe_t_id)
    VALUES (?, ?, ?, ?, ?)');
for ($id = 1; $id <= 311; $id++) {
    $tenant = ($id >= 276 && $id <= 287) ? 2 : 1;
    $connection = ($id >= 288 && $id <= 299) ? 2 : 1;
    $closedAt = ($id >= 300 && $id <= 305) ? '2026-11-01 00:00:00' : null;
    $safeT = $id >= 306 ? 'EXISTING-CLAIM' : null;
    $insert->execute([$id, $tenant, $connection, $closedAt, $safeT]);
}

$repo = new SvAmazonReturnCaseRepository($db, new SvAmazonTenantContext(1, 1));
$cursor = 0;
$seen = [];
$pageSizes = [];
for ($iteration = 0; $iteration < 5; $iteration++) {
    $page = $repo->casesWithoutSafeTId(100, $cursor);
    if ($page === []) break;
    $pageSizes[] = count($page);
    foreach ($page as $case) {
        $id = (int) $case['id'];
        assertDiscoveryPagination($id > $cursor, 'Pagination stalled or repeated a previous page');
        assertDiscoveryPagination(!isset($seen[$id]), 'Duplicate discovery candidate');
        assertDiscoveryPagination((int) $case['tenant_id'] === 1
            && (int) $case['amazon_connection_id'] === 1,
            'Cross-tenant/connection discovery leak');
        $seen[$id] = true;
    }
    $cursor = (int) $page[count($page) - 1]['id'];
}
assertDiscoveryPagination(array_keys($seen) === range(1, 275),
    'Discovery silently omitted eligible higher-ID cases beyond the first 250');
assertDiscoveryPagination($pageSizes === [100, 100, 75],
    'Unexpected keyset page sizes: '.json_encode($pageSizes));
assertDiscoveryPagination($repo->casesWithoutSafeTId(100, 275) === [],
    'Exhausted keyset must yield an empty page');

$service = file_get_contents(__DIR__ . '/../includes/amazon-returns/StatusBridgeService.php');
assertDiscoveryPagination(is_string($service)
    && preg_match('/casesWithoutSafeTId\\(250,\\s*\\$cursor\\)/', $service) === 1,
    'StatusBridge must use the paginated case repository rather than truncate discovery at 250');
echo "safe-t-discovery-pagination-contract-test: OK\n";
