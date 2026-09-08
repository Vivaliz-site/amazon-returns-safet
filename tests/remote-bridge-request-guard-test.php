<?php
declare(strict_types=1);

$guardPath=__DIR__.'/../includes/amazon-returns/HttpJsonRequest.php';
if(!is_file($guardPath))throw new RuntimeException('HTTP JSON request guard must exist.');
require_once $guardPath;

function rbrAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function rbrSame(mixed $expected,mixed $actual,string $message):void{if($expected!==$actual)throw new RuntimeException($message.' expected='.json_encode($expected).' actual='.json_encode($actual));}

rbrSame(['operation'=>'heartbeat'],SvAmazonReturnsHttpJsonRequest::decodeObject('{"operation":"heartbeat"}',131072),'Valid bridge JSON must decode.');

$tooLarge=false;
try{SvAmazonReturnsHttpJsonRequest::decodeObject(str_repeat('x',131073),131072);}catch(LengthException){$tooLarge=true;}
rbrAssert($tooLarge,'Actual request body over 128 KiB must be rejected even without Content-Length.');

$invalid=false;
try{SvAmazonReturnsHttpJsonRequest::decodeObject('{broken',131072);}catch(UnexpectedValueException){$invalid=true;}
rbrAssert($invalid,'Malformed JSON must be rejected deterministically.');

$scalar=false;
try{SvAmazonReturnsHttpJsonRequest::decodeObject('"heartbeat"',131072);}catch(UnexpectedValueException){$scalar=true;}
rbrAssert($scalar,'Bridge request body must be a JSON object/array contract, not a scalar.');

$vhost=(string)file_get_contents(__DIR__.'/../deploy/apache/returns.shopvivaliz.com.br.conf');
rbrAssert(str_contains($vhost,'LimitRequestBody 131072'),'Apache must reject oversized bridge bodies before PHP processing.');
foreach(['bridge.php','status-bridge.php'] as $endpoint){
    $source=(string)file_get_contents(__DIR__.'/../api/amazon-returns/'.$endpoint);
    rbrAssert(str_contains($source,'SvAmazonReturnsHttpJsonRequest::decodeObject'),'Bridge endpoint '.$endpoint.' must validate actual raw body size.');
}

echo "remote-bridge-request-guard-test: OK\n";
