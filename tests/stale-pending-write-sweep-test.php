<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/TenantContext.php';
require_once __DIR__.'/../includes/amazon-returns/TenantOutbox.php';

function spwAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function spwSame(mixed $want,mixed $got,string $message):void{if($want!==$got)throw new RuntimeException($message.' expected='.var_export($want,true).' actual='.var_export($got,true));}

final class SpwPdo extends PDO {
    public array $responses=[];
    public array $prepared=[];
    public function __construct(){}
    public function queue(array $response):void{$this->responses[]=$response;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{
        $this->prepared[]=$query;
        return new SpwStatement($query,array_shift($this->responses)??[]);
    }
}
final class SpwStatement extends PDOStatement {
    public array $params=[];
    public function __construct(public string $sql,private array $response){}
    public function execute(?array $params=null):bool{$this->params=$params??[];return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->response['fetch']??false;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->response['rows']??[];}
    public function rowCount():int{return (int)($this->response['rowCount']??0);}
}
$db=new SpwPdo();
$outbox=new SvAmazonTenantReturnsOutbox($db,new SvAmazonTenantContext(1,10));
$db->queue(['rows'=>[['case_id'=>496],['case_id'=>11824],['case_id'=>496]]]);
spwSame([496,11824],$outbox->pendingWriteCaseIds(),'Pending write cases must be unique and scoped.');
$caseSql=$db->prepared[0]??'';
spwAssert(str_contains($caseSql,"status='PENDING'"),'Pending write lookup must not claim or include processing jobs.');
foreach(['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'] as $kind){
    spwAssert(str_contains($caseSql,"'{$kind}'"),'Pending write lookup missing '.$kind);
}
spwAssert(!str_contains($caseSql,'SAFE_T_DISCOVERY'),'Read-only discovery must never be swept as a write.');

$db->queue(['fetch'=>['id'=>496]]);
$db->queue(['rowCount'=>28]);
$count=$outbox->supersedePendingWritesExcept(496,'SELLER_SUPPORT_OPEN',str_repeat('a',64),'SUPERSEDED_BY_CURRENT_DECISION:SELLER_SUPPORT_OPEN');
spwSame(28,$count,'Only stale pending writes should be superseded.');
$updateSql=$db->prepared[2]??'';
spwAssert(str_contains($updateSql,"status='SUPERSEDED'"),'Sweep must preserve stale rows as SUPERSEDED.');
spwAssert(str_contains($updateSql,"status='PENDING'"),'Sweep must never overwrite already-processing/completed jobs.');
spwAssert(str_contains($updateSql,'NOT (kind=:keep_kind AND idempotency_key=:keep_key)'),'Current logical write must remain pending.');
spwAssert(!str_contains($updateSql,'SAFE_T_DISCOVERY'),'Sweep update must exclude read-only discovery jobs.');

echo "stale-pending-write-sweep-test: OK\n";
