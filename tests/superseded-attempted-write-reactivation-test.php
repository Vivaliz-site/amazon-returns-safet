<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/TenantOutbox.php';
require_once __DIR__.'/../includes/amazon-returns/TenantContext.php';

function sawrSame(mixed $expected,mixed $actual,string $message):void
{
    if($expected!==$actual){
        throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
    }
}

final class SupersededWritePdo extends PDO
{
    public array $queue=[];
    public array $executed=[];
    public function __construct() {}
    public function push(array $response):void{$this->queue[]=$response;}
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        return new SupersededWriteStatement($this,$query,array_shift($this->queue)??[]);
    }
}

final class SupersededWriteStatement extends PDOStatement
{
    public function __construct(private SupersededWritePdo $db,private string $sql,private array $response){}
    public function execute(?array $params=null):bool
    {
        $this->db->executed[]=['sql'=>$this->sql,'params'=>$params??[]];
        if(($this->response['duplicate']??false)===true){
            throw new PDOException('duplicate idempotency key',23000);
        }
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed
    {
        return $this->response['fetch']??false;
    }
    public function rowCount():int{return (int)($this->response['row_count']??0);}
}

$db=new SupersededWritePdo();
$outbox=new SvAmazonTenantReturnsOutbox($db,new SvAmazonTenantContext(1,1));
$key=str_repeat('a',64);
$db->push(['fetch'=>['id'=>23]]);
$db->push(['duplicate'=>true]);
$db->push(['fetch'=>[
    'id'=>341839,'status'=>'SUPERSEDED','attempt_count'=>1,
    'kind'=>'SELLER_SUPPORT_OPEN','case_id'=>23,
    'last_error'=>'SUPERSEDED_BY_CURRENT_DECISION:CHECK_FINANCES',
]]);
$db->push(['row_count'=>1]);
$result=$outbox->enqueueResult(
    'SELLER_SUPPORT_OPEN',23,
    ['case_id'=>23,'decision'=>['action'=>'SELLER_SUPPORT_OPEN']],
    $key
);

sawrSame(341839,$result['id'],'The original idempotent job must be reused.');
sawrSame(true,$result['enqueued'],'An attempted job superseded by temporary evidence must reactivate when the same intent becomes valid again.');
$update=end($db->executed);
sawrSame(true,str_contains((string)$update['sql'],"status='PENDING'"),'Reactivation must return the existing job to PENDING.');

sawrSame(true,str_contains((string)$update['sql'],"last_error LIKE 'SUPERSEDED_BY_CURRENT_DECISION:%'"),'Attempted reactivation must be restricted to temporary decision supersession.');

echo "superseded-attempted-write-reactivation-test: OK\n";
