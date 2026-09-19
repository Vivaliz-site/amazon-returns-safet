<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/TenantContext.php';
require_once __DIR__.'/../includes/amazon-returns/TenantOutbox.php';
require_once __DIR__.'/../includes/amazon-returns/Runtime.php';

function ssdrAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function ssdrSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}

final class SsdrPdo extends PDO{
    /** @var list<array<string,mixed>> */ public array $executed=[];
    /** @var list<array<string,mixed>> */ public array $responses=[];
    public function __construct(){}
    public function queue(array $response):void{$this->responses[]=$response;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{
        return new SsdrStatement($this,$query,array_shift($this->responses)??[]);
    }
}
final class SsdrStatement extends PDOStatement{
    public function __construct(private SsdrPdo $db,private string $sql,private array $response){}
    public function execute(?array $params=null):bool{
        $this->db->executed[]=['sql'=>$this->sql,'params'=>$params??[]];return true;
    }
    public function rowCount():int{return (int)($this->response['row_count']??0);}
}

$db=new SsdrPdo();
$outbox=new SvAmazonTenantReturnsOutbox($db,new SvAmazonTenantContext(1,10));
ssdrAssert(method_exists($outbox,'reactivateSafeDeferredExternalWrites'),
    'Outbox must expose a scoped recovery for known-safe deferred external pre-write failures.');
$db->queue(['row_count'=>13]);
$reactivated=$outbox->reactivateSafeDeferredExternalWrites();
ssdrSame(13,$reactivated,'Recovery must report the rows made immediately available.');
$exec=$db->executed[array_key_last($db->executed)]??[];
$sql=(string)($exec['sql']??'');$params=$exec['params']??[];
foreach(['tenant_id','amazon_connection_id',"status='PENDING'",'available_at>UTC_TIMESTAMP()',"kind='SAFE_T_SUBMIT'","kind='SELLER_SUPPORT_OPEN'","kind='SELLER_SUPPORT_UPDATE'"] as $needle){
    ssdrAssert(str_contains($sql,$needle),'Recovery SQL missing guard: '.$needle);
}
ssdrAssert(str_contains($sql,'available_at=UTC_TIMESTAMP()'),'Recovery must wake the existing deferred row.');
$wherePos=strpos($sql,' WHERE ');$setClause=$wherePos===false?$sql:substr($sql,0,$wherePos);
foreach(['attempt_count','payload_json','last_error','status='] as $forbidden){
    ssdrAssert(!str_contains($setClause,$forbidden),'Recovery SET clause must preserve idempotency state: '.$forbidden);
}
ssdrSame('UI_DRIFT: SAFE_T_ORDER_INPUT_MISSING',$params[':safe_t_order_input_missing']??null,
    'SAFE-T submit recovery may rearm only the observed pre-write order-field drift.');
ssdrSame('UI_DRIFT: SAFE_T_ELIGIBILITY_BUTTON_MISSING',$params[':safe_t_eligibility_button_missing']??null,
    'SAFE-T submit recovery may rearm the observed pre-write eligibility-button drift.');
ssdrSame('UI_DRIFT: SAFE_T_ITEM_SELECTION_NOT_ACCEPTED',$params[':safe_t_item_selection_not_accepted']??null,
    'SAFE-T submit recovery may rearm the observed pre-write item-selection drift.');
ssdrSame('UI_DRIFT: SUPPORT_CASE_LOOKUP_UNAVAILABLE',$params[':lookup_error']??null,
    'Only the known pre-write lookup failure may be rearmed for SELLER_SUPPORT_OPEN.');
foreach([
    ':reply_send_missing'=>'UI_DRIFT: SUPPORT_REPLY_SEND_MISSING',
    ':reply_field_missing'=>'UI_DRIFT: SUPPORT_REPLY_FIELD_MISSING',
    ':reply_field_not_writable'=>'UI_DRIFT: SUPPORT_REPLY_FIELD_NOT_WRITABLE',
    ':native_reply_not_writable'=>'UI_DRIFT: SUPPORT_NATIVE_REPLY_NOT_WRITABLE',
] as $parameter=>$error){
    ssdrSame($error,$params[$parameter]??null,
        'Only known pre-send Seller Support reply failures may be rearmed immediately after a fixed write stack deploy.');
}
ssdrAssert(str_contains($sql,'last_error IN'),
    'Seller Support update recovery must enumerate safe pre-send UI drift reasons.');
ssdrAssert(!str_contains($sql,"last_error LIKE 'UI_DRIFT:%'"),
    'Recovery must not broadly rearm post-write or otherwise uncertain UI drift failures, including SAFE-T.');

$runtimeSource=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/Runtime.php');
foreach(['TenantOutbox.php','BridgeService.php','RemoteBridge.php','seller-central-bridge-worker.mjs'] as $file){
    ssdrAssert(str_contains($runtimeSource,$file),'Outbox stack revision must fingerprint '.$file.'.');
}
$daemonSource=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
ssdrAssert(str_contains($daemonSource,'outboxStackChanged'),'Daemon must detect an outbox execution-stack revision change.');
ssdrAssert(str_contains($daemonSource,'reactivateSafeDeferredExternalWrites'),
    'Daemon must rearm only enumerated safe deferred external-write rows after a fixed write stack is deployed.');
ssdrAssert(str_contains($daemonSource,"'outbox_recovery'"),'Daemon must expose recovery evidence in runtime results.');
ssdrAssert(str_contains($daemonSource,'($results[\'outbox_recovery\'][\'status\'] ?? null)===\'OK\''),
    'Daemon must not acknowledge the new outbox revision when recovery failed.');

echo "seller-support-deferred-recovery-test: OK\n";
