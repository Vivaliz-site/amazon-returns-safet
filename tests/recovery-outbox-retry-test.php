<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/TenantOutbox.php';
function rorSame(mixed $expected,mixed $actual,string $message):void{if($expected!==$actual){throw new RuntimeException($message." expected=".var_export($expected,true)." actual=".var_export($actual,true));}}
$now=new DateTimeImmutable('2026-08-07 07:27:57',new DateTimeZone('UTC'));
$retry=SvAmazonTenantReturnsOutbox::retryDecision([
    'attempt_count'=>SvAmazonTenantReturnsOutbox::MAX_ATTEMPTS,
    'payload'=>['deadline_at'=>'2026-08-09 07:27:57'],
],$now);
rorSame('RETRY',$retry['status'],'Transport retry cap must not kill a recovery while its business deadline remains open.');
rorSame('2026-08-08 07:27:57',$retry['next_at']?->format('Y-m-d H:i:s'),'Retries after the transport cap must run once per day.');
rorSame('RECOVERY_WINDOW_RETRY',$retry['reason'],'Extended retries must be explicitly identified.');
$final=SvAmazonTenantReturnsOutbox::retryDecision([
    'attempt_count'=>SvAmazonTenantReturnsOutbox::MAX_ATTEMPTS+1,
    'payload'=>['deadline_at'=>'2026-08-09 07:27:57'],
],new DateTimeImmutable('2026-08-09 07:27:57',new DateTimeZone('UTC')));
rorSame('DEAD_LETTER',$final['status'],'No further retry may be scheduled once the D+90 deadline is reached.');
rorSame('DEADLINE_WOULD_EXPIRE',$final['reason'],'Deadline exhaustion must be explicit.');
$legacy=SvAmazonTenantReturnsOutbox::retryDecision([
    'attempt_count'=>SvAmazonTenantReturnsOutbox::MAX_ATTEMPTS,
    'payload'=>[],
],$now);
rorSame('DEAD_LETTER',$legacy['status'],'Actions without a business deadline must retain the normal retry cap.');
rorSame('MAX_ATTEMPTS_EXHAUSTED',$legacy['reason'],'Legacy retry exhaustion reason must remain unchanged.');
echo "recovery-outbox-retry-test: OK\n";
