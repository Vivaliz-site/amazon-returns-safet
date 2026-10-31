<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/GmailApi.php';

function gofSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.json_encode($expected).' actual='.json_encode($actual));
}
function gofAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

gofAssert(method_exists(SvAmazonGmailApiClient::class,'oauthCredentialCandidates'),'Gmail client must expose a testable credential-candidate selector.');
gofAssert(method_exists(SvAmazonGmailApiClient::class,'refreshFromCandidates'),'Gmail client must isolate refresh fallback logic.');

$config=new SvAmazonReturnsConfig([
    'GMAIL_OAUTH_CLIENT_ID'=>'client-primary',
    'GMAIL_OAUTH_CLIENT_SECRET'=>'secret-primary',
    'GMAIL_OAUTH_REFRESH_TOKEN'=>'refresh-stale',
    'GOOGLE_OAUTH_CLIENT_ID'=>'client-fallback',
    'GOOGLE_OAUTH_CLIENT_SECRET'=>'secret-fallback',
    'GOOGLE_OAUTH_REFRESH_TOKEN'=>'refresh-good',
]);

$oauthCalls=[];
$oauthTransport=static function(string $url,array $fields) use (&$oauthCalls):array{
    $oauthCalls[]=[
        'client_id'=>$fields['client_id']??null,
        'refresh_token'=>$fields['refresh_token']??null,
    ];
    if(($fields['refresh_token']??'')==='refresh-stale'){
        throw new RuntimeException('Gmail OAuth HTTP 400 error=invalid_grant.');
    }
    return ['access_token'=>'fallback-access'];
};
$apiTransport=static function(string $method,string $url,array $headers,?array $body=null):array{
    gofSame('Bearer fallback-access',$headers['Authorization']??null,'Fallback access token must authorize Gmail API reads.');
    return ['status'=>200,'json'=>['messages'=>[]]];
};
$api=new SvAmazonGmailApiClient($config,$apiTransport,null,null,$oauthTransport);
gofSame([],$api->searchMessages('newer_than:1d reembolso iniciado',1),'Read should succeed through the fallback refresh token.');
gofSame([
    ['client_id'=>'client-primary','refresh_token'=>'refresh-stale'],
    ['client_id'=>'client-fallback','refresh_token'=>'refresh-good'],
],$oauthCalls,'Only the distinct configured fallback credential may run after invalid_grant.');

$nonGrantCalls=0;
$nonGrantOauth=static function(string $url,array $fields) use (&$nonGrantCalls):array{
    $nonGrantCalls++;
    throw new RuntimeException('Gmail OAuth HTTP 400 error=invalid_client.');
};
$blocked=new SvAmazonGmailApiClient($config,$apiTransport,null,null,$nonGrantOauth);
$message='';
try{$blocked->searchMessages('newer_than:1d reembolso iniciado',1);}catch(RuntimeException $e){$message=$e->getMessage();}
gofSame(1,$nonGrantCalls,'Non-invalid_grant OAuth failures must not try another credential.');
gofAssert(str_contains($message,'invalid_client'),'Original non-retryable OAuth reason must remain visible.');

$duplicateConfig=new SvAmazonReturnsConfig([
    'GMAIL_OAUTH_CLIENT_ID'=>'same-client',
    'GMAIL_OAUTH_CLIENT_SECRET'=>'same-secret',
    'GMAIL_OAUTH_REFRESH_TOKEN'=>'same-refresh',
    'GOOGLE_OAUTH_CLIENT_ID'=>'same-client',
    'GOOGLE_OAUTH_CLIENT_SECRET'=>'same-secret',
    'GOOGLE_OAUTH_REFRESH_TOKEN'=>'same-refresh',
]);
$candidates=(new ReflectionMethod(SvAmazonGmailApiClient::class,'oauthCredentialCandidates'))->invoke(null,$duplicateConfig);
gofSame(1,count($candidates),'Identical primary/fallback credentials must be deduplicated.');

echo "gmail-oauth-invalid-grant-fallback-test: OK\n";
