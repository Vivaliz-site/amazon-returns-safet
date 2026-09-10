<?php
declare(strict_types=1);
require_once __DIR__.'/ReviewAdvisor.php';
require_once __DIR__.'/Config.php';
require_once __DIR__.'/OpenAiReviewAdvisor.php';

final class SvAmazonHybridReviewAdvisor implements SvAmazonReviewAdvisor
{
    private ?string $selectedModel=null;
    private ?string $selectedProvider=null;

    public function __construct(
        private SvAmazonReturnsConfig $config,
        private $sessionTransport=null,
        private $codexTransport=null,
        private ?SvAmazonReviewAdvisor $apiAdvisor=null
    ) {}

    public function model():string{return $this->selectedModel??'review-advisor';}
    public function provider():string{return $this->selectedProvider??'UNAVAILABLE';}

    public function suggest(array $reviewContext):array
    {
        if($this->config->reviewAiChatGptSessionReady()){
            try{
                $request=$this->sessionRequest($reviewContext);
                $response=$this->sessionTransport?($this->sessionTransport)($request):$this->postSession($request);
                $status=(int)($response['status']??0);
                if($status<200||$status>=300)throw new RuntimeException('ChatGPT session bridge failed.');
                $body=is_array($response['body']??null)?$response['body']:[];
                $suggestion=SvAmazonOpenAiReviewAdvisor::validate($body['suggestion']??null);
                $this->selectedModel=$this->safeModel($body['model']??null,'chatgpt-session');
                $this->selectedProvider='CHATGPT_SESSION';
                return $suggestion;
            }catch(Throwable){ }
        }

        if($this->config->reviewAiCodexReady()){
            try{
                $request=['context'=>$reviewContext,'schema'=>SvAmazonOpenAiReviewAdvisor::schema()];
                $response=$this->codexTransport?($this->codexTransport)($request):$this->callCodex($request);
                if(!is_array($response))throw new RuntimeException('Codex broker response malformed.');
                $suggestion=SvAmazonOpenAiReviewAdvisor::validate($response['suggestion']??null);
                $this->selectedModel=$this->safeModel($response['model']??null,'codex-chatgpt');
                $this->selectedProvider='CODEX_CHATGPT';
                return $suggestion;
            }catch(Throwable){ }
        }

        $api=$this->apiAdvisor??=new SvAmazonOpenAiReviewAdvisor($this->config);
        $suggestion=$api->suggest($reviewContext);
        $this->selectedModel=method_exists($api,'model')?(string)$api->model():'api-review-advisor';
        $this->selectedProvider=method_exists($api,'provider')?(string)$api->provider():'OPENAI';
        return $suggestion;
    }

    private function sessionRequest(array $context):array
    {
        $headers=['Content-Type'=>'application/json'];$token=$this->config->reviewAiChatGptSessionToken();
        if($token!=='')$headers['Authorization']='Bearer '.$token;
        return ['url'=>$this->config->reviewAiChatGptSessionUrl(),'headers'=>$headers,'body'=>['mode'=>'advisory','context'=>$context,'schema'=>SvAmazonOpenAiReviewAdvisor::schema()],'timeout'=>$this->config->reviewAiChatGptSessionTimeout()];
    }

    private function postSession(array $request):array
    {
        $ch=curl_init((string)$request['url']);if($ch===false)throw new RuntimeException('ChatGPT session transport unavailable.');
        $headers=[];foreach($request['headers'] as $key=>$value)$headers[]=$key.': '.$value;
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($request['body'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_TIMEOUT=>(int)$request['timeout']]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        if($raw===false){curl_close($ch);throw new RuntimeException('ChatGPT session transport failed.');}
        curl_close($ch);$body=json_decode((string)$raw,true,32,JSON_THROW_ON_ERROR);return ['status'=>$status,'body'=>$body];
    }

    private function callCodex(array $request):array
    {
        $errno=0;$errstr='';$socket=@stream_socket_client('unix://'.$this->config->reviewAiCodexSocket(),$errno,$errstr,$this->config->reviewAiCodexTimeout(),STREAM_CLIENT_CONNECT);
        if(!is_resource($socket))throw new RuntimeException('Codex broker unavailable.');
        stream_set_timeout($socket,$this->config->reviewAiCodexTimeout());
        $json=json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(strlen($json)>131072){fclose($socket);throw new RuntimeException('Codex review context too large.');}
        fwrite($socket,$json."\n");$raw=stream_get_contents($socket,131073);fclose($socket);
        if(!is_string($raw)||trim($raw)===''||strlen($raw)>131072)throw new RuntimeException('Codex broker response invalid.');
        $decoded=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($decoded))throw new RuntimeException('Codex broker response malformed.');return $decoded;
    }

    private function safeModel(mixed $model,string $fallback):string
    {
        if(!is_scalar($model))return $fallback;$model=preg_replace('/[^A-Za-z0-9._:-]/','',(string)$model)??'';return $model!==''?substr($model,0,128):$fallback;
    }
}