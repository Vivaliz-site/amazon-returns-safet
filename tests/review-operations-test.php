<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ReviewOperations.php';

function roSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
function roAssert(bool $condition,string $why):void{if(!$condition)throw new RuntimeException($why);}

final class RoContext {
    public function tenantId():int{return 1;}
    public function amazonConnectionId():int{return 1;}
}
final class RoReviews {
    public array $rows;
    public int $saved=0;
    public int $failures=0;
    public function __construct(){
        $this->rows=[
            9=>['id'=>9,'case_id'=>501,'reason'=>'REFUND_INITIATOR_UNKNOWN','status'=>'OPEN','version'=>1,'context'=>['facts'=>['amazon_order_id'=>'702-0707321-6872209']]],
            10=>['id'=>10,'case_id'=>505,'reason'=>'OFFICIAL_APPEAL_WINDOW_EXPIRED','status'=>'OPEN','version'=>3,'context'=>['facts'=>['amazon_order_id'=>'702-8373627-4388208']],'ai_suggestion'=>['action'=>'SAFE_T_APPEAL','rationale'=>'Recorrer com as evidências disponíveis.','confidence'=>0.91,'uncertainties'=>[],'parameters'=>['date_binding'=>'APPEAL_DEADLINE']]],
        ];
    }
    public function openQueue(array $filters=[]):array{return array_values($this->rows);}
    public function saveSuggestion(int $id,int $expected,array $suggestion,string $model):array{
        $row=$this->rows[$id]??null;if(!is_array($row)||$row['version']!==$expected)throw new RuntimeException('STALE_REVIEW_VERSION',409);
        $row['ai_suggestion']=$suggestion;$row['ai_provider']='OPENAI';$row['ai_model']=$model;$row['version']++;$this->rows[$id]=$row;$this->saved++;return $row;
    }
    public function recordAiFailure(int $id,int $expected,string $class):array{$this->failures++;$row=$this->rows[$id];$row['version']++;$this->rows[$id]=$row;return $row;}
}
final class RoCases {
    public function find(int $id):?array{return match($id){501=>['id'=>501,'amazon_order_id'=>'702-0707321-6872209'],505=>['id'=>505,'amazon_order_id'=>'702-8373627-4388208'],default=>null};}
}
final class RoCursors {
    public ?array $row=null;public int $saved=0;public int $cleared=0;
    public function load(string $source,string $key):?array{return $this->row;}
    public function save(string $source,string $key,string $value,array $metadata=[]):void{$this->row=['value'=>$value,'metadata'=>$metadata,'observed_at'=>$value];$this->saved++;}
    public function clear(string $source,string $key):void{$this->row=null;$this->cleared++;}
}
final class RoPersistence {
    public RoReviews $reviews;public RoCases $cases;public RoCursors $cursors;private RoContext $ctx;
    public function __construct(){ $this->reviews=new RoReviews();$this->cases=new RoCases();$this->cursors=new RoCursors();$this->ctx=new RoContext(); }
    public function context():RoContext{return $this->ctx;}
}
final class RoAdvisor implements SvAmazonReviewAdvisor {
    public int $calls=0;
    public function suggest(array $context):array{$this->calls++;return ['action'=>'CHECK_FINANCES','rationale'=>'Confirmar o crédito financeiro antes de qualquer ação externa.','confidence'=>0.87,'uncertainties'=>[],'parameters'=>['date_binding'=>'NONE']];}
    public function model():string{return 'review-test-model';}
}

$posts=[];$gets=[];
$transport=static function(string $method,string $url,array $headers,?array $body=null)use(&$posts,&$gets):array{
    if($method==='GET' && str_contains($url,'/messages?')){$gets[]=$url;return ['status'=>200,'json'=>['messages'=>[]]];}
    if($method==='POST' && str_contains($url,'/messages/send')){$posts[]=$body;return ['status'=>200,'json'=>['id'=>'msg-'.count($posts),'threadId'=>'thread-'.count($posts)]];}
    throw new RuntimeException('Unexpected Gmail transport '.$method.' '.$url);
};
$config=new SvAmazonReturnsConfig([
    'OPENAI_API_KEY'=>'configured-for-readiness',
    'GMAIL_OAUTH_ACCESS_TOKEN'=>'test-token',
    'AMAZON_RETURNS_REVIEW_NOTIFY_EMAIL'=>'fredmourao@gmail.com',
]);
$p=new RoPersistence();$advisor=new RoAdvisor();$gmail=new SvAmazonGmailApiClient($config,$transport);
$ops=new SvAmazonReviewOperations($p,$config,$advisor,$gmail);
$now=new DateTimeImmutable('2026-09-08 01:10:00',new DateTimeZone('UTC'));
$r=$ops->run($now);
roSame('OK',$r['status']??null,'review operations status');
roSame(2,$r['open_reviews']??null,'all open reviews inspected');
roSame(1,$r['suggested']??null,'missing AI suggestion generated automatically');
roSame(2,$r['ready_reviews']??null,'all reviews are AI-ready before notification');
roSame(1,$r['reminder_sent']??null,'first pending-review reminder sent immediately');
roSame(1,$advisor->calls,'existing AI suggestion is not regenerated');
roSame(1,$p->reviews->saved,'generated suggestion persisted with optimistic version');
roSame(1,count($posts),'one reminder POST');
roSame(1,$p->cursors->saved,'notification timestamp persisted');
$raw=base64_decode(strtr((string)($posts[0]['raw']??''),'-_','+/'));
roAssert(is_string($raw)&&str_contains($raw,'To: fredmourao@gmail.com'),'reminder targets configured operator email');
roAssert(str_contains((string)$raw,'2 revisões pendentes'),'reminder describes pending review count in Portuguese');
roAssert(str_contains((string)$raw,'Verificar financeiro'),'generated action is shown in Portuguese');
roAssert(str_contains((string)$raw,'Recorrer no SAFE-T'),'existing suggested action is shown in Portuguese');
roAssert(!str_contains((string)$raw,'CHECK_FINANCES'),'operator reminder must not expose raw action enum');

$r2=$ops->run($now->modify('+1 hour'));
roSame(0,$r2['reminder_sent']??null,'no reminder before two hours');
roSame(1,count($posts),'no duplicate Gmail write inside two-hour interval');
$r3=$ops->run($now->modify('+2 hours +1 minute'));
roSame(1,$r3['reminder_sent']??null,'reminder repeats after two hours while review remains open');
roSame(2,count($posts),'second reminder has a new idempotent send episode');

$p->reviews->rows=[];
$r4=$ops->run($now->modify('+2 hours +6 minutes'));
roSame(0,$r4['open_reviews']??null,'empty review queue recognized');
roSame(1,$p->cursors->cleared,'notification episode resets after queue drains');

$runtime=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/Runtime.php');
$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
roAssert(str_contains($runtime,"'review_operations'=>7200"),'review operations must run every two hours so pending-review reminders meet the approved cadence');
roAssert(str_contains($daemon,"'review_operations'"),'daemon must dispatch automatic review operations');
roAssert(str_contains($daemon,'SvAmazonReviewOperations'),'daemon must use the review operations service');

echo "review-operations-test: OK\n";
