<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ReviewOperations.php';

function rnSame(mixed $want, mixed $actual, string $reason):void {
    if ($want !== $actual) throw new RuntimeException($reason.' expected='.json_encode($want).' actual='.json_encode($actual));
}
function rnAssert(bool $ok, string $reason):void {
    if (!$ok) throw new RuntimeException($reason);
}
final class RnContext {
    public function tenantId():int{return 1;}
    public function amazonConnectionId():int{return 1;}
}
final class RnReviews {
    public array $rows=[];
    public function openQueue(array $filters=[]):array{return array_values($this->rows);}
    public function saveSuggestion(int $id,int $version,array $suggestion,string $model):array {
        throw new RuntimeException('This regression expects existing advisory evidence');
    }
}
final class RnCases {
    public function find(int $id):array{return ['id'=>$id,'amazon_order_id'=>'701-0000000-'.str_pad((string)$id,7,'0',STR_PAD_LEFT)];}
}
final class RnCursors {
    public ?array $last=null;
    public function load(string $source,string $key):?array{return $this->last;}
    public function save(string $source,string $key,string $value,array $metadata=[]):void {
        $this->last=['value'=>$value,'metadata'=>$metadata,'observed_at'=>$value];
    }
    public function clear(string $source,string $key):void{$this->last=null;}
}
final class RnPersistence {
    public RnReviews $reviews;
    public RnCases $cases;
    public RnCursors $cursors;
    public function __construct(){
        $this->reviews=new RnReviews();
        $this->cases=new RnCases();
        $this->cursors=new RnCursors();
    }
    public function context():RnContext{return new RnContext();}
}
function rnReview(int $id,string $reason,string $action):array {
    return [
        'id'=>$id,'case_id'=>$id,'version'=>2,'reason'=>$reason,'status'=>'OPEN',
        'context'=>['signature'=>['material_conflict'=>false],
            'facts'=>['customer_delivery_status'=>'UNKNOWN','material_conflict'=>false]],
        'ai_suggestion'=>['action'=>$action,'confidence'=>0.8,
            'rationale'=>'Usar as evidências existentes.','uncertainties'=>[],
            'parameters'=>['date_binding'=>'NONE']],
    ];
}
$p=new RnPersistence();
$operational=[
    [386,'SUPPORT_CASE_NOT_REOPENABLE','SELLER_SUPPORT_OPEN'],
    [398,'SUPPORT_CASE_NOT_REOPENABLE','CHECK_FINANCES'],
    [399,'SUPPORT_CASE_NOT_REOPENABLE','CHECK_FINANCES'],
    [402,'SUPPORT_AMBIGUOUS_PRIOR_SUBMISSION_UNRESOLVED','CHECK_FINANCES'],
    [404,'SELLER_SUPPORT_CHAT_READ_BACK_NOT_CONFIRMED','CHECK_FINANCES'],
    [416,'REFUND_INITIATOR_UNKNOWN','CHECK_FINANCES'],
];
foreach($operational as [$id,$reason,$action])$p->reviews->rows[$id]=rnReview($id,$reason,$action);
$posts=[];
$transport=static function(string $method,string $url,array $headers,?array $body=null)use(&$posts):array{
    if($method==='GET' && str_contains($url,'/messages?'))return ['status'=>200,'json'=>['messages'=>[]]];
    if($method==='POST' && str_contains($url,'/messages/send')){
        $posts[]=$body;
        return ['status'=>200,'json'=>['id'=>'mail-'.count($posts),'threadId'=>'thread-'.count($posts)]];
    }
    throw new RuntimeException('Unexpected Gmail request '.$method.' '.$url);
};
$config=new SvAmazonReturnsConfig([
    'GMAIL_OAUTH_ACCESS_TOKEN'=>'fixture-access',
    'AMAZON_RETURNS_REVIEW_NOTIFY_EMAIL'=>'owner@example.com',
]);
$ops=new SvAmazonReviewOperations($p,$config,null,new SvAmazonGmailApiClient($config,$transport));
$when=new DateTimeImmutable('2026-11-11 11:00:00',new DateTimeZone('UTC'));
$r=$ops->run($when);
rnSame(6,$r['open_reviews'],'Keep all six financial/recovery records open');
rnSame(6,$r['operational_reviews']??null,'Classify operational investigations as non-owner work');
rnSame(0,$r['manual_reviews']??null,'No genuine owner decision in six cases');
rnSame(0,$r['reminder_sent'],'No owner email for six operational cases');
rnSame(0,count($posts),'Do not send repetitive mail for operational queue');
rnSame(6,count($p->reviews->rows),'Never erase pending financial work to silence email');

$p->reviews->rows[500]=rnReview(500,'DAMAGED_RETURN_INITIAL_CLAIM_MANUAL_ONLY','CHECK_FINANCES');
$r=$ops->run($when->modify('+1 minute'));
rnSame(1,$r['manual_reviews']??null,'True owner exception remains visible');
rnSame(1,$r['reminder_sent'],'New manual exception can notify owner');
rnSame(1,count($posts),'One real owner notification');
$raw=base64_decode(strtr((string)($posts[0]['raw']??''),'-_','+/'));
rnAssert(is_string($raw) && str_contains($raw,'Revisão #500'),'Manual case included');
rnAssert(!str_contains($raw,'Revisão #386'),'Operational case excluded');
rnAssert(!str_contains($raw,'Este lembrete será repetido'),'Do not promise recurring inbox spam');

$r=$ops->run($when->modify('+2 hours +2 minutes'));
rnSame(0,$r['reminder_sent'],'Unchanged manual review does not resend every two hours');
rnSame(1,count($posts),'No second email for unchanged owner action');

$p->reviews->rows[501]=rnReview(501,'EMAIL_REVIEW_FINAL_REQUIRES_TERMINAL_REVIEW','CHECK_FINANCES');
$r=$ops->run($when->modify('+2 hours +3 minutes'));
rnSame(1,$r['reminder_sent'],'Notify about a newly emerged genuine decision');
rnSame(2,count($posts),'Only one additional mail');
$second=base64_decode(strtr((string)($posts[1]['raw']??''),'-_','+/'));
rnAssert(is_string($second)&&str_contains($second,'Revisão #501'),'New decision included');
rnAssert(!str_contains($second,'Revisão #500'),'Previously notified manual case not repeated');
$r=$ops->run($when->modify('+4 hours +5 minutes'));
rnSame(0,$r['reminder_sent'],'No repeat after further time');
rnSame(2,count($posts),'Exactly once per manual review ID');

echo "review-notification-ownership-test: OK\n";
