<?php
declare(strict_types=1);
require_once __DIR__.'/amazon-returns-tenant-runtime-repositories-test.php';
$file=__DIR__.'/../includes/amazon-returns/FinancialRefresh.php';
if(!is_file($file) || !method_exists(SvAmazonReturnCaseRepository::class,'financialOrderIdsAfter')){
 fwrite(STDERR,"Missing fair financial rotation including recovered cases\n");exit(1);
}
require_once $file;
function frcSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
$db=new TenantRuntimeRepoPdo();$p=SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(3,30));
$ids=[];for($i=1;$i<=39;$i++)$ids[]=sprintf('702-%07d-2222222',$i);
$rows=static fn(array $v):array=>array_map(static fn(string $id):array=>['amazon_order_id'=>$id],$v);
$db->push(['fetch'=>false]);$db->push(['rows'=>$rows(array_slice($ids,0,25))]);
$first=SvAmazonFinancialRefresh::nextBatch($p,25);
frcSame(array_slice($ids,0,25),$first['order_ids'],'first batch');
$query=end($db->executed);
frcSame(3,$query['params'][':tenant_id'],'tenant scope');frcSame(30,$query['params'][':amazon_connection_id'],'connection scope');
frcSame(true,str_contains($query['sql'],'expected_reimbursement_amount>0'),'recovered cases still need reversal observation');
frcSame(false,str_contains($query['sql'],'WHERE closed_at IS NULL AND'),'closed cases cannot be globally excluded');
$db->push([]);SvAmazonFinancialRefresh::recordAttempted($p,$first,0);
$save=end($db->executed);frcSame($ids[24],$save['params'][':cursor_value'],'cursor advances to attempted last order');
$db->push(['fetch'=>['cursor_value'=>$ids[24],'metadata_json'=>null,'observed_at'=>null]]);
$db->push(['rows'=>$rows(array_slice($ids,25))]);
$second=SvAmazonFinancialRefresh::nextBatch($p,25);
frcSame(array_slice($ids,25),$second['order_ids'],'remaining 14 orders must be refreshed');
$query=end($db->executed);frcSame($ids[24],$query['params'][':after_order_id'],'keyset cursor must bind the previous endpoint');
$db->push([]);SvAmazonFinancialRefresh::recordAttempted($p,$second,2);
$save=end($db->executed);frcSame($ids[38],$save['params'][':cursor_value'],'failed orders must not permanently starve later orders');
$db->push(['fetch'=>['cursor_value'=>$ids[38],'metadata_json'=>null,'observed_at'=>null]]);
$db->push(['rows'=>[]]);$db->push(['rows'=>$rows(array_slice($ids,0,25))]);
$wrapped=SvAmazonFinancialRefresh::nextBatch($p,25);
frcSame(true,$wrapped['wrapped'],'after final order rotation wraps');
frcSame(array_slice($ids,0,25),$wrapped['order_ids'],'next cycle retries earlier failures');
$before=count($db->executed);SvAmazonFinancialRefresh::recordAttempted($p,['order_ids'=>[]],0);
$write=end($db->executed);frcSame('0',$write['params'][':cursor_value'],'empty batch uses a valid sentinel, never an empty cursor');
echo "financial-refresh-coverage-test: OK\n";
