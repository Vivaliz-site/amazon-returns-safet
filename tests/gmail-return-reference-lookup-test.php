<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/GmailApi.php';
require_once __DIR__.'/../includes/amazon-returns/GmailReturnReferenceLookup.php';

function grlSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why);}
$calls=[];
$body='Notificação de autorização de devolução referente ao pedido de número 702-1111111-2222222. Código TBR015328001.';
$encoded=rtrim(strtr(base64_encode($body),'+/','-_'),'=');
$transport=static function(string $method,string $url,array $headers,?array $payload=null)use(&$calls,$encoded):array{
    $calls[]=$url;
    if(str_contains($url,'/messages?'))return ['status'=>200,'json'=>['messages'=>[['id'=>'m-tbr']]]];
    if(str_contains($url,'/messages/m-tbr?'))return ['status'=>200,'json'=>[
        'id'=>'m-tbr','threadId'=>'t-tbr','internalDate'=>'1788283827000','payload'=>[
            'headers'=>[
                ['name'=>'From','value'=>'Amazon <donotreply@amazon.com>'],
                ['name'=>'Subject','value'=>'Notificação de autorização de devolução referente ao pedido de número 702-1111111-2222222'],
            ],'mimeType'=>'text/plain','body'=>['data'=>$encoded],
        ],
    ]];
    throw new RuntimeException('Unexpected Gmail URL '.$url);
};
$gmail=new SvAmazonGmailApiClient(new SvAmazonReturnsConfig(['GMAIL_OAUTH_ACCESS_TOKEN'=>'test-token']),$transport);
$lookup=new SvAmazonGmailReturnReferenceLookup($gmail);
$result=$lookup->find('tbr015328001');
grlSame('702-1111111-2222222',$result['order_id']??null,'TBR lookup must resolve the Amazon order.');
grlSame('TBR015328001',$result['event']['return_tracking_id']??null,'TBR lookup must return exact parsed return evidence.');
$queryUrl=$calls[0]??'';
grlSame(true,str_contains(urldecode($queryUrl),'TBR015328001'),'Gmail lookup must search the exact TBR token.');
$invalid=false;
try{$lookup->find('not-a-tbr');}catch(InvalidArgumentException){$invalid=true;}
grlSame(true,$invalid,'Invalid TBR must be rejected before Gmail search.');

echo "gmail-return-reference-lookup-test: OK\n";
