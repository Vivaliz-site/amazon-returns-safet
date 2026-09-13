<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/TenantContext.php';
require_once __DIR__ . '/../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__ . '/../includes/amazon-returns/GmailEventSink.php';
require_once __DIR__ . '/../includes/amazon-returns/SpApiEventSink.php';
require_once __DIR__ . '/../includes/amazon-returns/ReturnsReport.php';
require_once __DIR__ . '/../includes/amazon-returns/Projector.php';
require_once __DIR__ . '/../workers/amazon-returns/gmail-ingest.php';
require_once __DIR__ . '/../workers/amazon-returns/scheduler.php';

function tpAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class TenantPersistenceMemoryPdo extends PDO
{
    public function __construct() {}
}

$db = new TenantPersistenceMemoryPdo();
$context = new SvAmazonTenantContext(7, 19, 23);
$persistence = SvAmazonTenantPersistence::create($db, $context);

tpAssert($persistence->db() === $db, 'Persistence must expose the bound PDO instance.');
tpAssert($persistence->context() === $context, 'Persistence must expose the immutable tenant context.');
tpAssert($persistence->cases instanceof SvAmazonReturnCaseRepository, 'Case repository missing.');
tpAssert($persistence->events instanceof SvAmazonTenantReturnEventStore, 'Event store missing.');
tpAssert($persistence->evidence instanceof SvAmazonReturnEvidenceStore, 'Evidence store missing.');
tpAssert($persistence->outbox instanceof SvAmazonTenantReturnsOutbox, 'Outbox missing.');
tpAssert($persistence->cursors instanceof SvAmazonSourceCursorStore, 'Cursor store missing.');
tpAssert($persistence->policies instanceof SvAmazonReturnPolicyRepository, 'Policy repository missing.');
tpAssert(property_exists($persistence,'erpSalesReturns'), 'ERP sales return repository binding missing.');
tpAssert($persistence->erpSalesReturns instanceof SvAmazonErpSalesReturnRepository, 'ERP sales return repository missing.');

$contracts = [
    [SvAmazonGmailEventSink::class, 'persist', 0, 'SvAmazonTenantPersistence'],
    [SvAmazonSpApiEventSink::class, 'persist', 0, 'SvAmazonTenantPersistence'],
    [SvAmazonSpApiEventSink::class, 'persistReturnsReportRow', 0, 'SvAmazonTenantPersistence'],
    [SvAmazonReturnsReport::class, 'persistRows', 0, 'SvAmazonTenantPersistence'],
    [SvAmazonReturnsReport::class, 'loadCursor', 0, 'SvAmazonTenantPersistence'],
    [SvAmazonReturnProjector::class, 'project', 0, 'SvAmazonReturnCaseRepository'],
    [SvAmazonReturnProjector::class, 'project', 1, 'SvAmazonTenantReturnEventStore'],
    [SvAmazonGmailIngestor::class, 'saveCursor', 0, 'SvAmazonSourceCursorStore'],
    [SvAmazonReturnsScheduler::class, 'schedule', 0, 'SvAmazonTenantReturnsOutbox'],
];
foreach ($contracts as [$class,$method,$parameter,$expectedType]) {
    $reflection = new ReflectionMethod($class, $method);
    $type = (string)$reflection->getParameters()[$parameter]->getType();
    tpAssert(str_contains($type, $expectedType), "{$class}::{$method} is missing {$expectedType}.");
}

foreach ([
    'includes/amazon-returns/GmailEventSink.php'=>'$p->events->append',
    'includes/amazon-returns/SpApiEventSink.php'=>'$p->events->append',
    'includes/amazon-returns/ReturnsReport.php'=>'persistRowsScoped',
    'includes/amazon-returns/Projector.php'=>'writeProjectionScoped',
] as $relative=>$needle) {
    $source = (string)file_get_contents(__DIR__ . '/../' . $relative);
    tpAssert(str_contains($source, $needle), $relative . ' lacks its tenant-scoped path.');
}

echo "amazon-returns-tenant-persistence-test: OK\n";
