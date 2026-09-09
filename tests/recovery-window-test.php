<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/RecoveryWindow.php';
function rwSame(mixed $expected,mixed $actual,string $message):void{if($expected!==$actual){throw new RuntimeException($message." expected=".var_export($expected,true)." actual=".var_export($actual,true));}}
$case=['refund_at'=>'2026-05-10 07:27:57','seller_debit_at'=>'2026-05-11 07:27:57'];
rwSame('2026-08-09 07:27:57',SvAmazonRecoveryWindow::deadlineAt($case)?->format('Y-m-d H:i:s'),'Seller debit must be preferred as recovery basis.');
rwSame(false,SvAmazonRecoveryWindow::expired($case,new DateTimeImmutable('2026-08-09 07:27:57',new DateTimeZone('UTC'))),'D+90 must remain actionable.');
rwSame(true,SvAmazonRecoveryWindow::expired($case,new DateTimeImmutable('2026-08-09 07:27:58',new DateTimeZone('UTC'))),'After D+90 must expire.');
$fallback=['refund_at'=>'2026-05-10 07:27:57','seller_debit_at'=>null];
rwSame('2026-08-08 07:27:57',SvAmazonRecoveryWindow::deadlineAt($fallback)?->format('Y-m-d H:i:s'),'Refund timestamp must be fallback basis.');
$deadline=new DateTimeImmutable('2026-08-09 07:27:57',new DateTimeZone('UTC'));
rwSame('2026-08-09 07:27:57',SvAmazonRecoveryWindow::nextDailyRetryAt(new DateTimeImmutable('2026-08-08 12:00:00',new DateTimeZone('UTC')),$deadline)?->format('Y-m-d H:i:s'),'Final retry may land exactly on D+90.');
rwSame(null,SvAmazonRecoveryWindow::nextDailyRetryAt(new DateTimeImmutable('2026-08-09 07:27:58',new DateTimeZone('UTC')),$deadline),'No retry may be scheduled after D+90.');
echo "recovery-window-test: OK\n";
