<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/StatusBridgeService.php';
final class ReentryPdo extends PDO {
 public array $events=[];public array $case=['id'=>77,'tenant_id'=>1,'amazon_connection_id'=>1,'state'=>'SAFE_T_DENIED','safe_t_id'=>'11111-22222-3333333','appeal_deadline_at'=>null,'last_denial_fingerprint'=>null,'repeated_denial_count'=>0];
 private bool $tx=false;public int $last=2;
 public function __construct(){}
 public function prepare(string $query,array $options=[]):PDOStatement|false{return new ReentryStatement($this,$query);}
 public function beginTransaction():bool{$this->tx=true;return true;}public function commit():bool{$this->tx=false;return true;}public function rollBack():bool{$this->tx=false;return true;}public function inTransaction():bool{return $this->tx;}
 public function lastInsertId(?string $name=null):string|false{return (string)$this->last;}
}
final class ReentryStatement extends PDOStatement {
 private array $params=[];
 public function __construct(private ReentryPdo $db,private string $sql){}
 public function execute(?array $params=null):bool{
  $this->params=$params??[];
  if(str_starts_with($this->sql,'INSERT INTO amazon_return_events')){
   $row=[];foreach($this->params as $k=>$v)$row[ltrim($k,':')]=$v;$row['id']=++$this->db->last;$this->db->events[]=$row;
  }
  if(str_starts_with($this->sql,'UPDATE amazon_return_cases')){
   foreach($this->params as $k=>$v){$key=ltrim($k,':');if(str_starts_with($key,'patch_'))$key=substr($key,6);if(array_key_exists($key,$this->db->case))$this->db->case[$key]=$v;}
  }
  return true;
 }
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{
  if(str_contains($this->sql,'FROM amazon_return_cases'))return $this->db->case;
  foreach($this->db->events as $e)if(($e['idempotency_key']??'')===($this->params[':key']??$this->params[':idempotency_key']??null))return $e;return false;
 }
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->db->events;}
 public function fetchColumn(int $column=0):mixed{$row=$this->fetch();return is_array($row)?($row['id']??false):false;}
 public function rowCount():int{return 1;}
}
$db=new ReentryPdo();$approved=['claim_status'=>'APPROVED','safe_t_id'=>$db->case['safe_t_id'],'appeal_denied'=>false,'decision_text'=>null];
$denied=$approved;$denied['claim_status']='DENIED';$denied['decision_text']='Negado';
foreach([$approved,$denied] as $i=>$read)$db->events[]=['id'=>$i+1,'tenant_id'=>1,'amazon_connection_id'=>1,'case_id'=>77,'event_type'=>'SAFE_T_STATUS_OBSERVED','source'=>'SELLER_CENTRAL','idempotency_key'=>SvAmazonSafeTStatusService::observationKey(77,$read),'occurred_at'=>'2026-09-0'.($i+1).' 12:00:00','created_at'=>'2026-09-0'.($i+1).' 12:00:00','payload_json'=>json_encode($read)];
$service=new SvAmazonReturnsStatusBridgeService(SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(1,1)));
$method=new ReflectionMethod($service,'completeObservation');$row=['id'=>901,'case_id'=>77];$result=['read'=>$approved,'evidence'=>[]];
$first=$method->invoke($service,$row,$result);$errors=[];
if($db->case['state']!=='SAFE_T_APPROVED')$errors[]='A repeated approval after a denial must refresh the current case';
if(count($db->events)!==3)$errors[]='A to B to A must preserve a new immutable observation';
$again=$method->invoke($service,$row,$result);
if(count($db->events)!==3 || $again['new_observation']!==false)$errors[]='Repeated current snapshot must remain idempotent';
foreach(['EMAIL_REVIEW_SENT','EMAIL_REVIEW_RESPONSE_PENDING','SUPPORT_ESCALATION','RECOVERED'] as $state){
 if(SvAmazonSafeTStatusService::nextState($state,'DENIED',true)!==$state)$errors[]='Polling must preserve operational progress '.$state;
}
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "status-observation-reentry-test: OK\n";
