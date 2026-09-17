<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Runtime.php';
function grtSame(mixed $expected,mixed $actual,string $message):void{if($expected!==$actual)throw new RuntimeException($message.' expected='.json_encode($expected).' actual='.json_encode($actual));}

$rate=['status'=>'FAILED','error_class'=>'RuntimeException','error'=>'Gmail API HTTP 403 reason=rateLimitExceeded.'];
$userRate=['status'=>'FAILED','error_class'=>'RuntimeException','error'=>'Gmail API HTTP 403 reason=userRateLimitExceeded.'];
$tooMany=['status'=>'FAILED','error_class'=>'RuntimeException','error'=>'Gmail API HTTP 429 reason=RESOURCE_EXHAUSTED.'];
$permission=['status'=>'FAILED','error_class'=>'RuntimeException','error'=>'Gmail API HTTP 403 reason=insufficientPermissions.'];
$transport=['status'=>'FAILED','error_class'=>'RuntimeException','error'=>'Gmail API transport failed: Operation timed out after 30002 milliseconds with 0 bytes received'];

grtSame(300,SvAmazonReturnsRuntime::gmailRateLimitRetryDelaySeconds('gmail',$rate),'Gmail ingest quota failure must retry in five minutes.');
grtSame(300,SvAmazonReturnsRuntime::gmailRateLimitRetryDelaySeconds('gmail_refund_reconciliation',$userRate),'Refund reconciliation quota failure must retry in five minutes.');
grtSame(300,SvAmazonReturnsRuntime::gmailRateLimitRetryDelaySeconds('gmail',$tooMany),'HTTP 429 Gmail ingest must retry in five minutes.');
grtSame(null,SvAmazonReturnsRuntime::gmailRateLimitRetryDelaySeconds('gmail',$permission),'Permanent permission failures must retain normal cadence.');
grtSame(300,SvAmazonReturnsRuntime::gmailTransportRetryDelaySeconds('gmail',$transport),'Transient Gmail transport failure must retry in five minutes.');
grtSame(null,SvAmazonReturnsRuntime::gmailTransportRetryDelaySeconds('gmail_history_probe',$transport),'Diagnostic probe transport failure must not become a recurring retry loop.');
grtSame(null,SvAmazonReturnsRuntime::gmailTransportRetryDelaySeconds('gmail',$permission),'Permanent Gmail permission failure is not a transport retry.');
grtSame(null,SvAmazonReturnsRuntime::gmailRateLimitRetryDelaySeconds('gmail_history_probe',$rate),'One-shot diagnostic probe must not become a recurring retry loop.');
grtSame(null,SvAmazonReturnsRuntime::gmailRateLimitRetryDelaySeconds('gmail',['status'=>'OK']),'Successful Gmail ingest needs no retry override.');

$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
if(!str_contains($daemon,'gmailRateLimitRetryDelaySeconds'))throw new RuntimeException('Daemon must apply bounded Gmail quota task retry.');
if(!str_contains($daemon,'gmailTransportRetryDelaySeconds'))throw new RuntimeException('Daemon must apply bounded Gmail transport task retry.');
echo "gmail-rate-limit-task-retry-test: OK\n";
