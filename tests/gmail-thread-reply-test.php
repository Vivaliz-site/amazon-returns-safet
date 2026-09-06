<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/amazon-returns/GmailApi.php';

function gtAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function gtSame(mixed $expected,mixed $actual,string $message):void{if($expected!==$actual)throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true));}
$calls=[];
$transport=static function(string $method,string $url,array $headers,?array $body=null)use(&$calls):array{
    $calls[]=[$method,$url,$headers,$body];
    if($method==='GET' && str_contains($url,'/messages?'))return['status'=>200,'json'=>['messages'=>[]]];
    if($method==='POST' && str_contains($url,'/messages/send'))return['status'=>200,'json'=>['id'=>'sent-1','threadId'=>'thread-abc']];
    throw new RuntimeException('Unexpected transport call');
};
$api=new SvAmazonGmailApiClient(new SvAmazonReturnsConfig(['GMAIL_OAUTH_ACCESS_TOKEN'=>'test-token']),$transport);
$key=hash('sha256','reply-1');
$result=$api->sendReplyOnce('Safe-T-Review@amazon.com','Re: SAFE-T 12472-25597-6629839','Resposta verificada.','thread-abc','<amazon-rfc-message@example.com>',$key);
gtSame('thread-abc',$result['thread_id'],'Reply must remain in Amazon Gmail thread.');
$post=array_values(array_filter($calls,static fn(array $c):bool=>$c[0]==='POST'))[0] ?? null;
gtAssert(is_array($post),'Reply must POST once.');
gtSame('thread-abc',$post[3]['threadId'] ?? null,'Gmail API payload must include threadId.');
$raw=base64_decode(strtr((string)($post[3]['raw'] ?? ''),'-_','+/'));
gtAssert(is_string($raw) && str_contains($raw,'In-Reply-To: <amazon-rfc-message@example.com>'),'Reply MIME must include In-Reply-To.');
gtAssert(str_contains((string)$raw,'Message-ID: <amazon-returns-'),'Reply must retain deterministic RFC Message-ID idempotency.');


$daemonSource=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
gtAssert(str_contains($daemonSource,"write_snapshot"),'Gmail executor must consume persisted write snapshot.');
gtAssert(str_contains($daemonSource,"write_content_sha256"),'Gmail sent event must reference persisted content hash.');
gtAssert(str_contains($daemonSource,"outbox_id"),'Gmail sent event must reference outbox id.');

echo "gmail-thread-reply-test: OK\n";
