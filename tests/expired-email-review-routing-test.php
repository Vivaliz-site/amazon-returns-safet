<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ReturnActionRouter.php';
$case=['id'=>2,'safe_t_id'=>'12472-25597-6629839','program'=>'FBA','physical_status'=>'NOT_RECEIVED','appeal_deadline_at'=>'2026-09-08 17:29:00'];
$timeline=[['case_id'=>2,'source'=>'GMAIL','event_type'=>'SAFE_T_EMAIL_REVIEW_SENT','occurred_at'=>'2026-09-07 10:00:00','payload'=>['safe_t_id'=>'12472-25597-6629839']]];
$policy=['eligible'=>false];
$before=SvAmazonReturnActionRouter::decide($case,$timeline,$policy,new DateTimeImmutable('2026-09-08 16:00:00',new DateTimeZone('UTC')));
$after=SvAmazonReturnActionRouter::decide($case,$timeline,$policy,new DateTimeImmutable('2026-09-09 18:00:00',new DateTimeZone('UTC')));
if(($before['action']??null)!=='WAIT')throw new RuntimeException('pending review must wait before deadline');
if(($after['action']??null)!=='SAFE_T_APPEAL')throw new RuntimeException('expired unanswered review must attempt SAFE-T recovery appeal');
if(($after['reason']??null)!=='EMAIL_REVIEW_DEADLINE_EXPIRED_RECOVERY_ATTEMPT')throw new RuntimeException('expired review must expose deterministic recovery reason');
echo "expired-email-review-routing-test: OK\n";
