<?php
declare(strict_types=1);
require_once __DIR__.'/../workers/amazon-returns/daemon.php';
final class EmailGatePdo extends PDO {
 public array $executed=[];private bool $transaction=false;
 public function __construct(){}
 public function beginTransaction():bool{$this->transaction=true;return true;}
 public function commit():bool{$this->transaction=false;return true;}
 public function rollBack():bool{$this->transaction=false;return true;}
 public function inTransaction():bool{return $this->transaction;}
 public function prepare(string $query,array $options=[]):PDOStatement|false{return new EmailGateStatement($this,$query);}
}
final class EmailGateStatement extends PDOStatement {
 public function __construct(private EmailGatePdo $db,private string $sql){}
 public function execute(?array $params=null):bool{$this->db->executed[]=['sql'=>$this->sql,'params'=>$params??[]];return true;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return [];}
 public function rowCount():int{return 0;}
}
$errors=[];
foreach([[1,0,['SAFE_T_EMAIL_REVIEW']],[0,1,['SAFE_T_EMAIL_REPLY']],[0,0,[]]] as [$review,$reply,$expected]){
 $db=new EmailGatePdo();$config=new SvAmazonReturnsConfig([
  'AMAZON_RETURNS_ENABLED'=>'1','AMAZON_RETURNS_MODE'=>'production','AMAZON_RETURNS_GMAIL_INGEST'=>'0',
  'AMAZON_RETURNS_EMAIL_REVIEW_WRITE'=>(string)$review,'AMAZON_RETURNS_EMAIL_REPLY_WRITE'=>(string)$reply,
  'GMAIL_OAUTH_CLIENT_ID'=>'test','GMAIL_OAUTH_CLIENT_SECRET'=>'test','GMAIL_OAUTH_REFRESH_TOKEN'=>'test',
 ]);
 $daemon=new SvAmazonReturnsDaemon($db,new SvAmazonTenantContext(1,1),$config);
 $method=new ReflectionMethod($daemon,'runGmail');$result=$method->invoke($daemon);
 $kinds=[];foreach($db->executed as $execution){foreach($execution['params'] as $value){if(is_string($value)&&str_starts_with($value,'SAFE_T_EMAIL_'))$kinds[]=$value;}}
 $kinds=array_values(array_unique($kinds));sort($kinds);sort($expected);
 if($kinds!==$expected)$errors[]='review='.$review.' reply='.$reply.' claimed='.json_encode($kinds).' expected='.json_encode($expected);
}
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "email-executor-gates-test: OK\n";
