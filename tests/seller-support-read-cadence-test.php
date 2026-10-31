<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SellerSupportStatus.php';

function ssrcdSame(mixed $e,mixed $a,string $m):void{if($e!==$a)throw new RuntimeException($m.' expected='.var_export($e,true).' actual='.var_export($a,true));}
function ssrcdAssert(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$caseId=13225;
$supportId='22260120621';
$a=new DateTimeImmutable('2026-09-25 18:00:00',new DateTimeZone('UTC'));
$b=new DateTimeImmutable('2026-09-25 19:59:59',new DateTimeZone('UTC'));
$c=new DateTimeImmutable('2026-09-25 20:00:00',new DateTimeZone('UTC'));
$keyA=SvAmazonSellerSupportStatus::readKey($caseId,$supportId,$a);
$keyB=SvAmazonSellerSupportStatus::readKey($caseId,$supportId,$b);
$keyC=SvAmazonSellerSupportStatus::readKey($caseId,$supportId,$c);
ssrcdSame($keyA,$keyB,'Seller Support reads must deduplicate inside the same 2-hour UTC bucket.');
ssrcdAssert($keyC!==$keyA,'Seller Support active cases must become readable again in the next 2-hour bucket.');
ssrcdAssert($keyA!==SvAmazonSellerSupportStatus::readKey($caseId,$supportId,new DateTimeImmutable('2026-09-25 16:00:00',new DateTimeZone('UTC'))),'Adjacent 2-hour buckets must have distinct idempotency keys.');

echo "seller-support-read-cadence-test: OK\n";
