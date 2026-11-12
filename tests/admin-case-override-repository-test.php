<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/TenantContext.php';
require_once __DIR__.'/../includes/amazon-returns/CaseRepository.php';
function acoSame(mixed $e,mixed $a,string $m):void{if($e!==$a)throw new RuntimeException($m.' expected='.var_export($e,true).' actual='.var_export($a,true));}
function acoThrows(callable $f,string $m):void{try{$f();}catch(InvalidArgumentException|RuntimeException){return;}throw new RuntimeException($m);}
final class AcoPdo extends PDO{public array $q=[];public array $sql=[];public function __construct(){} public function queue(array $r):void{$this->q[]=$r;}public function prepare(string $query,array $options=[]):PDOStatement|false{$r=array_shift($this->q)??[];return new AcoStmt($this,$query,$r);}}
final class AcoStmt extends PDOStatement{public function __construct(private AcoPdo $db,private string $query,private array $response){}public function execute(?array $params=null):bool{$this->db->sql[]=['query'=>$this->query,'params'=>$params??[]];return true;}public function rowCount():int{return(int)($this->response['rows']??0);}}
$db=new AcoPdo();$repo=new SvAmazonReturnCaseRepository($db,new SvAmazonTenantContext(7,11));
$db->queue(['rows'=>1]);$repo->adminOverrideUpdate(55,['state'=>'CLOSED_LOSS','closed_at'=>'2026-11-12 22:00:00','terminal_reason'=>'ADMIN_MANUAL_CLOSED_LOSS'],'2026-11-12 21:00:00');
$write=$db->sql[0];
foreach(['tenant_id','amazon_connection_id','updated_at=:expected_updated_at'] as $needle){if(!str_contains($write['query'],$needle))throw new RuntimeException('Missing scoped optimistic update condition: '.$needle);}
acoSame(7,$write['params'][':tenant_id']??null,'Tenant must be bound');acoSame(11,$write['params'][':amazon_connection_id']??null,'Connection must be bound');acoSame('2026-11-12 21:00:00',$write['params'][':expected_updated_at']??null,'Expected version must be bound');acoSame('CLOSED_LOSS',$write['params'][':patch_state']??null,'Terminal state must be patchable by explicit admin override');
$db->queue(['rows'=>0]);acoThrows(fn()=>$repo->adminOverrideUpdate(55,['state'=>'POLICY_REVIEW_REQUIRED'],'2026-11-12 21:00:00'),'Stale version must conflict');
acoThrows(fn()=>$repo->adminOverrideUpdate(55,['reconciled_credit_amount'=>'121.50'],'2026-11-12 21:00:00'),'Admin override must not forge reconciled credit');
acoThrows(fn()=>$repo->adminOverrideUpdate(55,['tenant_id'=>99],'2026-11-12 21:00:00'),'Admin override must not change tenant ownership');
echo "admin-case-override-repository-test: OK\n";
