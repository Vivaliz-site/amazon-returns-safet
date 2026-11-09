<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/SafeTExternalWindow.php';

function d90DiscoveryAssert(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException('SAFE_T_D90_DISCOVERY: '.$message);
}

$now=new DateTimeImmutable('2026-11-09 03:00:00',new DateTimeZone('UTC'));
$utc90=new DateTimeImmutable('2026-08-11 03:00:00',new DateTimeZone('UTC'));
$utc90String=$utc90->format('Y-m-d H:i:s');
d90DiscoveryAssert(
    SvAmazonSafeTExternalWindow::discoveryVerificationExpired(['refund_at'=>$utc90String],$now),
    'D+90 exactly must be excluded from new discovery'
);
d90DiscoveryAssert(
    !SvAmazonSafeTExternalWindow::discoveryVerificationExpired(
        ['refund_at'=>'2026-08-11 03:00:01'],$now
    ),
    'one second before D+90 must still be verified'
);
d90DiscoveryAssert(
    SvAmazonSafeTExternalWindow::discoveryVerificationExpired(
        ['refund_at'=>'2026-06-01 00:00:00'],$now
    ),
    'older than 90 days must be excluded'
);
foreach([[],['refund_at'=>null],['refund_at'=>'invalid'],['refund_at'=>'2026-02-31 00:00:00']] as $case){
    d90DiscoveryAssert(!SvAmazonSafeTExternalWindow::discoveryVerificationExpired($case,$now),
        'unknown/invalid refund cannot be assumed older than 90 days');
}
// A linked SAFE-T is not cancelled after 90 days: only new status reads are
// skipped; existing appeals, support claims and financial reconciliation remain.
$service=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/StatusBridgeService.php');
$repo=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/TenantOutbox.php');
d90DiscoveryAssert(
    str_contains($service, "supersedeExpiredDiscoveryJobs('REFUND_D90_DISCOVERY_EXPIRED',\$now)"),
    'old queued discovery must be retired in ensureJobs'
);
d90DiscoveryAssert(
    substr_count($service,'SvAmazonSafeTExternalWindow::discoveryVerificationExpired($case,$now)')===3,
    'both discovery paths and linked SAFE-T status enqueue must enforce D+90'
);
d90DiscoveryAssert(str_contains($service, "'expired_over90'=>0"),
    'refund-reconciliation skip must be visible in result stats');
d90DiscoveryAssert(str_contains($repo, "AND o.kind='SAFE_T_DISCOVERY' AND c.refund_at IS NOT NULL"),
    'bulk supersede must target discovery only, with confirmed refund');
d90DiscoveryAssert(str_contains($service, "supersedeExpiredSafeTReadJobs('REFUND_D90_STATUS_READ_EXPIRED',\$now)"),
    'expired known-claim status reads must be retired without canceling external claims');
d90DiscoveryAssert(str_contains($repo,"AND o.kind='SAFE_T_READ' AND c.refund_at IS NOT NULL"),
    'expiry cleanup must target SAFE_T_READ only with confirmed refund');
d90DiscoveryAssert(str_contains($repo,'AND c.refund_at<=:refund_cutoff'),
    'terminal query must use confirmed UTC D+90');
d90DiscoveryAssert(str_contains($repo,'o.locked_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL '),
    'live processing leases must be preserved');
d90DiscoveryAssert(str_contains($repo,'AND c.tenant_id=o.tenant_id AND c.amazon_connection_id=o.amazon_connection_id'),
    'case join must keep tenant and Amazon connection isolation');
d90DiscoveryAssert(str_contains($repo,"CONCAT('|',LEFT(o.last_error,120))"),
    'original UI-drift diagnosis must remain in historical outbox row');
echo "SAFE_T_D90_DISCOVERY_SKIP=PASS\n";
