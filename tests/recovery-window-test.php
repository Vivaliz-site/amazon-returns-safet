<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/RecoveryWindow.php';
function rwSame(mixed $expected,mixed $actual,string $message):void{if($expected!==$actual){throw new RuntimeException($message." expected=".var_export($expected,true)." actual=".var_export($actual,true));}}
$case=['refund_at'=>'2026-05-10 07:27:57','seller_debit_at'=>'2026-05-11 07:27:57'];
rwSame('2026-08-08 07:27:57',SvAmazonRecoveryWindow::deadlineAt($case)?->format('Y-m-d H:i:s'),'Refund timestamp must be the sole recovery-window basis.');
rwSame(false,SvAmazonRecoveryWindow::expired($case,new DateTimeImmutable('2026-08-08 07:27:56',new DateTimeZone('UTC'))),'Case must remain active until just before refund D+90.');
rwSame(true,SvAmazonRecoveryWindow::expired($case,new DateTimeImmutable('2026-08-08 07:27:57',new DateTimeZone('UTC'))),'Refund D+90 is outside active scope.');
$noRefund=['refund_at'=>null,'seller_debit_at'=>'2026-05-01 07:27:57'];
rwSame(null,SvAmazonRecoveryWindow::deadlineAt($noRefund),'Without refund_at there is no 90-day cutoff.');
rwSame(false,SvAmazonRecoveryWindow::expired($noRefund,new DateTimeImmutable('2026-09-01 00:00:00',new DateTimeZone('UTC'))),'Cases without refund_at remain investigable.');
$deadline=new DateTimeImmutable('2026-08-08 07:27:57',new DateTimeZone('UTC'));
rwSame(null,SvAmazonRecoveryWindow::nextDailyRetryAt(new DateTimeImmutable('2026-08-07 12:00:00',new DateTimeZone('UTC')),$deadline),'No retry may be scheduled to land exactly on D+90.');
rwSame(null,SvAmazonRecoveryWindow::nextDailyRetryAt(new DateTimeImmutable('2026-08-08 07:27:57',new DateTimeZone('UTC')),$deadline),'No retry may be scheduled at D+90.');
$short=array_replace($case,['appeal_deadline_at'=>'2026-06-01T00:00:00Z']);
rwSame('2026-06-01 00:00:00',SvAmazonRecoveryWindow::effectiveDeadlineAt($short,'SAFE_T_APPEAL')?->format('Y-m-d H:i:s'),'Appeal must honor a shorter explicit Amazon deadline, including ISO timestamps.');
rwSame('2026-08-08 07:27:57',SvAmazonRecoveryWindow::effectiveDeadlineAt($short,'SELLER_SUPPORT_OPEN')?->format('Y-m-d H:i:s'),'Seller Support must keep refund D+90 as its outer deadline.');
$late=array_replace($case,['appeal_deadline_at'=>'2026-12-01T00:00:00Z']);
rwSame('2026-08-08 07:27:57',SvAmazonRecoveryWindow::effectiveDeadlineAt($late,'SAFE_T_APPEAL')?->format('Y-m-d H:i:s'),'Explicit appeal deadline must not extend recovery beyond refund D+90.');
echo "recovery-window-test: OK\n";
