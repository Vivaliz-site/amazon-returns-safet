<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/RemoteBridge.php';
function stproof(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$validId='98143-99485-9285859';
$matrix=[
 ['label'=>'accepted readback','expected'=>true,'status'=>'ACCEPTED','submitted'=>true,'external_id'=>$validId],
 ['label'=>'existing readback','expected'=>true,'status'=>'ALREADY_EXISTS','submitted'=>false,'external_id'=>$validId],
 ['label'=>'dry run accepted','expected'=>false,'status'=>'ACCEPTED','submitted'=>false,'external_id'=>null],
 ['label'=>'accepted no readback','expected'=>false,'status'=>'ACCEPTED','submitted'=>true,'external_id'=>null],
 ['label'=>'existing no readback','expected'=>false,'status'=>'ALREADY_EXISTS','submitted'=>false,'external_id'=>null],
 ['label'=>'malformed identifier','expected'=>false,'status'=>'ACCEPTED','submitted'=>true,'external_id'=>'UNVERIFIED'],
 ['label'=>'failure with id','expected'=>false,'status'=>'FAILED','submitted'=>true,'external_id'=>$validId],
];
foreach($matrix as $item){
  stproof(SvAmazonReturnsRemoteBridge::safeTSubmitReadbackVerified($item)===$item['expected'],'SAFE-T readback gate: '.$item['label']);
}
$service=file_get_contents(__DIR__.'/../includes/amazon-returns/BridgeService.php');
$daemon=file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
stproof(str_contains($service,'!SvAmazonReturnsRemoteBridge::safeTSubmitReadbackVerified($result)'), 'Polling bridge must fail closed before success.');
stproof(str_contains($daemon,'!SvAmazonReturnsRemoteBridge::safeTSubmitReadbackVerified($actionResult)'), 'Direct adapter must fail closed before success.');
echo "safe-t-result-readback-failclosed-test: OK\n";
