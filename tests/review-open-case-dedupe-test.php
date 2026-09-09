<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ReviewRepository.php';

function rodAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

final class RodPdo extends PDO{
    public array $cases=[1=>['id'=>1,'tenant_id'=>1,'amazon_connection_id'=>10]];
    public array $reviews=[];
    private bool $tx=false;
    private int $last=0;
    public function __construct(){}
    public function beginTransaction():bool{$this->tx=true;return true;}
    public function commit():bool{$this->tx=false;return true;}
    public function rollBack():bool{$this->tx=false;return true;}
    public function inTransaction():bool{return $this->tx;}
    public function lastInsertId(?string $name=null):string|false{return (string)$this->last;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new RodStatement($this,$query);}
    public function run(string $sql,array $p):array{
        $q=preg_replace('/\s+/',' ',trim($sql))??$sql;
        if(str_starts_with($q,'SELECT * FROM amazon_return_cases')){
            $id=(int)($p[':id']??0);$row=$this->cases[$id]??null;
            return ['rows'=>$row?[$row]:[],'count'=>$row?1:0];
        }
        if(str_starts_with($q,'SELECT * FROM amazon_return_reviews')){
            $rows=array_values(array_filter($this->reviews,function(array $r)use($q,$p):bool{
                if((int)$r['tenant_id']!==(int)$p[':tenant_id']||(int)$r['amazon_connection_id']!==(int)$p[':connection_id'])return false;
                if(str_contains($q,'open_key=:key')&&($r['open_key']??null)!==($p[':key']??null))return false;
                if(str_contains($q,'case_id=:case_id')&&(int)$r['case_id']!==(int)($p[':case_id']??0))return false;
                if(str_contains($q,"status='OPEN'")&&($r['status']??'')!=='OPEN')return false;
                if(str_contains($q,'id=:id')&&(int)$r['id']!==(int)($p[':id']??0))return false;
                return true;
            }));
            usort($rows,fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
            return ['rows'=>$rows,'count'=>count($rows)];
        }
        if(str_starts_with($q,'INSERT INTO amazon_return_reviews')){
            $id=++$this->last;
            $row=['id'=>$id,'tenant_id'=>(int)$p[':tenant_id'],'amazon_connection_id'=>(int)$p[':connection_id'],
                'case_id'=>(int)$p[':case_id'],'status'=>'OPEN','version'=>1,'reason'=>$p[':reason'],
                'context_hash'=>$p[':context_hash'],'context_json'=>$p[':context_json'],'open_key'=>$p[':open_key'],
                'created_at'=>$p[':created_at'],'actor'=>null,'source_version'=>null,'decided_at'=>null];
            $this->reviews[$id]=$row;return ['rows'=>[],'count'=>1];
        }
        if(str_starts_with($q,'UPDATE amazon_return_reviews SET')){
            $id=(int)($p[':id']??0);$row=$this->reviews[$id]??null;
            if(!$row||($row['status']??'')!=='OPEN')return ['rows'=>[],'count'=>0];
            if(isset($p[':expected'])&&(int)$row['version']!==(int)$p[':expected'])return ['rows'=>[],'count'=>0];
            foreach($p as $key=>$value){
                if(str_starts_with((string)$key,':set_'))$row[substr((string)$key,5)]=$value;
            }
            $this->reviews[$id]=$row;return ['rows'=>[],'count'=>1];
        }
        throw new RuntimeException('Unhandled SQL: '.$q);
    }
}
final class RodStatement extends PDOStatement{
    private array $result=['rows'=>[],'count'=>0];
    public function __construct(private RodPdo $db,private string $sql){}
    public function execute(?array $params=null):bool{$this->result=$this->db->run($this->sql,$params??[]);return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->result['rows'];}
    public function fetchColumn(int $column=0):mixed{return $this->result['rows'][0][$column]??$this->result['count'];}
    public function rowCount():int{return (int)$this->result['count'];}
}

$db=new RodPdo();
$repo=new SvAmazonReviewRepository($db,new SvAmazonTenantContext(1,10));
$firstHash=hash('sha256','first-context');
$secondHash=hash('sha256','second-context');
$first=$repo->open(1,'REFUND_INITIATOR_UNKNOWN',$firstHash,['snapshot'=>1]);
$second=$repo->open(1,'REFUND_INITIATOR_UNKNOWN',$secondHash,['snapshot'=>2]);
$open=$repo->openQueue(['case_id'=>1]);
rodAssert(count($open)===1,'A case must never have more than one OPEN review episode.');
rodAssert((int)$open[0]['id']===(int)$second['id'],'The newest context must be the active review episode.');
$history=$repo->forCase(1);
rodAssert(count($history)===2,'Replacing review context must retain the prior episode for audit.');
rodAssert(($history[0]['status']??null)==='RESOLVED','Prior open context must be resolved when replaced.');
rodAssert(($history[1]['status']??null)==='OPEN','Replacement context must remain open.');
$same=$repo->open(1,'REFUND_INITIATOR_UNKNOWN',$secondHash,['snapshot'=>99]);
rodAssert((int)$same['id']===(int)$second['id'],'Identical context must reuse the current open episode.');
rodAssert(count($repo->openQueue(['case_id'=>1]))===1,'Retry must not create another open review.');
echo "review-open-case-dedupe-test: OK\n";
