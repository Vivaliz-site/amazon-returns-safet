<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/GmailApi.php';

function gipSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message);
}

$config=new SvAmazonReturnsConfig([
    'GMAIL_OAUTH_CLIENT_ID'=>'cid-a',
    'GMAIL_OAUTH_CLIENT_SECRET'=>'cs-a',
    'GMAIL_OAUTH_REFRESH_TOKEN'=>'rt-a',
    'GOOGLE_OAUTH_CLIENT_ID'=>'cid-b',
    'GOOGLE_OAUTH_CLIENT_SECRET'=>'cs-b',
    'GOOGLE_OAUTH_REFRESH_TOKEN'=>'rt-b',
]);

$oauthCalls=[];
$oauth=static function(string $url,array $fields) use (&$oauthCalls):array{
    $rt=(string)($fields['refresh_token']??'');
    $oauthCalls[]=$rt;
    return ['access_token'=>$rt==='rt-a' ? 'at-a' : 'at-b'];
};

$apiCalls=[];
$transport=static function(string $method,string $url,array $headers,?array $body=null) use (&$apiCalls):array{
    $auth=(string)($headers['Authorization']??'');
    $apiCalls[]=$auth;
    if($auth==='Bearer at-a'){
        return ['status'=>403,'json'=>['error'=>['errors'=>[['reason'=>'insufficientPermissions']]]]];
    }
    return ['status'=>200,'json'=>['messages'=>[]]];
};

$client=new SvAmazonGmailApiClient($config,$transport,null,null,$oauth);
gipSame([],$client->searchMessages('newer_than:1d amazon',1),'Permission fallback read failed.');
gipSame(['rt-a','rt-b'],$oauthCalls,'Permission fallback must advance once.');
gipSame(['Bearer at-a','Bearer at-b'],$apiCalls,'Read must retry with fallback token.');

echo "gmail-oauth-insufficient-permissions-fallback-test: OK\n";
