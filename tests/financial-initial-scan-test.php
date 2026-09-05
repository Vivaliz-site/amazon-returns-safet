<?php
declare(strict_types=1);
require_once __DIR__.'/amazon-returns-tenant-runtime-repositories-test.php';
require_once __DIR__.'/../includes/amazon-returns/FinancialRefresh.php';
foreach(['initialScanComplete','requiresInitialRefresh','schedule','canReconcile'] as $method){if(!method_exists(SvAmazonFinancialRefresh::class,$method)){fwrite(STDERR,"Missing initial financial scan gate: $method\n");exit(1);}}
function fisSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
$db=new TenantRuntimeRepoPdo();$p=SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(3,30));
$ids=[];for($i=1;$i<=39;$i++)$ids[]=sprintf('702-%07d-3333333',$i);
$rows=static fn(array $v):array=>array_map(static fn(string $id):array=>['amazon_order_id'=>$id],$v);
$saved=static fn(string $id,array $meta):array=>['fetch'=>['cursor_value'=>$id,'metadata_json'=>json_encode($meta),'observed_at'=>null]];
$db->push(['fetch'=>false]);fisSame(true,SvAmazonFinancialRefresh::requiresInitialRefresh($p),'fresh deployment requires financial scan');
$db->push(['fetch'=>false]);fisSame(false,SvAmazonFinancialRefresh::initialScanComplete($p),'no cursor is not proof of complete scan');
fisSame(['bootstrap','sp_api','financial'],SvAmazonFinancialRefresh::schedule(['bootstrap'],true),'initial scan runs before credit projection');
fisSame(['bootstrap','sp_api','financial'],SvAmazonFinancialRefresh::schedule(['bootstrap','financial','sp_api'],false),'refresh must precede reconciliation');
fisSame(['bootstrap'],SvAmazonFinancialRefresh::schedule(['bootstrap'],false),'completed scan respects normal cadences');
fisSame(false,SvAmazonFinancialRefresh::canReconcile(['status'=>'PARTIAL'],true),'partial refresh cannot authorize closure');
fisSame(false,SvAmazonFinancialRefresh::canReconcile(['status'=>'OK'],false),'first incomplete page cannot authorize closure');
fisSame(true,SvAmazonFinancialRefresh::canReconcile(['status'=>'OK'],true),'complete successful scan permits projection');
$db->push(['fetch'=>false]);$db->push(['rows'=>$rows(array_slice($ids,0,26))]);
$first=SvAmazonFinancialRefresh::nextBatch($p,25);fisSame(true,$first['has_more'],'lookahead detects remaining pages');
$db->push([]);$meta=SvAmazonFinancialRefresh::recordAttempted($p,$first,0);
fisSame(false,$meta['initial_scan_complete'],'25 of39 is incomplete');fisSame(25,$meta['cycle_attempted'],'first batch coverage');
$db->push($saved($ids[24],$meta));fisSame(true,SvAmazonFinancialRefresh::requiresInitialRefresh($p),'continue bootstrap without waiting a normal interval');
$db->push($saved($ids[24],$meta));$db->push(['rows'=>$rows(array_slice($ids,25))]);
$last=SvAmazonFinancialRefresh::nextBatch($p,25);$db->push([]);$complete=SvAmazonFinancialRefresh::recordAttempted($p,$last,0);
fisSame(true,$complete['initial_scan_complete'],'39 successful distinct order attempts completes the first scan');
fisSame(39,$complete['cycle_attempted'],'complete batch coverage');
$db->push($saved($ids[38],$complete));fisSame(false,SvAmazonFinancialRefresh::requiresInitialRefresh($p),'completed bootstrap must not spin');
$db->push([]);$failed=SvAmazonFinancialRefresh::recordAttempted($p,$last,2);
fisSame(false,$failed['initial_scan_complete'],'failed final page cannot mark scan complete');
$db->push($saved($ids[38],$failed));fisSame(false,SvAmazonFinancialRefresh::requiresInitialRefresh($p),'API failures defer retry to normal cadence, not a tight loop');
$db->push($saved($ids[38],$failed));$db->push(['rows'=>[]]);$db->push(['rows'=>$rows(array_slice($ids,0,26))]);
$retry=SvAmazonFinancialRefresh::nextBatch($p,25);fisSame(0,$retry['cycle_failures'],'next full cycle resets old failures');
$db->push(['fetch'=>false]);$db->push(['rows'=>[]]);$empty=SvAmazonFinancialRefresh::nextBatch($p,25);$db->push([]);$done=SvAmazonFinancialRefresh::recordAttempted($p,$empty,0);
fisSame(true,$done['initial_scan_complete'],'empty eligible dataset completes without a spin');
$write=end($db->executed);fisSame('0',$write['params'][':cursor_value'],'empty dataset uses a valid start sentinel');
echo "financial-initial-scan-test: OK\n";
