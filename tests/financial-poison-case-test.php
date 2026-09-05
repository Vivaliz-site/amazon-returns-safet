<?php
declare(strict_types=1);
require_once __DIR__.'/../workers/amazon-returns/daemon.php';
final class PoisonCasePdo extends PDO {
 public array $writes=[];
 public function __construct(){}
 public function prepare(string $query,array $options=[]):PDOStatement|false{return new PoisonCaseStatement($this,$query);}
}
final class PoisonCaseStatement extends PDOStatement {
 private array $params=[];
 public function __construct(private PoisonCasePdo $db,private string $sql){}
 public function execute(?array $params=null):bool{$this->params=$params??[];if(str_starts_with($this->sql,'INSERT')||str_starts_with($this->sql,'UPDATE'))$this->db->writes[]=['sql'=>$this->sql,'params'=>$this->params];return true;}
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{
  if(str_contains($this->sql,'amazon_return_source_cursors'))return false;
  return ['id'=>(int)($this->params[':case_id']??$this->params[':id']??2),'state'=>'RECOVERED'];
 }
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{
  if(str_contains($this->sql,'expected_reimbursement_amount>0'))return [
   ['id'=>1,'state'=>'RECOVERED','expected_reimbursement_amount'=>'100.00','marketplace_id'=>'A2Q3Y263D00KWC'],
   ['id'=>2,'state'=>'RECOVERED','expected_reimbursement_amount'=>'100.00','marketplace_id'=>'A2Q3Y263D00KWC']];
  if(str_contains($this->sql,'amazon_return_events') && ($this->params[':case_id']??0)===1)throw new PDOException('PRIVATE_TEST_SECRET poison fixture');
  return [];
 }
 public function rowCount():int{return 1;}
}
$db=new PoisonCasePdo();$daemon=new SvAmazonReturnsDaemon($db,new SvAmazonTenantContext(1,1),new SvAmazonReturnsConfig());
try{$result=(new ReflectionMethod($daemon,'runFinancial'))->invoke($daemon);}catch(Throwable $e){$result=['status'=>'UNCAUGHT','error_class'=>$e::class];}
$errors=[];
if(($result['status']??'')!=='PARTIAL')$errors[]='One bad case must report PARTIAL instead of aborting the entire page';
if(($result['failed']??0)!==1)$errors[]='Failed case must remain visible';
if(($result['updated']??0)!==1)$errors[]='The valid case after the failed one must still reconcile';
$cursor=array_values(array_filter($db->writes,fn($w)=>str_contains($w['sql'],'amazon_return_source_cursors')));
if(($cursor[0]['params'][':cursor_value']??null)!=='2')$errors[]='Cursor must advance after the attempted page, retrying failed cases on the next rotation';
if(str_contains(json_encode($result),'PRIVATE_TEST_SECRET'))$errors[]='Financial failure audit leaked raw exception content';
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "financial-poison-case-test: OK\n";
