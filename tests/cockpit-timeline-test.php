<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$timelineFile=$root.'/includes/amazon-returns/CockpitTimeline.php';
if(!is_file($timelineFile))throw new RuntimeException('CockpitTimeline.php missing');
require_once $timelineFile;
require_once $root.'/includes/amazon-returns/TenantContext.php';
require_once $root.'/includes/amazon-returns/TenantOutbox.php';

function ctAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function ctSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true));
}

$case=['id'=>77,'amazon_order_id'=>'702-1234567-7654321','safe_t_id'=>'98143-99485-9285859'];
$appealText='Pedido 702-1234567-7654321, SAFE-T 98143-99485-9285859. Texto exato persistido.';
$events=[
    ['id'=>1,'event_type'=>'RETURN_STATUS_OBSERVED','source'=>'SP_API','occurred_at'=>'2026-09-05 18:00:00','payload'=>['order_id'=>$case['amazon_order_id'],'authorization'=>'Bearer secret-token','private_blob'=>'must-not-project'],'evidence_sha256'=>null],
    ['id'=>2,'event_type'=>'SELLER_CENTRAL_ACTION_RESULT','source'=>'SELLER_CENTRAL','occurred_at'=>'2026-09-05 18:10:00','payload'=>['action'=>'SAFE_T_APPEAL','status'=>'ACCEPTED','outbox_id'=>41,'write_content_sha256'=>hash('sha256',$appealText),'reason'=>'submitted'],'evidence_sha256'=>str_repeat('a',64)],
];
$outbox=[
    ['id'=>41,'case_id'=>77,'kind'=>'SAFE_T_APPEAL','status'=>'SUCCEEDED','attempt_count'=>1,'created_at'=>'2026-09-05 18:05:00','updated_at'=>'2026-09-05 18:10:00','payload'=>['write_snapshot'=>['format_version'=>2,'channel'=>'seller_central_bridge','narrative'=>$appealText,'content_sha256'=>hash('sha256',$appealText)]]],
    ['id'=>42,'case_id'=>77,'kind'=>'SAFE_T_SUBMIT','status'=>'SUCCEEDED','attempt_count'=>1,'created_at'=>'2026-09-04 12:00:00','updated_at'=>'2026-09-04 12:01:00','payload'=>['order_id'=>$case['amazon_order_id']]],
];
$timeline=SvAmazonCockpitTimeline::project($case,$events,[],$outbox,[],[]);
ctSame(['EXTERNAL_WRITE','OBSERVATION','EXTERNAL_WRITE'],array_column(array_slice($timeline,0,3),'category'),'Timeline must be chronological across sources.');

$writeItem=null;$legacyItem=null;$responseItem=null;
foreach($timeline as $item){
    if(($item['id']??'')==='outbox:41')$writeItem=$item;
    if(($item['id']??'')==='outbox:42')$legacyItem=$item;
    if(($item['id']??'')==='event:2')$responseItem=$item;
}
ctAssert(is_array($writeItem),'Persisted appeal write must be projected.');
ctSame($appealText,$writeItem['content']['narrative']??null,'Exact persisted appeal narrative must be shown unchanged.');
ctSame(hash('sha256',$appealText),$writeItem['content']['content_sha256']??null,'Write hash must be projected.');
ctAssert(is_array($legacyItem),'Legacy write must remain visible.');
ctSame('MISSING_HISTORICAL_SNAPSHOT',$legacyItem['content']['narrative_status']??null,'Legacy narrative must never be reconstructed.');
ctSame('AMAZON_RESPONSE',$responseItem['category']??null,'Seller Central result must classify as Amazon response.');
$serialized=json_encode($timeline,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
ctAssert(!str_contains($serialized,'secret-token'),'Timeline must not expose authorization values.');
ctAssert(!str_contains($serialized,'must-not-project'),'Timeline must not expose arbitrary private payload fields.');

final class CtPdo extends PDO{
    public array $responses=[];
    public function __construct(){}
    public function queue(array $response):void{$this->responses[]=$response;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new CtStatement($query,array_shift($this->responses)??[]);}
}
final class CtStatement extends PDOStatement{
    public function __construct(private string $sql,private array $response){}
    public function execute(?array $params=null):bool{return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->response['fetch']??false;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->response['rows']??[];}
}
$db=new CtPdo();
$db->queue(['fetch'=>['id'=>77]]);
$db->queue(['rows'=>[
    ['id'=>41,'tenant_id'=>1,'amazon_connection_id'=>10,'case_id'=>77,'kind'=>'SAFE_T_APPEAL','idempotency_key'=>str_repeat('a',64),'payload_json'=>json_encode($outbox[0]['payload'],JSON_THROW_ON_ERROR),'status'=>'SUCCEEDED','attempt_count'=>1,'available_at'=>'2026-09-05 18:05:00','locked_at'=>null,'last_error'=>null,'created_at'=>'2026-09-05 18:05:00','updated_at'=>'2026-09-05 18:10:00'],
    ['id'=>42,'tenant_id'=>1,'amazon_connection_id'=>10,'case_id'=>77,'kind'=>'SAFE_T_SUBMIT','idempotency_key'=>str_repeat('b',64),'payload_json'=>json_encode($outbox[1]['payload'],JSON_THROW_ON_ERROR),'status'=>'DEAD_LETTER','attempt_count'=>5,'available_at'=>'2026-09-04 12:00:00','locked_at'=>null,'last_error'=>'MAX_ATTEMPTS_EXHAUSTED','created_at'=>'2026-09-04 12:00:00','updated_at'=>'2026-09-04 12:10:00'],
]]);
$history=(new SvAmazonTenantReturnsOutbox($db,new SvAmazonTenantContext(1,10)))->historyForCase(77);
ctSame(2,count($history),'Outbox history must return all statuses for owned case.');
ctSame('SUCCEEDED',$history[0]['status']??null,'Successful writes remain in history.');
ctSame('DEAD_LETTER',$history[1]['status']??null,'Dead letters remain in history.');
ctSame($appealText,$history[0]['payload']['write_snapshot']['narrative']??null,'History decodes persisted write snapshot.');

echo "cockpit-timeline-test: OK\n";
