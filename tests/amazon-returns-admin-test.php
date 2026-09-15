<?php
declare(strict_types=1);
function adAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function source(string $relative):string{$path=__DIR__.'/../'.$relative;if(!is_file($path))throw new RuntimeException('Missing admin file: '.$relative);return(string)file_get_contents($path);}

$files=['admin/amazon-returns/index.php','admin/amazon-returns/intake.php','admin/amazon-returns/api/summary.php','admin/amazon-returns/api/intake.php','admin/amazon-returns/api/case.php'];
foreach($files as $file){
    $src=source($file);
    adAssert(str_contains($src,'AdminAuth.php'),$file.' must use standalone admin auth.');
    adAssert(!str_contains($src,'admin-guard.php'),$file.' cannot use website admin guard.');
}
$intakeApi=source('admin/amazon-returns/api/intake.php');
adAssert(str_contains($intakeApi,'SvAmazonReturnsCsrf::valid'),'Physical intake write must validate standalone CSRF.');
adAssert(str_contains($intakeApi,'amazon_returns_pdo()'),'Physical intake must use standalone DB.');
adAssert(str_contains($intakeApi,'$p->events->append'),'Physical intake must append through the scoped event store.');
adAssert(str_contains($intakeApi,'SvAmazonReturnProjector::project'),'Physical intake must reproject after event append.');
adAssert(str_contains($intakeApi,'$p->evidence->record'),'Physical intake must persist scoped evidence.');
adAssert(str_contains($intakeApi,'tenant-'),'Evidence storage path must include tenant scope.');
adAssert(str_contains($intakeApi,'operation_id'),'Intake must use client operation id for retry idempotency.');
adAssert(str_contains($intakeApi,'WAREHOUSE_PHOTO'),'Discrepancy photos must be protected evidence.');
adAssert(!str_contains($intakeApi,'CARRIER_DELIVERED'),'Intake must not infer physical receipt from carrier state.');

foreach(['admin/amazon-returns/api/case.php','admin/amazon-returns/api/intake.php','admin/amazon-returns/api/summary.php'] as $api){
    $src=source($api);
    adAssert(str_contains($src,'TenantRegistry'),$api.' must resolve tenant context.');
    adAssert(str_contains($src,'TenantPersistence'),$api.' must use scoped persistence.');
    adAssert(!str_contains($src,'EventStore.php'),$api.' must not use the global event store.');
}
$summary=source('admin/amazon-returns/api/summary.php');
foreach(['unclassified','eligible_without_action','expired_without_treatment','credit_without_reconciliation'] as $gate)adAssert(str_contains($summary,$gate),'Summary must expose '.$gate);
adAssert(str_contains($summary,"pending_reviews"),"Summary must expose pending review count.");
foreach(['at_risk','eligible_now','safe_t_submitted','denied','appeal','support','approved_awaiting_credit','recovered','loss'] as $bucket)adAssert(str_contains($summary,$bucket),'Summary bucket '.$bucket);

$caseRepo=source('includes/amazon-returns/CaseRepository.php');
adAssert(str_contains($caseRepo,'$unclassified="('),'Unclassified gate must explicitly group metadata gaps before operational filters.');
adAssert(str_contains($caseRepo,'AND c.closed_at IS NULL'),'Unclassified gate must ignore concluded cases.');
adAssert(str_contains($caseRepo,"c.state IN ('REFUND_DETECTED','AWAITING_RETURN','NO_RETURN','IN_TRANSIT','RECEIVED_DISCREPANT','SAFE_T_ELIGIBLE','SAFE_T_READY','POLICY_REVIEW_REQUIRED','BLOCKED_REVIEW')"),'Unclassified gate must only flag lifecycle states where missing classification can still block routing.');
adAssert(!str_contains($caseRepo,"c.current_action='SAFE_T_APPEAL'"),'Summary SQL must not query derived current_action from the case table.');
adAssert(str_contains($caseRepo,"c.refund_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)"),'Expired appeal gate must ignore refunds older than 90 days.');
adAssert(str_contains($caseRepo,"o.kind='SAFE_T_APPEAL'")&&str_contains($caseRepo,"o.status IN ('PENDING','PROCESSING','SUCCEEDED')"),'Expired appeal gate must ignore appeals already being processed or completed.');
adAssert(str_contains($caseRepo,'$creditMismatch="c.closed_at IS NULL'),'Credit mismatch gate must ignore concluded cases.');
adAssert(str_contains($caseRepo,'$exposure<=0'),'Credit mismatch gate must only flag fully recovered exposure that remains operationally open.');

$intakePage=source('admin/amazon-returns/intake.php');
adAssert(str_contains($intakePage,'SvAmazonReturnsCsrf::token'),'Intake page must generate standalone CSRF token.');
adAssert(str_contains($intakePage,'Registrar devolução recebida'),'Intake task title required.');
adAssert(str_contains($intakePage,'crypto.randomUUID'),'Browser retry identity required.');
adAssert(str_contains($intakePage,'type="file"'),'Discrepancy photo upload required.');
$dashboard=source('admin/amazon-returns/index.php');
adAssert(str_contains($dashboard,'SvAmazonReturnsAdminAuth::requireLogin'),'Dashboard must require standalone login.');
adAssert(str_contains($dashboard,'id="operational-problems"'),'Dashboard must expose the dedicated operational-health section.');
$login=source('login.php');
adAssert(str_contains($login,'SvAmazonReturnsAdminAuth::login'),'Standalone login entrypoint required.');

echo "amazon-returns-admin-test: OK\n";
