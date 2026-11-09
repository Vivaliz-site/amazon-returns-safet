<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/TenantContext.php';
require_once __DIR__.'/../includes/amazon-returns/TenantOutbox.php';

function assertDriftReasons(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class UiDriftReasonPdo extends PDO
{
    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        assertDriftReasons(
            str_contains($query, "LEFT(last_error,9)='UI_DRIFT:'"),
            'Diagnostic query must remain scoped to UI drift.'
        );
        assertDriftReasons(str_contains($query, 'tenant_id=:tenant_id'), 'Missing tenant scope.');
        assertDriftReasons(str_contains($query, 'amazon_connection_id=:amazon_connection_id'), 'Missing connection scope.');
        return new UiDriftReasonStatement();
    }
}

final class UiDriftReasonStatement extends PDOStatement
{
    public function __construct() {}

    public function execute(?array $params = null): bool
    {
        assertDriftReasons(($params[':tenant_id'] ?? null) === 1, 'Wrong tenant scope.');
        assertDriftReasons(($params[':amazon_connection_id'] ?? null) === 1, 'Wrong connection scope.');
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [
            'UI_DRIFT: SAFE_T_QUANTITY_INPUT_MISSING',
            'UI_DRIFT: SAFE_T_EXACT_SEARCH_CONTROL_UNAVAILABLE',
            'UI_DRIFT: SAFE_T_EXACT_SEARCH_ID_UNREADABLE',
            'UI_DRIFT: SAFE_T_EXACT_SEARCH_ABSENCE_UNVERIFIED',
            'UI_DRIFT: SAFE_T_EXACT_SEARCH_ABSENCE_UNVERIFIED',
            'UI_DRIFT: SAFE_T_DISCOVERY_LIST_COVERAGE_UNVERIFIED',
            'UI_DRIFT: SAFE_T_DISCOVERY_PAGE_LIMIT_REACHED',
            'UI_DRIFT: UNEXPECTED_FUTURE_REASON',
        ];
    }
}

$outbox = new SvAmazonTenantReturnsOutbox(new UiDriftReasonPdo(), new SvAmazonTenantContext(1, 1));
$expected = [
    'SAFE_T_QUANTITY_INPUT_MISSING',
    'SAFE_T_EXACT_SEARCH_CONTROL_UNAVAILABLE',
    'SAFE_T_EXACT_SEARCH_ID_UNREADABLE',
    'SAFE_T_EXACT_SEARCH_ABSENCE_UNVERIFIED',
    'SAFE_T_DISCOVERY_LIST_COVERAGE_UNVERIFIED',
    'SAFE_T_DISCOVERY_PAGE_LIMIT_REACHED',
    'OTHER',
];
$actual = $outbox->pendingUiDriftReasonCodes();
assertDriftReasons($actual === $expected, 'Known reasons must be visible and unknown reasons fail closed, actual='.json_encode($actual));

$submitWorker = (string) file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
assertDriftReasons(str_contains($submitWorker, "reason: 'SAFE_T_QUANTITY_INPUT_MISSING'"), 'Missing submit-worker reason contract.');
$discovery = (string) file_get_contents(__DIR__.'/../scripts/amazon-returns/safe-t-discovery-exact-search.mjs');
foreach (array_slice($expected, 1, 3) as $code) {
    assertDriftReasons(str_contains($discovery, "reason: '".$code."'"), 'Missing discovery reason contract: '.$code);
}

$discoveryPages = (string)file_get_contents(__DIR__.'/../scripts/amazon-returns/safe-t-discovery-pages.mjs');
assertDriftReasons(str_contains($discoveryPages,'SAFE_T_DISCOVERY_LIST_COVERAGE_UNVERIFIED'), 'Missing coverage diagnostic in official SAFE-T scanner.');
assertDriftReasons(str_contains($discoveryPages,'SAFE_T_DISCOVERY_PAGE_LIMIT_REACHED'), 'Missing page-limit diagnostic in official SAFE-T scanner.');
assertDriftReasons(str_contains($actual[0] ?? '','SAFE_T_'), 'Unrecognized errors must remain a distinct OTHER fallback.');

echo "safe-t-ui-drift-reason-codes-test: OK\n";
