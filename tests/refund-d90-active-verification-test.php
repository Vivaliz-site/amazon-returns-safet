<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/RecoveryWindow.php';
require_once __DIR__.'/../includes/amazon-returns/CaseRepository.php';
require_once __DIR__.'/../includes/amazon-returns/ErpSalesReturnRepository.php';
require_once __DIR__.'/../includes/amazon-returns/ErpSalesReturnTask.php';
require_once __DIR__.'/../includes/amazon-returns/TenantOutbox.php';

function d90Assert(bool $pass,string $why): void
{
    if(!$pass)throw new RuntimeException($why);
}

$now=new DateTimeImmutable('2026-11-09 03:00:00',new DateTimeZone('UTC'));
d90Assert(!SvAmazonErpSalesReturnTask::refundWithinVerificationWindow(
    ['refund_at'=>'2026-08-11 02:59:59'],$now
),'Refund older than 90 days must not trigger active ERP verification.');
d90Assert(!SvAmazonErpSalesReturnTask::refundWithinVerificationWindow(
    ['refund_at'=>'2026-08-11 03:00:00'],$now
),'The existing D+90 expiry boundary must stay exclusive.');
d90Assert(SvAmazonErpSalesReturnTask::refundWithinVerificationWindow(
    ['refund_at'=>'2026-08-11 03:00:01'],$now
),'D+90 minus one second must remain active.');
d90Assert(SvAmazonErpSalesReturnTask::refundWithinVerificationWindow(
    ['refund_at'=>null],$now
),'Unknown refund timestamp must never be treated as proof of age.');
d90Assert(SvAmazonErpSalesReturnTask::refundWithinVerificationWindow(
    ['refund_at'=>'invalid'],$now
),'Invalid refund timestamp must remain unresolved, not silently expire.');

final class RefundD90Db extends PDO
{
    /** @var list<string> */
    public array $queries=[];
    public function __construct() {}
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        $this->queries[]=$query;
        return new RefundD90Stmt();
    }
}
final class RefundD90Stmt extends PDOStatement
{
    public function __construct() {}
    public function execute(?array $params=null):bool
    {
        if(!is_array($params))throw new RuntimeException('Tenant-scoped parameters required.');
        d90Assert(isset($params[':tenant_id'],$params[':amazon_connection_id']),
            'Never relax tenant/connection isolation.');
        return true;
    }
    public function fetchColumn(int $column=0):mixed{return 0;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return [];}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return false;}
    public function rowCount():int{return 0;}
}
$db=new RefundD90Db();
$scope=new SvAmazonTenantContext(1,1);
$cases=new SvAmazonReturnCaseRepository($db,$scope);
$cases->casesWithSafeTId(10);
$knownClaimSql=(string)end($db->queries);
d90Assert(str_contains($knownClaimSql,'refund_at>=:refund_cutoff'),
    'Existing SAFE-T claims older than 90 days must not be rescheduled.');
d90Assert(str_contains($knownClaimSql,'refund_at IS NULL'),
    'Claims with unknown refund date must not be excluded.');
d90Assert(!str_contains($knownClaimSql,'closed_at IS NULL'),
    'Recently refunded closed cases with real claims can still be monitored.');

$cases->casesWithoutSafeTId(10);
$discoverySql=(string)end($db->queries);
d90Assert(str_contains($discoverySql,'refund_at>=:refund_cutoff'),
    'Discovery must not re-enqueue D+90 expired refund cases.');
d90Assert(str_contains($discoverySql,'refund_at IS NOT NULL'),
    'Discovery requires a known refund date (owner decision 12/11/2026).');
d90Assert(!str_contains($discoverySql,'refund_at IS NULL'),
    'Cases without a refund date are excluded from discovery.');
d90Assert(str_contains($discoverySql,'closed_at IS NULL'),
    'Closed cases must remain excluded from new discovery.');

$cases->countRefundQuantityUnconfirmed();
$unknownQuantitySql=(string)end($db->queries);
d90Assert(str_contains($unknownQuantitySql,'refund_at>=:refund_cutoff'),
    'Historical refunds must not be an active quantity blocker.');

$erp=new SvAmazonErpSalesReturnRepository($db,$scope);
$erp->countIncomplete();$incompleteSql=(string)end($db->queries);
$erp->incompleteAuditRows();$detailsSql=(string)end($db->queries);
$erp->countStaleWaitingInvoices();$staleSql=(string)end($db->queries);
foreach([$incompleteSql,$detailsSql,$staleSql] as $sql){
    d90Assert(str_contains($sql,'c.refund_at>=:refund_cutoff'),
        'ERP health queries must apply the 90-day verification window.');
    d90Assert(str_contains($sql,'c.refund_at IS NULL'),
        'ERP health must not assume an unknown refund is old.');
    d90Assert(str_contains($sql,'NOT EXISTS (SELECT 1 FROM amazon_return_cases c'),
        'Orphan ERP workflows must not disappear silently.');
    d90Assert(str_contains($sql,'c.amazon_connection_id=amazon_return_erp_sales_returns.amazon_connection_id'),
        'ERP history checks must preserve connection scope.');
}

$outbox=new SvAmazonTenantReturnsOutbox($db,$scope);
d90Assert($outbox->supersedeExpiredRecoveryReads('RECOVERY_WINDOW_EXPIRED_D90')===0,
    'Expired read sweeper should expose an idempotent affected-row count.');
$sweepSql=(string)end($db->queries);
foreach(['o.kind IN (\'SAFE_T_DISCOVERY\',\'SAFE_T_READ\')',
    "c.refund_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)",
    "o.status='PENDING' OR (o.status='PROCESSING'",
    'o.locked_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 300 SECOND)',
    'c.tenant_id=o.tenant_id',
    'c.amazon_connection_id=o.amazon_connection_id'
] as $fragment){
    d90Assert(str_contains($sweepSql,$fragment),'Unsafe D+90 read sweep query: '.$fragment);
}
d90Assert(!str_contains($sweepSql,'SAFE_T_SUBMIT'),'D+90 sweep may never touch external writes.');

$statusSource=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/StatusBridgeService.php');
d90Assert(str_contains($statusSource,"supersedeExpiredRecoveryReads('RECOVERY_WINDOW_EXPIRED_D90')"),
    'Status worker must sweep old jobs before rescheduling.');
d90Assert(str_contains($statusSource,'SvAmazonRecoveryWindow::expired($case,$now)'),
    'Refund discovery after the last claim must ignore confirmed D+90 expiry.');

echo "refund-d90-active-verification-test: OK\n";
