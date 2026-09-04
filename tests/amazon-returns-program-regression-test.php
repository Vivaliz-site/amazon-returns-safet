<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/SpApiEventSink.php';
require_once __DIR__ . '/../includes/amazon-returns/CaseRepository.php';
require_once __DIR__ . '/../includes/amazon-returns/TenantContext.php';

function prgSame(mixed $expected,mixed $actual,string $message):void
{
    if($expected!==$actual){
        throw new RuntimeException($message."\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true));
    }
}

final class ProgramRegressionMemoryPdo extends PDO
{
    /** @var array<int,array<string,mixed>> */
    public array $cases=[];
    public int $nextId=1;
    public int $lastId=0;
    public function __construct() {}
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        return new ProgramRegressionMemoryStatement($this,$query);
    }
    public function lastInsertId(?string $name=null):string|false
    {
        return (string)$this->lastId;
    }
}

final class ProgramRegressionMemoryStatement extends PDOStatement
{
    private array $rows=[];
    public function __construct(private ProgramRegressionMemoryPdo $db,private string $query) {}
    public function execute(?array $params=null):bool
    {
        $params??=[];
        $sql=strtoupper($this->query);
        $this->rows=[];
        if(str_starts_with(ltrim($sql),'INSERT INTO AMAZON_RETURN_CASES')){
            $existing=null;
            foreach($this->db->cases as $id=>$case){
                if($case['tenant_id']===(int)$params[':tenant_id']
                    && $case['amazon_connection_id']===(int)$params[':amazon_connection_id']
                    && $case['amazon_order_id']===$params[':amazon_order_id']
                    && $case['amazon_order_item_id']===$params[':amazon_order_item_id']){
                    $existing=$id;
                    break;
                }
            }
            if($existing!==null){
                if($params[':program']!=='UNKNOWN'){
                    $this->db->cases[$existing]['program']=$params[':program'];
                }
                $this->db->lastId=$existing;
                return true;
            }
            $id=$this->db->nextId++;
            $this->db->lastId=$id;
            $this->db->cases[$id]=[
                'id'=>$id,
                'tenant_id'=>(int)$params[':tenant_id'],
                'amazon_connection_id'=>(int)$params[':amazon_connection_id'],
                'amazon_order_id'=>$params[':amazon_order_id'],
                'amazon_order_item_id'=>$params[':amazon_order_item_id'],
                'program'=>$params[':program'],
            ];
            return true;
        }
        if(str_contains($sql,'SELECT * FROM AMAZON_RETURN_CASES')){
            foreach($this->db->cases as $case){
                if($case['tenant_id']!==(int)$params[':tenant_id'])continue;
                if($case['amazon_connection_id']!==(int)$params[':amazon_connection_id'])continue;
                if($case['amazon_order_id']!==$params[':order_id'])continue;
                if($case['amazon_order_item_id']!==$params[':item_id'])continue;
                $this->rows[]=$case;
            }
            return true;
        }
        throw new LogicException('Unexpected repository SQL: '.$this->query);
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed
    {
        return array_shift($this->rows)??false;
    }
}

function upsertProgram(
    SvAmazonReturnCaseRepository $cases,
    string $orderId,
    array $programs,
    array $fulfillment
):int {
    return $cases->upsertOrderItem([
        'amazon_order_id'=>$orderId,
        'amazon_order_item_id'=>'item-1',
        'marketplace_id'=>'A2Q3Y263D00KWC',
        'quantity_ordered'=>1,
        'program'=>SvAmazonSpApiEventSink::programFromOrder([
            'programs'=>$programs,'fulfillment'=>$fulfillment,
        ]),
        'state'=>'POLICY_REVIEW_REQUIRED',
    ]);
}

$db=new ProgramRegressionMemoryPdo();
$cases=new SvAmazonReturnCaseRepository($db,new SvAmazonTenantContext(1,10));
$firstId=upsertProgram($cases,'702-1111111-1111111',['DELIVERY_BY_AMAZON'],[]);
prgSame('DELIVERY_BY_AMAZON',$db->cases[$firstId]['program'],'Explicit program must classify the case.');
$secondId=upsertProgram($cases,'702-1111111-1111111',[],[]);
prgSame($firstId,$secondId,'Resync must resolve the same case.');
prgSame('DELIVERY_BY_AMAZON',$db->cases[$secondId]['program'],'Missing later evidence must not downgrade a known program.');

$db2=new ProgramRegressionMemoryPdo();
$cases2=new SvAmazonReturnCaseRepository($db2,new SvAmazonTenantContext(2,20));
$unclassified=upsertProgram($cases2,'702-2222222-2222222',[],[]);
prgSame('UNKNOWN',$db2->cases[$unclassified]['program'],'No evidence must stay UNKNOWN.');
upsertProgram($cases2,'702-2222222-2222222',[],['fulfilledBy'=>'MERCHANT']);
prgSame('STANDARD',$db2->cases[$unclassified]['program'],'Later real evidence must classify UNKNOWN.');

echo "amazon-returns-program-regression-test: OK\n";
