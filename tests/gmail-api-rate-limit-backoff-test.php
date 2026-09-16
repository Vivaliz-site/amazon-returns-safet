<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/GmailApi.php';
function grbAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function grbSame(mixed $expected,mixed $actual,string $message):void{if($expected!==$actual)throw new RuntimeException($message.' expected='.json_encode($expected).' actual='.json_encode($actual));}

$calls=[];$sleeps=[];$profileAttempts=0;
$transport=static function(string $method,string $url,array $headers,?array $body=null)use(&$calls,&$profileAttempts):array{
    $calls[]=[$method,$url];
    if(str_contains($url,'/profile')){
        $profileAttempts++;
        if($profileAttempts<3)return ['status'=>403,'json'=>['error'=>['errors'=>[['reason'=>'rateLimitExceeded']]]]];
        return ['status'=>200,'json'=>['historyId'=>'200']];
    }
    if(str_contains($url,'/messages?'))return ['status'=>200,'json'=>['messages'=>[]]];
    throw new RuntimeException('Unexpected URL '.$url);
};
$sleep=static function(int $microseconds)use(&$sleeps):void{$sleeps[]=$microseconds;};
$jitter=static fn():int=>0;
$api=new SvAmazonGmailApiClient(new SvAmazonReturnsConfig(['GMAIL_OAUTH_ACCESS_TOKEN'=>'test-token']),$transport,$sleep,$jitter);
$result=$api->pull(null,1);
grbSame('200',$result['cursor'],'Quota recovery must return the successful Gmail response.');
grbSame([1000000,2000000],$sleeps,'Quota retries must use exponential delays.');
grbSame(3,$profileAttempts,'Transient 403 rateLimitExceeded must retry the same idempotent GET.');
$permanentCalls=0;$permanentSleeps=[];
$permanentTransport=static function()use(&$permanentCalls):array{
    $permanentCalls++;
    return ['status'=>403,'json'=>['error'=>['errors'=>[['reason'=>'insufficientPermissions']]]]];
};
$permanentApi=new SvAmazonGmailApiClient(new SvAmazonReturnsConfig(['GMAIL_OAUTH_ACCESS_TOKEN'=>'test-token']),$permanentTransport,static function(int $us)use(&$permanentSleeps):void{$permanentSleeps[]=$us;},$jitter);
$error='';try{$permanentApi->pull(null,1);}catch(RuntimeException $e){$error=$e->getMessage();}
grbSame(1,$permanentCalls,'Permanent Gmail 403 must fail immediately.');
grbSame([],$permanentSleeps,'Permanent Gmail 403 must not consume backoff time.');
grbAssert(str_contains($error,'insufficientPermissions'),'Permanent failure reason must remain diagnosable.');

$tooManyCalls=0;$tooManySleeps=[];
$tooManyTransport=static function()use(&$tooManyCalls):array{
    $tooManyCalls++;
    if($tooManyCalls===1)return ['status'=>429,'json'=>['error'=>['status'=>'RESOURCE_EXHAUSTED']]];
    if($tooManyCalls===2)return ['status'=>200,'json'=>['historyId'=>'300']];
    return ['status'=>200,'json'=>['messages'=>[]]];
};
$tooManyApi=new SvAmazonGmailApiClient(new SvAmazonReturnsConfig(['GMAIL_OAUTH_ACCESS_TOKEN'=>'test-token']),$tooManyTransport,static function(int $us)use(&$tooManySleeps):void{$tooManySleeps[]=$us;},$jitter);
$tooManyApi->pull(null,1);
grbSame([1000000],$tooManySleeps,'HTTP 429 read must use the same quota backoff.');
$sendCalls=0;$sendSleeps=[];
$sendTransport=static function(string $method,string $url)use(&$sendCalls):array{
    $sendCalls++;
    return ['status'=>429,'json'=>['error'=>['status'=>'RESOURCE_EXHAUSTED']]];
};
$sendApi=new SvAmazonGmailApiClient(new SvAmazonReturnsConfig(['GMAIL_OAUTH_ACCESS_TOKEN'=>'test-token']),$sendTransport,static function(int $us)use(&$sendSleeps):void{$sendSleeps[]=$us;},$jitter);
$sendError='';try{$sendApi->send('safe-t-review@example.com','subject','body');}catch(RuntimeException $e){$sendError=$e->getMessage();}
grbSame(1,$sendCalls,'Gmail POST send must never be automatically retried.');
grbSame([],$sendSleeps,'Gmail POST send must never enter read backoff.');
grbAssert(str_contains($sendError,'HTTP 429'),'Send failure must remain explicit.');

