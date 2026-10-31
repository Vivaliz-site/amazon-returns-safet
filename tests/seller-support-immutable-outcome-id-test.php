<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/BridgeService.php';
require_once __DIR__.'/../includes/amazon-returns/RemoteBridge.php';
function outcomeAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$scope='tenant-1:conn-10';
$row=['id'=>9001,'kind'=>'SELLER_SUPPORT_OPEN','idempotency_key'=>hash('sha256','synthetic-outbox'),'attempt_count'=>4];
$outcome=['action'=>'SELLER_SUPPORT_OPEN','resume_scope'=>'SYNTHETIC','status'=>'UI_DRIFT','submitted'=>true,
 'external_id'=>null,'retry_safe'=>true,'reason'=>'SUPPORT_WRITE_WITHOUT_READBACK_ID',
 'write_content_sha256'=>hash('sha256','synthetic-content')];
$legacy=SvAmazonReturnsBridgeService::bridgeResultEventIdempotencyKey($scope,$row,$outcome,hash('sha256','synthetic-evidence'));
$withId=$outcome;$withId['result_event_id']=str_repeat('a',32);
$key1=SvAmazonReturnsBridgeService::bridgeResultEventIdempotencyKey($scope,$row,$withId,hash('sha256','synthetic-evidence'));
$another=$withId;$another['result_event_id']=str_repeat('b',32);
$key2=SvAmazonReturnsBridgeService::bridgeResultEventIdempotencyKey($scope,$row,$another,hash('sha256','synthetic-evidence'));
outcomeAssert($key1!==$key2,'Two actual worker executions need distinct immutable event identities even when UI evidence is unchanged.');
outcomeAssert($key1===SvAmazonReturnsBridgeService::bridgeResultEventIdempotencyKey($scope,$row,$withId,hash('sha256','synthetic-evidence')),'Exact result POST replay must remain idempotent.');
outcomeAssert($legacy!==$key1,'New explicit result identity must not collide with legacy identity.');
$normalized=SvAmazonReturnsRemoteBridge::validateResult([
 'status'=>'UI_DRIFT','submitted'=>true,'external_id'=>null,'retry_safe'=>true,
 'reason'=>'SUPPORT_WRITE_WITHOUT_READBACK_ID','result_event_id'=>str_repeat('a',32),
]);
outcomeAssert(($normalized['result_event_id']??null)===str_repeat('a',32),'Server must validate and retain worker result event identity.');
$invalid=false;
try{SvAmazonReturnsRemoteBridge::validateResult(['status'=>'UI_DRIFT','result_event_id'=>'bad-id']);}
catch(RuntimeException){$invalid=true;}
outcomeAssert($invalid,'Invalid external result IDs must fail closed.');
$worker=file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
outcomeAssert(is_string($worker) && str_contains($worker,"result.result_event_id = randomBytes(16).toString('hex');"),'Worker must create immutable outcome ID before result POST.');
$start=strpos($worker,'async function supportOpen');
$end=strpos($worker,'async function supportUpdate',$start);
outcomeAssert($start!==false&&$end!==false,'Support Open must be present.');
$open=substr($worker,$start,$end-$start);
$guard=strpos($open,"if (retryReconciliation) return bridgeResult('HUMAN_INTERVENTION_REQUIRED'");
$write=strpos($open,'await cdp.navigate(HELP_URL, 6000)');
outcomeAssert($guard!==false&&$write!==false&&$guard<$write,'Ambiguous prior submit without readback must never trigger another Support write.');
echo "seller-support-immutable-outcome-id-test: OK\n";
