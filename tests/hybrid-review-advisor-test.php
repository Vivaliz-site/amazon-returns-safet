<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/HybridReviewAdvisor.php';

function hraSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
$valid=['action'=>'WAIT','rationale'=>'Aguardar evidência conclusiva.','confidence'=>0.9,'uncertainties'=>[],'parameters'=>['date_binding'=>'NONE']];
$heartbeat=tempnam(sys_get_temp_dir(),'chatgpt-session-');
touch($heartbeat);
$socket=sys_get_temp_dir().'/codex-review-'.bin2hex(random_bytes(5)).'.sock';
$server=stream_socket_server('unix://'.$socket,$errno,$errstr);
if(!$server)throw new RuntimeException('Unable to create Codex socket fixture.');
$config=new SvAmazonReturnsConfig([
    'AMAZON_RETURNS_CHATGPT_SESSION_URL'=>'http://127.0.0.1:7777/review',
    'AMAZON_RETURNS_CHATGPT_SESSION_HEARTBEAT_FILE'=>$heartbeat,
    'AMAZON_RETURNS_CHATGPT_SESSION_HEARTBEAT_TTL'=>'30',
    'AMAZON_RETURNS_CODEX_REVIEW_SOCKET'=>$socket,
]);
$calls=[];
$session=function(array $request)use(&$calls,$valid):array{$calls[]='session';return ['status'=>200,'body'=>['model'=>'gpt-session','suggestion'=>$valid]];};
$codex=function(array $request)use(&$calls,$valid):array{$calls[]='codex';return ['model'=>'gpt-codex','suggestion'=>$valid];};
final class HraApi implements SvAmazonReviewAdvisor{
    public int $calls=0;
    public function suggest(array $context):array{$this->calls++;return ['action'=>'CHECK_FINANCES','rationale'=>'API fallback.','confidence'=>0.8,'uncertainties'=>[],'parameters'=>['date_binding'=>'NONE']];}
    public function model():string{return 'claude-fallback';}
    public function provider():string{return 'ANTHROPIC';}
}
$api=new HraApi();
$advisor=new SvAmazonHybridReviewAdvisor($config,$session,$codex,$api);
$suggestion=$advisor->suggest(['facts'=>[],'signature'=>[],'variables'=>[],'evidence_refs'=>[]]);
hraSame('WAIT',$suggestion['action'],'Fresh ChatGPT session must answer first.');
hraSame(['session'],$calls,'ChatGPT success must stop fallback.');
hraSame('CHATGPT_SESSION',$advisor->provider(),'ChatGPT provider provenance.');
hraSame('gpt-session',$advisor->model(),'ChatGPT model provenance.');
hraSame(0,$api->calls,'API must not run after ChatGPT success.');

touch($heartbeat,time()-120);
$calls=[];
$advisor=new SvAmazonHybridReviewAdvisor($config,$session,$codex,$api);
$advisor->suggest([]);
hraSame(['codex'],$calls,'Stale ChatGPT heartbeat must skip immediately to Codex.');
hraSame('CODEX_CHATGPT',$advisor->provider(),'Codex must be second provider.');

fclose($server);
@unlink($socket);
$calls=[];
$advisor=new SvAmazonHybridReviewAdvisor($config,$session,$codex,$api);
$advisor->suggest([]);
hraSame([],$calls,'Unavailable local providers must be skipped.');
hraSame('ANTHROPIC',$advisor->provider(),'API chain follows local providers.');

$futureHeartbeat=tempnam(sys_get_temp_dir(),'chatgpt-future-');touch($futureHeartbeat,time()+10);
$future=new SvAmazonReturnsConfig(['AMAZON_RETURNS_CHATGPT_SESSION_URL'=>'http://127.0.0.1:7777/review','AMAZON_RETURNS_CHATGPT_SESSION_HEARTBEAT_FILE'=>$futureHeartbeat]);
hraSame(false,$future->reviewAiChatGptSessionReady(),'Future heartbeat must fail closed.');
$externalHttp=new SvAmazonReturnsConfig(['AMAZON_RETURNS_CHATGPT_SESSION_URL'=>'http://example.com/review','AMAZON_RETURNS_CHATGPT_SESSION_HEARTBEAT_FILE'=>$heartbeat]);
hraSame(false,$externalHttp->reviewAiChatGptSessionReady(),'Non-loopback HTTP session bridge must be rejected.');
@unlink($heartbeat);@unlink($futureHeartbeat);
echo "hybrid-review-advisor-test: OK\n";