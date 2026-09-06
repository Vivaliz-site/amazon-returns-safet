<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$builderFile=$root.'/includes/amazon-returns/ExternalWritePayload.php';
if(!is_file($builderFile))throw new RuntimeException('ExternalWritePayload.php missing');
require_once $builderFile;
require_once $root.'/includes/amazon-returns/TenantContext.php';
require_once $root.'/includes/amazon-returns/TenantOutbox.php';
require_once $root.'/workers/amazon-returns/scheduler.php';

function ewsAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function ewsSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true));
}

$case=[
    'id'=>77,
    'amazon_order_id'=>'702-1234567-7654321',
    'safe_t_id'=>'98143-99485-9285859',
    'physical_status'=>'NOT_RECEIVED',
    'state'=>'SAFE_T_DENIED',
    'latest_denial_text'=>'Negado sem prova de entrega.',
];
$appealDecision=[
    'action'=>'SAFE_T_APPEAL',
    'reason'=>'SAFE_T_DENIAL_REQUIRES_FIRST_APPEAL',
    'idempotency_key'=>hash('sha256','appeal-test'),
];
$appealPayload=SvAmazonExternalWritePayload::build($appealDecision,$case,[]);
$snapshot=$appealPayload['write_snapshot']??null;
ewsAssert(is_array($snapshot),'Appeal write snapshot must exist.');
ewsSame(2,$snapshot['format_version']??null,'Appeal snapshot format version.');
ewsSame('seller_central_bridge',$snapshot['channel']??null,'Appeal snapshot channel.');
$expectedAppeal='Pedido 702-1234567-7654321, SAFE-T 98143-99485-9285859. O produto não foi recebido fisicamente pelo vendedor. '
    .'Solicito reavaliação da decisão com análise do fluxo de devolução, rastreio e eventual comprovante de entrega. SAFE_T_DENIAL_REQUIRES_FIRST_APPEAL';
ewsSame($expectedAppeal,$snapshot['narrative']??null,'Appeal narrative must preserve existing bridge wording.');
ewsSame(hash('sha256',$expectedAppeal),$snapshot['content_sha256']??null,'Appeal snapshot hash.');

$submitCase=$case;
$submitCase['safe_t_id']=null;
$submitDecision=['action'=>'SAFE_T_SUBMIT','reason'=>'FIRST_ELIGIBLE_ATTEMPT','idempotency_key'=>hash('sha256','submit-test')];
$submitPayload=SvAmazonExternalWritePayload::build($submitDecision,$submitCase,[]);
$submitText=$submitPayload['write_snapshot']['narrative']??'';
ewsAssert(trim((string)$submitText)!=='','Submit narrative is materialized before enqueue.');
ewsSame(hash('sha256',(string)$submitText),$submitPayload['write_snapshot']['content_sha256']??null,'Submit hash matches exact narrative.');
$replyCase=$case;
$replyCase['state']='EMAIL_REVIEW_RESPONSE_PENDING';
$replyTimeline=[[
    'case_id'=>77,'event_type'=>'SAFE_T_EMAIL_REVIEW_RESPONSE','source'=>'GMAIL',
    'payload'=>[
        'review_outcome'=>'INFO_REQUESTED','review_suggested_action'=>'RESPOND_EMAIL',
        'content_sha256'=>hash('sha256','review-response'),
        'gmail_thread_id'=>'thread-abc','gmail_rfc_message_id'=>'<amazon@example.com>',
        'review_excerpt'=>'Envie os fatos adicionais.',
    ],
]];
$replyDecision=(new SvAmazonSafeTDecisionEngine())->nextAction($replyCase,$replyTimeline,[]);
ewsSame('SAFE_T_EMAIL_REPLY',$replyDecision['action']??null,'Fixture must produce email reply.');
$replyPayload=SvAmazonExternalWritePayload::build($replyDecision,$replyCase,$replyTimeline);
$replyMessage=$replyPayload['write_snapshot']['message']??null;
ewsAssert(is_array($replyMessage),'Normal review reply must materialize without treating review_scope as dated resume scope.');
ewsSame('thread-abc',$replyMessage['thread_id']??null,'Reply snapshot preserves Gmail thread id.');

$emailCase=$case;
$emailCase['state']='APPEAL_DENIED_FINAL';
$emailDecision=['action'=>'SAFE_T_EMAIL_REVIEW','reason'=>'APPEAL_DENIED_REQUIRES_DETAILED_EMAIL_REVIEW','idempotency_key'=>hash('sha256','email-test')];
$emailPayload=SvAmazonExternalWritePayload::build($emailDecision,$emailCase,[]);
$emailSnapshot=$emailPayload['write_snapshot']??null;
ewsAssert(is_array($emailSnapshot),'Email write snapshot must exist.');
ewsSame('gmail',$emailSnapshot['channel']??null,'Email snapshot channel.');
$message=$emailSnapshot['message']??null;
ewsAssert(is_array($message),'Email snapshot stores complete message fields.');
ewsSame('Safe-T-Review@amazon.com',$message['to']??null,'Email recipient is persisted.');
ewsAssert(trim((string)($message['subject']??''))!=='','Email subject is persisted.');
ewsAssert(trim((string)($message['body']??''))!=='','Email body is persisted.');
ewsSame(hash('sha256',(string)$message['body']),$emailSnapshot['content_sha256']??null,'Email hash uses exact body.');

final class EwsPdo extends PDO{
    public array $responses=[];
    public array $executed=[];
    public function __construct(){}
    public function queue(array $response):void{$this->responses[]=$response;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new EwsStatement($this,$query,array_shift($this->responses)??[]);}
    public function lastInsertId(?string $name=null):string|false{return '501';}
}
final class EwsStatement extends PDOStatement{
    public function __construct(private EwsPdo $db,private string $sql,private array $response){}
    public function execute(?array $params=null):bool{$this->db->executed[]=['sql'=>$this->sql,'params'=>$params??[]];return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->response['fetch']??false;}
    public function rowCount():int{return (int)($this->response['row_count']??0);}
}

$db=new EwsPdo();
$db->queue(['fetch'=>['id'=>77]]);
$db->queue([]);
$outbox=new SvAmazonTenantReturnsOutbox($db,new SvAmazonTenantContext(1,10));
$scheduler=new SvAmazonReturnsScheduler();
$result=$scheduler->scheduleDecision($outbox,$case,$appealDecision,[]);
ewsSame(501,$result['outbox_id']??null,'Scheduler returns persisted outbox id.');
$insert=null;
foreach($db->executed as $execution){
    if(str_contains($execution['sql'],'INSERT INTO amazon_return_outbox')){$insert=$execution;break;}
}
ewsAssert(is_array($insert),'Scheduler must enqueue an outbox row.');
$stored=json_decode((string)($insert['params'][':payload_json']??''),true,512,JSON_THROW_ON_ERROR);
ewsSame($expectedAppeal,$stored['write_snapshot']['narrative']??null,'Exact appeal narrative is persisted before enqueue.');
ewsSame(hash('sha256',$expectedAppeal),$stored['write_snapshot']['content_sha256']??null,'Persisted outbox carries exact content hash.');

echo "external-write-snapshot-test: OK\n";
