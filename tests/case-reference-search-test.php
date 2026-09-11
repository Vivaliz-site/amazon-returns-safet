<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/includes/amazon-returns/CaseReferenceSearch.php';

function crsSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
function crsAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}

crsSame('ORDER',SvAmazonCaseReferenceSearch::kind('702-1234567-7654321'),'Order classification.');
crsSame('INVOICE',SvAmazonCaseReferenceSearch::kind('123456'),'NF classification.');
crsSame('RETURN_TRACKING',SvAmazonCaseReferenceSearch::kind('tbr015328001'),'TBR classification.');
crsSame('REFERENCE',SvAmazonCaseReferenceSearch::kind('B0ABC12345'),'Generic reference classification.');

final class CrsPdo extends PDO{
    public array $responses=[];
    public array $executed=[];
    public function __construct(){}
    public function queue(array $columns):void{$this->responses[]=$columns;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new CrsStatement($this,$query,array_shift($this->responses)??[]);}
}
final class CrsStatement extends PDOStatement{
    public function __construct(private CrsPdo $db,private string $sql,private array $columns){}
    public function execute(?array $params=null):bool{$this->db->executed[]=['sql'=>$this->sql,'params'=>$params??[]];return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->columns;}
}
$db=new CrsPdo();
$db->queue([7]);
$db->queue([7,8]);
$db->queue([8]);
$context=new SvAmazonTenantContext(11,22);
$ids=SvAmazonCaseReferenceSearch::caseIds($db,$context,'TBR015328001');
crsSame([7,8],$ids,'Reference resolver must de-duplicate case IDs.');
crsAssert(count($db->executed)>=2,'Reference resolver must query scoped case/evidence sources.');
foreach($db->executed as $execution){
    $sql=$execution['sql'];$params=$execution['params'];
    crsAssert(str_contains($sql,'tenant_id='),'Reference SQL must scope tenant.');
    crsAssert(str_contains($sql,'amazon_connection_id='),'Reference SQL must scope connection.');
    $tenant=array_values(array_filter($params,static fn(mixed $v,string $k):bool=>str_contains($k,'tenant'),ARRAY_FILTER_USE_BOTH));
    $connection=array_values(array_filter($params,static fn(mixed $v,string $k):bool=>str_contains($k,'connection'),ARRAY_FILTER_USE_BOTH));
    crsAssert(in_array(11,$tenant,true),'Tenant value must come from context.');
    crsAssert(in_array(22,$connection,true),'Connection value must come from context.');
}
$eventExecution=null;
foreach($db->executed as $execution){if(str_contains($execution['sql'],'amazon_return_events')){$eventExecution=$execution;break;}}
crsAssert(is_array($eventExecution),'Event evidence query must execute.');
crsSame('TBR015328001',$eventExecution['params'][':event_return_tracking_ids']??null,'Structured TBR array lookup must bind the exact token.');
crsSame('TBR015328001',$eventExecution['params'][':event_return_tracking_id']??null,'Scalar structured TBR must remain exact.');
crsAssert(str_contains($eventExecution['sql'],'JSON_CONTAINS'),'Structured TBR array lookup must compare an exact JSON array element.');
$invoiceDb=new CrsPdo();
$invoiceDb->queue([]);$invoiceDb->queue([]);$invoiceDb->queue([9]);
crsSame([9],SvAmazonCaseReferenceSearch::caseIds($invoiceDb,$context,'123456'),'Exact NF evidence must resolve.');
$invoiceExecution=null;
foreach($invoiceDb->executed as $execution){if(array_key_exists(':q_invoice',$execution['params'])){$invoiceExecution=$execution;break;}}
crsSame('123456',$invoiceExecution['params'][':q_invoice']??null,'Structured NF lookup must be exact.');
$source=(string)file_get_contents($root.'/includes/amazon-returns/CaseReferenceSearch.php');
$caseRepository=(string)file_get_contents($root.'/includes/amazon-returns/CaseRepository.php');
$eventStore=(string)file_get_contents($root.'/includes/amazon-returns/TenantEventStore.php');
foreach(['return_tracking_id','return_tracking_ids','customer_tracking_ids','tracking_id','tracking_ids','invoice_number','sales_invoice_number'] as $field){
    crsAssert(str_contains($source.$eventStore,$field),'Known evidence reference missing: '.$field);
}
crsAssert(!str_contains($source.$eventStore,"JSON_SEARCH(payload_json, 'all'"),'Resolver must never search arbitrary event payload text.');
crsAssert(!str_contains($source,'FROM amazon_return_cases'),'Resolver orchestration must use the tenant-scoped case repository instead of raw SQL.');
crsAssert(!str_contains($source,'FROM amazon_return_events'),'Resolver orchestration must use the tenant-scoped event store instead of raw SQL.');
crsAssert(str_contains($caseRepository,'function caseIdsForReference('),'Case repository must own direct reference SQL.');
crsAssert(str_contains($eventStore,'function caseIdsForReference('),'Event store must own evidence reference SQL.');

echo "case-reference-search-test: OK\n";
