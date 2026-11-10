<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/StatusBridgeService.php';
function clAssert(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
clAssert(method_exists(SvAmazonReturnsStatusBridgeService::class,'verifiedCompleteClaimListEvidence'),
 'Trusted bridge must validate full-list proof before read-only completion.');
$proof=[
 'host'=>'sellercentral.amazon.com.br','view'=>'all','claim_type'=>'ALL','fulfillment'=>'ALL',
 'date_filter_type'=>'CLAIM_DATE','date_filter_days'=>90,
 'page_size'=>100,'page_start_key'=>0,'range_start'=>1,'range_end'=>39,'total'=>39,
 'rendered'=>39,'parsed_orders'=>39,'parsed_claims'=>39,'unique_claims'=>39,
 'start_date'=>'08/13/2026','end_date'=>'11/11/2026','order_id'=>'701-1111111-1111111',
];
$case=['amazon_order_id'=>'701-1111111-1111111',
 'refund_at'=>'2026-09-09 18:38:59','safe_t_id'=>null,'closed_at'=>null];
$result=['status'=>'NOT_FOUND','reason'=>'OFFICIAL_90_DAY_COMPLETE_LIST_NO_MATCH',
 'submitted'=>false,'external_id'=>null,'evidence'=>[
  'snapshot_sha256'=>hash('sha256','official-snapshot'),
  'claim_list_proof'=>$proof,
 ]];
$now=new DateTimeImmutable('2026-11-10 16:20:00',new DateTimeZone('UTC'));
clAssert(SvAmazonReturnsStatusBridgeService::verifiedCompleteClaimListEvidence($result,$case,$now),
 'Valid complete-window live evidence must acknowledge a read-only scan.');
foreach([
 ['proof'=>['range_end'=>38]],['proof'=>['parsed_orders'=>38]],
 ['proof'=>['claim_type'=>'FBA']],['proof'=>['host'=>'evil.example']],
 ['proof'=>['start_date'=>'10/01/2026']],['case'=>['amazon_order_id'=>'701-2222222-2222222']],
 ['case'=>['refund_at'=>'2026-08-01 00:00:00']],['case'=>['safe_t_id'=>'12345-11111-1111111']],
 ['result'=>['reason'=>'SOME_OTHER_REASON']],['evidence'=>['snapshot_sha256'=>'bad']],
] as $change){
 $bad=$result;$c=$case;
 if(isset($change['proof']))$bad['evidence']['claim_list_proof']=array_replace($proof,$change['proof']);
 if(isset($change['result']))$bad=array_replace($bad,$change['result']);
 if(isset($change['evidence']))$bad['evidence']=array_replace($bad['evidence'],$change['evidence']);
 if(isset($change['case']))$c=array_replace($c,$change['case']);
 clAssert(!SvAmazonReturnsStatusBridgeService::verifiedCompleteClaimListEvidence($bad,$c,$now),
  'Invalid proof must never close discovery: '.json_encode($change));
}
// Refund date unknown/outside the listed window: observation only, never absence.
$wres=$result;$wres['reason']='SAFE_T_DISCOVERY_REFUND_NOT_IN_LISTED_WINDOW';
foreach([null,'2026-05-01 00:00:00','2026-09-09 18:38:59'] as $refund){
 $wc=array_replace($case,['refund_at'=>$refund]);
 clAssert(SvAmazonReturnsStatusBridgeService::verifiedListViewEvidence($wres,$wc,$now),
  'Complete list with refund date '.json_encode($refund).' must close as observation only.');
}
clAssert(!SvAmazonReturnsStatusBridgeService::verifiedCompleteClaimListEvidence($wres,array_replace($case,['refund_at'=>null]),$now),
 'Unknown refund date must never satisfy the no-match (absence) proof.');
foreach([['range_end'=>38],['rendered'=>38],['view'=>'pending'],['host'=>'evil.example'],['start_date'=>'01/01/2026']] as $change){
 $bp=$wres;$bp['evidence']['claim_list_proof']=array_replace($proof,$change);
 clAssert(!SvAmazonReturnsStatusBridgeService::verifiedListViewEvidence($bp,array_replace($case,['refund_at'=>null]),$now),
  'Incomplete list must not close: '.json_encode($change));
}
$source=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/StatusBridgeService.php');
clAssert(str_contains($source,'SAFE_T_DISCOVERY_COMPLETE_LIST_OBSERVED'),
 'A completed read must record a source-backed event, not grant permission to write.');
clAssert(str_contains($source,'SAFE_T_DISCOVERY_NOT_EXHAUSTIVE'),
 'Old unverified not-found results must remain deferred.');
echo "safe-t-complete-list-proof-test: OK\n";