$exhaustCalls=0;$exhaustSleeps=[];
$exhaustTransport=static function()use(&$exhaustCalls):array{
    $exhaustCalls++;
    return ['status'=>403,'json'=>['error'=>['errors'=>[['reason'=>'rateLimitExceeded']]]]];
};
$exhaustApi=new SvAmazonGmailApiClient(new SvAmazonReturnsConfig(['GMAIL_OAUTH_ACCESS_TOKEN'=>'test-token']),$exhaustTransport,static function(int $us)use(&$exhaustSleeps):void{$exhaustSleeps[]=$us;},$jitter);
$exhaustError='';try{$exhaustApi->pull(null,1);}catch(RuntimeException $e){$exhaustError=$e->getMessage();}
grbSame(4,$exhaustCalls,'A single Gmail read must not block the daemon through a minute-long retry chain.');
grbSame([1000000,2000000,4000000],$exhaustSleeps,'Per-request retry budget must stay short; task-level retry handles longer quota windows.');
grbAssert(str_contains($exhaustError,'rateLimitExceeded'),'Exhausted quota failure must remain classifiable by the daemon.');

$batchHistoryAttempts=0;$batchSleeps=[];
$batchTransport=static function(string $method,string $url,array $headers,?array $body=null)use(&$batchHistoryAttempts):array{
    if(str_contains($url,'/profile'))return ['status'=>200,'json'=>['historyId'=>'500']];
    if(str_contains($url,'/history?')){
        $batchHistoryAttempts++;
        if($batchHistoryAttempts<3)return ['status'=>403,'json'=>['error'=>['errors'=>[['reason'=>'rateLimitExceeded']]]]];
        return ['status'=>200,'json'=>['history'=>[['id'=>'450','messagesAdded'=>[['message'=>['id'=>'m-rl1']]]]]]];
    }
    if(str_contains($url,'/messages/m-rl1?'))return ['status'=>200,'json'=>[
        'id'=>'m-rl1','threadId'=>'t-rl1','internalDate'=>'1788283827000',
        'payload'=>['headers'=>[['name'=>'From','value'=>'Amazon <donotreply@amazon.com>'],['name'=>'Subject','value'=>'Assunto']],'mimeType'=>'text/plain','body'=>['data'=>'']],
    ]];
    throw new RuntimeException('Unexpected URL '.$url);
};
$batchApi=new SvAmazonGmailApiClient(new SvAmazonReturnsConfig(['GMAIL_OAUTH_ACCESS_TOKEN'=>'test-token']),$batchTransport,static function(int $us)use(&$batchSleeps):void{$batchSleeps[]=$us;},$jitter);
$batchResult=$batchApi->pullIncrementalBatch('400',50,50);
grbSame(1,count($batchResult['messages']),'Bounded batch must recover from a transient history rate limit and still fetch the message.');
grbSame([1000000,2000000],$batchSleeps,'Bounded batch history call must reuse the GET-only quota backoff.');
grbSame('500',$batchResult['checkpoint_cursor'],'Fully drained batch checkpoints to the mailbox history id.');

$permHistorySleeps=[];
$permHistoryTransport=static function(string $method,string $url):array{
    if(str_contains($url,'/profile'))return ['status'=>200,'json'=>['historyId'=>'500']];
    return ['status'=>403,'json'=>['error'=>['errors'=>[['reason'=>'insufficientPermissions']]]]];
};
$permHistoryApi=new SvAmazonGmailApiClient(new SvAmazonReturnsConfig(['GMAIL_OAUTH_ACCESS_TOKEN'=>'test-token']),$permHistoryTransport,static function(int $us)use(&$permHistorySleeps):void{$permHistorySleeps[]=$us;},$jitter);
$permHistoryError='';
try{$permHistoryApi->pullIncrementalBatch('400',50,50);}catch(RuntimeException $e){$permHistoryError=$e->getMessage();}
grbSame([],$permHistorySleeps,'Permanent Gmail 403 on history must not consume backoff time.');
grbAssert(str_contains($permHistoryError,'insufficientPermissions'),'Bounded batch failure reason must remain diagnosable.');

echo "gmail-api-rate-limit-backoff-test: OK\n";
