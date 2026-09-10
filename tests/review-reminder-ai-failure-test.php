<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ReviewOperations.php';
function rafSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
final class RafReviews{public function openQueue(array $f=[]):array{return [['id'=>31,'case_id'=>77,'status'=>'OPEN','version'=>1,'reason'=>'WRITE_BLOCKED_SAFE_T_EMAIL_REVIEW','context'=>['facts'=>['case_id'=>77]]]];}}
final class RafCases{public function find(int $id):?array{return ['id'=>$id,'amazon_order_id'=>'702-1111111-2222222'];}}
final class RafCursors{public ?array $row=null;public function load(string $s,string $k):?array{return $this->row;}public function save(string $s,string $k,string $v,array $m=[]):void{$this->row=['value'=>$v,'metadata'=>$m];}public function clear(string $s,string $k):void{$this->row=null;}}
final class RafContext{public function tenantId():int{return 1;}public function amazonConnectionId():int{return 10;}}
final class RafP{public RafReviews $reviews;public RafCases $cases;public RafCursors $cursors;public function __construct(){$this->reviews=new RafReviews();$this->cases=new RafCases();$this->cursors=new RafCursors();}public function context():RafContext{return new RafContext();}}
$posts=[];$transport=static function(string $method,string $url,array $headers,?array $body=null)use(&$posts):array{if($method==='POST'){$posts[]=$body;return ['status'=>200,'json'=>['id'=>'m1','threadId'=>'t1']];}return ['status'=>200,'json'=>['messages'=>[]]];};
$config=new SvAmazonReturnsConfig(['GMAIL_OAUTH_ACCESS_TOKEN'=>'test','AMAZON_RETURNS_REVIEW_NOTIFY_EMAIL'=>'fredmourao@gmail.com','AMAZON_RETURNS_AI_ENV_FILE'=>'/nonexistent']);
$ops=new SvAmazonReviewOperations(new RafP(),$config,null,new SvAmazonGmailApiClient($config,$transport));
$r=$ops->run(new DateTimeImmutable('2026-09-10T05:00:00Z'));
rafSame(1,$r['ai_failed']??null,'Unavailable AI must be visible in metrics.');
rafSame(1,$r['reminder_sent']??null,'AI outage must not suppress operator reminder.');
rafSame(1,count($posts),'Exactly one reminder should be sent.');
$raw=base64_decode(strtr((string)($posts[0]['raw']??''),'-_','+/'));
if(!is_string($raw)||!str_contains($raw,'indisponível no momento'))throw new RuntimeException('Reminder must explain unavailable AI suggestion.');
echo "review-reminder-ai-failure-test: OK\n";