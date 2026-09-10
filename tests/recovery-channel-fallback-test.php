<?php
declare(strict_types=1);
require_once __DIR__.'/../workers/amazon-returns/scheduler.php';
function rcfSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
$now=new DateTimeImmutable('2026-09-07 17:10:00',new DateTimeZone('UTC'));
$case=['id'=>505,'amazon_order_id'=>'702-8373627-4388208','safe_t_id'=>'84164-27415-4505005','refund_at'=>'2026-06-15 08:32:36','seller_debit_at'=>'2026-06-15 08:32:36','appeal_deadline_at'=>'2026-08-29 16:35:00'];
$appeal=['action'=>'SAFE_T_APPEAL','reason'=>'AMAZON_REQUESTED_DATE_REACHED_UNRECOVERED','case_id'=>505,'idempotency_key'=>hash('sha256','appeal')];
$fallback=SvAmazonReturnsScheduler::normalizeRecoveryChannel($case,$appeal,$now);
rcfSame('SELLER_SUPPORT_OPEN',$fallback['action']??null,'Expired appeal channel must fall back to Seller Support while D+90 is open.');
rcfSame('OFFICIAL_APPEAL_WINDOW_EXPIRED_RECOVERY_CONTINUES',$fallback['reason']??null,'Fallback reason must be explicit.');
rcfSame('GENERAL_ORDER_SUPPORT',$fallback['support_route']??null,'Expired appeal recovery must use the general support route.');
$open=$case;$open['support_case_id']='12345678901';$open['support_case_status']='OPEN';
$waiting=SvAmazonReturnsScheduler::normalizeRecoveryChannel($open,$appeal,$now);
rcfSame('WAIT',$waiting['action']??null,'An already active support case must suppress duplicate support creation.');
$before=$case;$before['appeal_deadline_at']='2026-09-08 16:35:00';
rcfSame('SAFE_T_APPEAL',SvAmazonReturnsScheduler::normalizeRecoveryChannel($before,$appeal,$now)['action']??null,'An appeal still inside its explicit deadline must remain an appeal.');
$expired=$case;$expired['seller_debit_at']='2026-06-01 08:32:36';$expired['refund_at']='2026-06-01 08:32:36';
$stopped=SvAmazonReturnsScheduler::normalizeRecoveryChannel($expired,$appeal,$now);
rcfSame('WAIT',$stopped['action']??null,'No channel fallback may create a new recovery write after D+90.');
rcfSame('RECOVERY_WINDOW_EXPIRED',$stopped['reason']??null,'D+90 stop reason must remain explicit.');
echo "recovery-channel-fallback-test: OK\n";
