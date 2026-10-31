<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Runtime.php';

function gmailFailureAssert(bool $condition,string $message):void {
    if (!$condition) throw new RuntimeException($message);
}
$now=new DateTimeImmutable('2026-10-13T11:00:00Z');
$due=['bootstrap','gmail_refund_reconciliation','health'];
$failure=['metadata'=>['status'=>'FAILED'],'observed_at'=>'2026-10-13 10:30:00'];
$throttled=SvAmazonReturnsRuntime::deferFailedGmailReconciliation($due,$failure,$now);
gmailFailureAssert(!in_array('gmail_refund_reconciliation',$throttled,true),'A recent FAILED revision task must not be retried on every daemon tick.');
gmailFailureAssert(in_array('health',$throttled,true),'Unrelated health tasks must still run.');
$stillThrottled=SvAmazonReturnsRuntime::deferFailedGmailReconciliation($due,$failure,$now->modify('+29 minutes 59 seconds'));
gmailFailureAssert(!in_array('gmail_refund_reconciliation',$stillThrottled,true),'Failure backoff must last 3600 seconds.');
$recovered=SvAmazonReturnsRuntime::deferFailedGmailReconciliation($due,$failure,$now->modify('+30 minutes'));
gmailFailureAssert(in_array('gmail_refund_reconciliation',$recovered,true),'An expired failure backoff must automatically retry.');
$healthy=['metadata'=>['status'=>'OK'],'observed_at'=>'2026-10-13 10:59:59'];
gmailFailureAssert(in_array('gmail_refund_reconciliation',SvAmazonReturnsRuntime::deferFailedGmailReconciliation($due,$healthy,$now),true),'An updated code revision must still force immediate reconciliation after prior success.');
gmailFailureAssert(in_array('gmail_refund_reconciliation',SvAmazonReturnsRuntime::deferFailedGmailReconciliation($due,null,$now),true),'Missing task history must not suppress the initial reconciliation.');
gmailFailureAssert(!in_array('gmail_refund_reconciliation',SvAmazonReturnsRuntime::deferFailedGmailReconciliation(['bootstrap','health'],$failure,$now),true),'No reconciliation may be added by throttling.');
echo "gmail-reconciliation-failure-backoff-test: OK\n";
