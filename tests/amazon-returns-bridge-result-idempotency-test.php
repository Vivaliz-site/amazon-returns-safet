<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/BridgeResultEventId.php';
function idCheck(bool $condition, string $reason): void {
    if (!$condition) throw new RuntimeException($reason);
}
$row=[
    'id'=>713,'kind'=>'SELLER_SUPPORT_UPDATE','idempotency_key'=>str_repeat('a',64),
    'payload'=>['write_snapshot'=>['content_sha256'=>hash('sha256','synthetic-support-write')]],
];
$pre=[
    'status'=>'UI_DRIFT','submitted'=>false,'external_id'=>null,'retry_safe'=>true,
    'reason'=>'UI_CHANGED_BEFORE_SUBMISSION',
    'evidence'=>['snapshot_sha256'=>hash('sha256','synthetic-ui')],
    'result_event_id'=>str_repeat('1',32),
];
$post=$pre;
$post['submitted']=true;
$post['reason']='SUPPORT_WRITE_WITHOUT_READBACK_ID';
$post['result_event_id']=str_repeat('2',32);
$keyPre=SvAmazonBridgeResultEventId::key('tenant1:conn10',$row,$pre);
$keyPost=SvAmazonBridgeResultEventId::key('tenant1:conn10',$row,$post);
idCheck(preg_match('/^[0-9a-f]{64}$/D',$keyPre)===1,'Identity must be a SHA256 hash.');
idCheck($keyPre!==$keyPost,'Prewrite UI drift must not suppress later submitted-without-readback event.');
idCheck(SvAmazonBridgeResultEventId::key('tenant1:conn10',$row,$post)===$keyPost,'Exact duplicate result must be idempotent.');
$postAgain=$post;
$postAgain['result_event_id']=str_repeat('3',32);
idCheck(SvAmazonBridgeResultEventId::key('tenant1:conn10',$row,$postAgain)!==$keyPost,'Separate immutable result episode must be retained.');
$postNewContent=$post;
$postNewContent['result_event_id']=null;
$rowNewContent=$row;
$rowNewContent['payload']['write_snapshot']['content_sha256']=hash('sha256','new-synthetic-support-write');
idCheck(SvAmazonBridgeResultEventId::key('tenant1:conn10',$rowNewContent,$postNewContent)!==SvAmazonBridgeResultEventId::key('tenant1:conn10',$row,$postNewContent),'Different current content hash must not alias the old episode.');
idCheck(SvAmazonBridgeResultEventId::key('tenant2:conn10',$row,$post)!==$keyPost,'Tenant scope must isolate event identities.');
$invalid=$post;
$invalid['result_event_id']='not-an-id';
try {
    SvAmazonBridgeResultEventId::key('tenant1:conn10',$row,$invalid);
    throw new RuntimeException('Invalid result episode ID was accepted.');
} catch (InvalidArgumentException) {}
echo "amazon-returns-bridge-result-idempotency-test: OK\n";
