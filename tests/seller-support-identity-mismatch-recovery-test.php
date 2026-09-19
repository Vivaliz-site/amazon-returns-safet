<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/StatusBridgeService.php';

function simrAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function simrSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}

final class SimrPdo extends PDO {
    public array $case=[
        'id'=>77,'tenant_id'=>1,'amazon_connection_id'=>1,
        'amazon_order_id'=>'701-0009670-5933042','support_case_id'=>'22144700811',
        'state'=>'SUPPORT_ESCALATION',
    ];
    public array $events=[];
    public string $outboxStatus='PROCESSING';
    private bool $tx=false;
    public int $last=100;
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new SimrStatement($this,$query);}
    public function beginTransaction():bool{$this->tx=true;return true;}
    public function commit():bool{$this->tx=false;return true;}
    public function rollBack():bool{$this->tx=false;return true;}
    public function inTransaction():bool{return $this->tx;}
    public function lastInsertId(?string $name=null):string|false{return (string)$this->last;}
}
final class SimrStatement extends PDOStatement {
    private array $params=[];
    private int $affected=1;
    public function __construct(private SimrPdo $db,private string $sql){}
    public function execute(?array $params=null):bool{
        $this->params=$params??[];
        if(str_starts_with($this->sql,'INSERT INTO amazon_return_events')){
            $row=[];foreach($this->params as $k=>$v)$row[ltrim($k,':')]=$v;
            $row['id']=++$this->db->last;$this->db->events[]=$row;
        }elseif(str_starts_with($this->sql,'UPDATE amazon_return_cases')){
            foreach($this->params as $k=>$v){
                $key=ltrim($k,':');
                if(str_starts_with($key,'patch_'))$this->db->case[substr($key,6)]=$v;
            }
        }elseif(str_starts_with($this->sql,'UPDATE amazon_return_outbox')){
            $this->db->outboxStatus='SUCCEEDED';
        }
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{
        if(str_contains($this->sql,'FROM amazon_return_cases')){
            return str_starts_with(trim($this->sql),'SELECT id')?['id'=>$this->db->case['id']]:$this->db->case;
        }
        return false;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return [];}
    public function fetchColumn(int $column=0):mixed{return false;}
    public function rowCount():int{return $this->affected;}
}

$db=new SimrPdo();
$service=new SvAmazonReturnsStatusBridgeService(
    SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(1,1))
);
$method=new ReflectionMethod($service,'completeSupportIdentityMismatch');
$result=$method->invoke($service,['id'=>901,'case_id'=>77],[
    'external_id'=>'22144700811',
    'reason'=>'SELLER_SUPPORT_CASE_IDENTITY_MISMATCH',
    'evidence'=>['snapshot_sha256'=>str_repeat('a',64)],
]);

simrSame(null,$db->case['support_case_id'],'A proven cross-order Seller Support binding must be cleared.');
simrSame('SUCCEEDED',$db->outboxStatus,'The mismatched read job must complete instead of retrying forever.');
simrSame(true,$result['completed']??null,'Mismatch recovery must complete the read job.');
simrSame('22144700811',$result['support_case_id_cleared']??null,'Recovery must report the exact cleared ID.');
simrSame(1,count($db->events),'Recovery must append one immutable audit event.');
simrSame('SELLER_SUPPORT_IDENTITY_MISMATCH',$db->events[0]['event_type']??null,'Audit event must name the identity corruption.');
$payload=json_decode((string)($db->events[0]['payload_json']??''),true,512,JSON_THROW_ON_ERROR);
simrSame(true,$payload['binding_cleared']??null,'Audit payload must record that the binding was cleared.');
simrSame('701-0009670-5933042',$payload['order_id']??null,'Audit payload must bind the correction to the scoped Amazon order.');

echo "seller-support-identity-mismatch-recovery-test: OK\n";
