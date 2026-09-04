<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/Config.php';
require_once __DIR__ . '/../includes/amazon-returns/AmazonSpApiClient.php';

function acAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function acSame(mixed $expected,mixed $actual,string $message):void{if($expected!==$actual)throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true));}

$calls=[];
$config=new SvAmazonReturnsConfig([
    'AMAZON_LWA_CLIENT_ID'=>'client-test',
    'AMAZON_LWA_CLIENT_SECRET'=>'secret-test',
    'AMAZON_LWA_REFRESH_TOKEN'=>'refresh-test',
    'AMAZON_MARKETPLACE_ID'=>'A2Q3Y263D00KWC',
    'AMAZON_SP_API_ENDPOINT'=>'https://sellingpartnerapi-na.amazon.com',
]);
$http=static function(string $method,string $url,array $headers,?string $body)use(&$calls):array{
    $calls[]=compact('method','url','headers','body');
    if(str_contains($url,'api.amazon.com/auth/o2/token'))return['status'=>200,'request_id'=>'','json'=>['access_token'=>'token-test']];
    return['status'=>200,'request_id'=>'req-test','json'=>['ok'=>true]];
};
$client=new SvAmazonSpApiClient($config,$http);
acSame('A2Q3Y263D00KWC',$client->marketplaceId(),'Configured marketplace must be used.');
$response=$client->request('GET','/orders/test',['z'=>'2','a'=>'1']);
acSame(200,$response['status'],'Transport status must normalize.');
acSame('req-test',$response['request_id'],'Transport request ID must normalize.');
acSame(['ok'=>true],$response['data'],'Transport JSON must normalize.');
acAssert(str_ends_with($calls[1]['url'],'/orders/test?a=1&z=2'),'SP-API query must be deterministic.');
acSame('token-test',$calls[1]['headers']['x-amz-access-token'] ?? null,'LWA token must be sent only as access-token header.');

$source=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/SpApi.php');
acAssert(!str_contains($source,'marketplace/AmazonPublisher.php'),'Standalone SP-API must not depend on website marketplace runtime.');
acAssert(str_contains($source,'AmazonSpApiClient.php'),'SP-API facade must load the standalone client.');

echo "amazon-sp-api-client-test: OK\n";