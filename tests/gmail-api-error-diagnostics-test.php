<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/GmailApi.php';
function gedAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$transport=static fn(string $method,string $url,array $headers,?array $body=null):array=>['status'=>403,'json'=>['error'=>['status'=>'PERMISSION_DENIED','message'=>'sensitive server detail','errors'=>[['reason'=>'insufficientPermissions']]]]];
$api=new SvAmazonGmailApiClient(new SvAmazonReturnsConfig(['GMAIL_OAUTH_ACCESS_TOKEN'=>'test-token']),$transport);
$message='';
try{$api->pull(null);}catch(RuntimeException $e){$message=$e->getMessage();}
gedAssert(str_contains($message,'Gmail API HTTP 403'),'HTTP status must remain visible.');
gedAssert(str_contains($message,'insufficientPermissions'),'Safe Google API reason must be visible for diagnosis.');
gedAssert(!str_contains($message,'sensitive server detail'),'Google free-form error messages must never enter operational logs.');
echo "gmail-api-error-diagnostics-test: OK\n";
