<?php
declare(strict_types=1);
require_once __DIR__.'/amazon-returns-tenant-runtime-repositories-test.php';
require_once __DIR__.'/../includes/amazon-returns/FinancialRefresh.php';
function fsrSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
$db=new TenantRuntimeRepoPdo();
$p=SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(3,30));
$retry=['702-0000001-1111111','702-0000002-2222222'];
$meta=['has_more'=>false,'cycle_attempted'=>317,'cycle_failures'=>2,'initial_scan_complete'=>true,'retry_order_ids'=>$retry];
$db->push(['fetch'=>['cursor_value'=>'702-9999999-9999999','metadata_json'=>json_encode($meta),'observed_at'=>null]]);
$batch=SvAmazonFinancialRefresh::nextBatch($p,25);
fsrSame($retry,$batch['order_ids']??null,'Completed rotation with failures must retry only failed orders.');
fsrSame(true,$batch['retry_only']??false,'Failed-order batch must be marked retry-only.');
fsrSame('702-9999999-9999999',$batch['rotation_cursor']??null,'Retry-only batch must preserve full-rotation cursor.');
$db->push([]);
$after=SvAmazonFinancialRefresh::recordAttempted($p,$batch,1,[$retry[1]]);
fsrSame([$retry[1]],$after['retry_order_ids']??null,'Successful failed order must leave retry queue.');
fsrSame(1,$after['cycle_failures']??null,'Retry queue size must drive cycle failures.');
$save=end($db->executed);
fsrSame('702-9999999-9999999',$save['params'][':cursor_value']??null,'Retry-only save must not rewind rotation cursor.');
$db->push(['fetch'=>['cursor_value'=>'702-9999999-9999999','metadata_json'=>json_encode($after),'observed_at'=>null]]);
$second=SvAmazonFinancialRefresh::nextBatch($p,25);
fsrSame([$retry[1]],$second['order_ids']??null,'Second retry must stay bounded to remaining failed order.');
$db->push([]);
$done=SvAmazonFinancialRefresh::recordAttempted($p,$second,0,[]);
fsrSame([],$done['retry_order_ids']??null,'Successful selective retry must clear retry queue.');
fsrSame(0,$done['cycle_failures']??null,'Successful selective retry must clear cycle failure count.');
fsrSame(true,$done['initial_scan_complete']??null,'Successful selective retry must preserve initial scan completion.');
echo "financial-selective-retry-test: OK\n";
