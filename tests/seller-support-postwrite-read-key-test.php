<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SellerSupportStatus.php';

function ssprSame(mixed $e,mixed $a,string $m):void{if($e!==$a)throw new RuntimeException($m.' expected='.var_export($e,true).' actual='.var_export($a,true));}
function ssprAssert(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$now=new DateTimeImmutable('2026-09-19 18:00:00',new DateTimeZone('UTC'));
$caseId=13231;
$supportId='22144700811';
$daily=SvAmazonSellerSupportStatus::readKey($caseId,$supportId,$now);
$before=[
    ['id'=>10,'case_id'=>$caseId,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL','occurred_at'=>'2026-09-19 12:00:00','payload'=>['case_id'=>$supportId,'case_status'=>'RESOLVED','latest_text'=>'Caso encerrado.']],
];
ssprSame($daily,SvAmazonSellerSupportStatus::readKeyForTimeline($caseId,$supportId,$now,$before),'Without a newer support write the normal daily read key must remain stable.');

$afterWrite=$before;
$afterWrite[]=['id'=>11,'case_id'=>$caseId,'event_type'=>'SELLER_CENTRAL_ACTION_RESULT','source'=>'SELLER_CENTRAL','occurred_at'=>'2026-09-19 13:42:53','payload'=>['action'=>'SELLER_SUPPORT_OPEN','status'=>'ACCEPTED','external_id'=>$supportId,'submitted'=>true]];
$postWrite=SvAmazonSellerSupportStatus::readKeyForTimeline($caseId,$supportId,$now,$afterWrite);
ssprAssert($postWrite!==$daily,'A successful support write after the last observation must force a distinct same-day readback key.');
ssprSame($postWrite,SvAmazonSellerSupportStatus::readKeyForTimeline($caseId,$supportId,$now,$afterWrite),'Post-write readback key must be deterministic.');

$afterRead=$afterWrite;
$afterRead[]=['id'=>12,'case_id'=>$caseId,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL','occurred_at'=>'2026-09-19 13:50:00','payload'=>['case_id'=>$supportId,'case_status'=>'PENDINGAMAZONACTION','latest_text'=>'Caso reaberto.']];
ssprSame($daily,SvAmazonSellerSupportStatus::readKeyForTimeline($caseId,$supportId,$now,$afterRead),'Once post-write status is observed, cadence must return to the daily key.');

echo "seller-support-postwrite-read-key-test: OK\n";
