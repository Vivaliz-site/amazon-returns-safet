<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SpApi.php';
require_once __DIR__.'/../includes/amazon-returns/SpApiEventSink.php';
$errors=[];
function fovEq(mixed $want,mixed $got,string $label):void{global $errors;if($want!==$got)$errors[]=$label.' expected='.json_encode($want).' actual='.json_encode($got);}
$method=method_exists(SvAmazonSpApiEventSink::class,'financialObservationKey');
fovEq(true,$method,'financial changes need versioned immutable identities');
if($method){
 $a=['transaction_id'=>'mutable','transaction_status'=>'RELEASED','total_amount'=>['amount'=>'100.00','currency'=>'BRL']];$b=$a;$b['transaction_status']='DEFERRED';
 fovEq(false,SvAmazonSpApiEventSink::financialObservationKey(1,$a)===SvAmazonSpApiEventSink::financialObservationKey(1,$b),'changed status must append new evidence');
 fovEq(true,SvAmazonSpApiEventSink::financialObservationKey(1,$a)===SvAmazonSpApiEventSink::financialObservationKey(1,array_reverse($a,true)),'field order is not a financial change');
 fovEq(false,SvAmazonSpApiEventSink::financialObservationKey(1,$a,1)===SvAmazonSpApiEventSink::financialObservationKey(1,$a,3),'A B A recurrence must not reuse old A identity');
 fovEq(false,SvAmazonSpApiEventSink::financialObservationKey(1,$a)===SvAmazonSpApiEventSink::financialObservationKey(2,$a),'observation identity is case bound');
}
final class FovClient {
 public function marketplaceId():string{return 'A2Q3Y263D00KWC';}
 public function request(string $method,string $path,array $query=[]):array{return ['status'=>200,'request_id'=>'fixture','data'=>['payload'=>['transactions'=>[[
 'transactionId'=>'official-format','transactionType'=>'SAFE_T_REIMBURSEMENT','transactionStatus'=>'RELEASED','totalAmount'=>['currencyAmount'=>'100.00','currencyCode'=>'BRL'],
 'relatedIdentifiers'=>[['relatedIdentifierName'=>'ORDER_ID','relatedIdentifierValue'=>'702-1111111-2222222'],['relatedIdentifierName'=>'DEFERRED_TRANSACTION_ID','relatedIdentifierValue'=>'prior-version']],
 ]]]]];}
}
$tx=(new SvAmazonReturnsSpApi(new FovClient()))->listTransactions('702-1111111-2222222')['transactions'][0];
fovEq('702-1111111-2222222',$tx['order_id']??null,'official relatedIdentifierName/Value preserves order binding');
fovEq(2,count($tx['related_identifiers']??[]),'related identifiers must survive normalization');
$names=array_column($tx['related_identifiers']??[],'name');sort($names);fovEq(['DEFERRED_TRANSACTION_ID','ORDER_ID'],$names,'lifecycle linkage must not be dropped');
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "financial-observation-version-test: OK\n";
