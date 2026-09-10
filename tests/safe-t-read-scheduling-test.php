<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/TenantContext.php';
require_once __DIR__.'/../includes/amazon-returns/TenantOutbox.php';
require_once __DIR__.'/../workers/amazon-returns/scheduler.php';

function strdAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function strdSame(mixed $expected,mixed $actual,string $message):void{if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));}

final class StrdPdo extends PDO{
    public array $responses=[];
    public array $executed=[];
    public function __construct(){}
    public function queue(array $response):void{$this->responses[]=$response;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new StrdStatement($this,$query,array_shift($this->responses)??[]);}
    public function lastInsertId(?string $name=null):string|false{return '902';}
}
final class StrdStatement extends PDOStatement{
    public function __construct(private StrdPdo $db,private string $sql,private array $response){}
    public function execute(?array $params=null):bool{
        $this->db->executed[]=['sql'=>$this->sql,'params'=>$params??[]];
        if(($this->response['throw']??null) instanceof Throwable)throw $this->response['throw'];
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->response['fetch']??false;}
    public function rowCount():int{return (int)($this->response['row_count']??0);}
}

$decision=[
    'action'=>'SAFE_T_READ','reason'=>'OFFICIAL_APPEAL_DEADLINE_REFRESH_REQUIRED',
    'idempotency_key'=>hash('sha256','deadline-refresh-13246'),
];
strdAssert(method_exists(SvAmazonReturnsScheduler::class,'isReadAction'),'Scheduler must distinguish read-only bridge actions from external writes.');
strdSame(true,SvAmazonReturnsScheduler::isReadAction($decision),'SAFE_T_READ must be recognized as a read-only schedulable action.');
strdSame(false,SvAmazonReturnsScheduler::isWriteAction($decision),'SAFE_T_READ must never be classified as an external write.');

$db=new StrdPdo();
$db->queue(['fetch'=>['id'=>13246]]);
$db->queue([]);
$outbox=new SvAmazonTenantReturnsOutbox($db,new SvAmazonTenantContext(1,1));
$case=['id'=>13246,'amazon_order_id'=>'702-5404465-2676215','amazon_order_item_id'=>'item-1','safe_t_id'=>'91582-36431-8749346'];
$scheduler=new SvAmazonReturnsScheduler();
$result=$scheduler->scheduleDecision($outbox,$case,$decision,[]);
strdSame(902,$result['outbox_id']??null,'Targeted SAFE-T deadline refresh must be enqueued immediately.');
strdSame(true,$result['enqueued']??null,'A newly inserted bridge job must be reported as newly enqueued.');
$insert=null;
foreach($db->executed as $execution){if(str_contains($execution['sql'],'INSERT INTO amazon_return_outbox')){$insert=$execution;break;}}
strdAssert(is_array($insert),'SAFE_T_READ must create an outbox row for the read bridge.');
strdSame('SAFE_T_READ',$insert['params'][':kind']??null,'Outbox kind must remain SAFE_T_READ.');
$payload=json_decode((string)($insert['params'][':payload_json']??''),true,512,JSON_THROW_ON_ERROR);
strdSame(true,$payload['read_only']??null,'Targeted refresh payload must be explicitly read-only.');
strdSame('91582-36431-8749346',$payload['safe_t_id']??null,'Targeted refresh must carry the SAFE-T claim ID.');

$duplicateDb=new StrdPdo();
$duplicateDb->queue(['fetch'=>['id'=>13246]]);
$duplicate=new PDOException('Duplicate scoped outbox key',23000);
$duplicate->errorInfo=['23000',1062,'Duplicate scoped outbox key'];
$duplicateDb->queue(['throw'=>$duplicate]);
$duplicateDb->queue(['fetch'=>['id'=>903,'status'=>'SUCCEEDED','attempt_count'=>1,'kind'=>'SAFE_T_READ','case_id'=>13246]]);
$duplicateOutbox=new SvAmazonTenantReturnsOutbox($duplicateDb,new SvAmazonTenantContext(1,1));
$duplicateResult=$scheduler->scheduleDecision($duplicateOutbox,$case,$decision,[]);
strdSame(903,$duplicateResult['outbox_id']??null,'An idempotent completed job must remain addressable by its existing outbox ID.');
strdSame(false,$duplicateResult['enqueued']??null,'An idempotent completed job must not be reported as newly enqueued.');

$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
strdAssert(str_contains($daemon,'SvAmazonReturnsScheduler::isReadAction($decision)'),'Runtime scheduler must enqueue read-only decisions before the external-write gate.');
strdAssert(substr_count($daemon,"\$scheduled['enqueued'] ?? false")>=2,'Runtime scheduler must count only jobs that actually entered the active outbox.');

echo "safe-t-read-scheduling-test: OK\n";
