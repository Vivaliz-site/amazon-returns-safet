<?php
declare(strict_types=1);
require_once __DIR__.'/ReviewAdvisor.php';
require_once __DIR__.'/Config.php';
final class SvAmazonOpenAiReviewAdvisor implements SvAmazonReviewAdvisor
{
    private const ACTIONS=['WAIT','CHECK_FINANCES','SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE','CLOSE_LOSS'];
    private ?string $lastModel=null;
    public function __construct(private SvAmazonReturnsConfig $config,private $transport=null){}
    public function model():string
    {
        if($this->lastModel!==null)return $this->lastModel;
        if($this->config->openAiKey()!=='')return $this->config->reviewAiModel();
        if($this->config->anthropicKey()!=='')return $this->config->reviewAiAnthropicModel();
        return $this->config->reviewAiGeminiModel();
    }
    public function suggest(array $reviewContext):array
    {
        if(!$this->config->reviewAiReady())throw new RuntimeException('Review AI is not configured.');
        foreach($this->providers() as $provider){
            try{
                $suggestion=$this->callProvider($provider,$reviewContext);
                $this->lastModel=$provider['model'];
                return $suggestion;
            }catch(Throwable){continue;}
        }
        throw new RuntimeException('Review AI providers unavailable.');
    }
    private function providers():array
    {
        $providers=[];
        if($this->config->openAiKey()!=='')$providers[]=['name'=>'openai','key'=>$this->config->openAiKey(),'model'=>$this->config->reviewAiModel()];
        if($this->config->anthropicKey()!=='')$providers[]=['name'=>'anthropic','key'=>$this->config->anthropicKey(),'model'=>$this->config->reviewAiAnthropicModel()];
        if($this->config->geminiKey()!=='')$providers[]=['name'=>'gemini','key'=>$this->config->geminiKey(),'model'=>$this->config->reviewAiGeminiModel()];
        return $providers;
    }
    private function callProvider(array $provider,array $context):array
    {
        $request=match($provider['name']){
            'openai'=>$this->openAiRequest($provider,$context),
            'anthropic'=>$this->anthropicRequest($provider,$context),
            'gemini'=>$this->geminiRequest($provider,$context),
            default=>throw new RuntimeException('Unknown review AI provider.'),
        };
        $response=$this->transport?($this->transport)($request):$this->post($request);
        $status=(int)($response['status']??0);
        if($status<200||$status>=300)throw new RuntimeException('Review AI request failed.');
        $text=$this->extractText((string)$provider['name'],$response['body']??[]);
        if($text==='')throw new RuntimeException('Review AI output missing.');
        try{$decoded=json_decode($text,true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new RuntimeException('Review AI output malformed.',0,$e);}
        return self::validate($decoded);
    }
    private function openAiRequest(array $provider,array $context):array
    {
        return ['url'=>'https://api.openai.com/v1/responses','headers'=>[
            'Authorization'=>'Bearer '.$provider['key'],'Content-Type'=>'application/json'],
            'body'=>['model'=>$provider['model'],'store'=>false,'input'=>$this->openAiInput($context),
                'text'=>['format'=>['type'=>'json_schema','name'=>'safe_t_review_suggestion','strict'=>true,'schema'=>self::schema()]]]];
    }
    private function anthropicRequest(array $provider,array $context):array
    {
        return ['url'=>'https://api.anthropic.com/v1/messages','headers'=>[
            'x-api-key'=>$provider['key'],'anthropic-version'=>'2023-06-01','Content-Type'=>'application/json'],
            'body'=>['model'=>$provider['model'],'max_tokens'=>1200,'system'=>$this->systemPrompt(),
                'messages'=>[['role'=>'user','content'=>$this->contextJson($context)]],
                'output_config'=>['format'=>['type'=>'json_schema','schema'=>self::schema()]]]];
    }
    private function geminiRequest(array $provider,array $context):array
    {
        return ['url'=>'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($provider['model']).':generateContent',
            'headers'=>['x-goog-api-key'=>$provider['key'],'Content-Type'=>'application/json'],
            'body'=>['systemInstruction'=>['parts'=>[['text'=>$this->systemPrompt()]]],
                'contents'=>[['role'=>'user','parts'=>[['text'=>$this->contextJson($context)]]]],
                'generationConfig'=>['responseMimeType'=>'application/json','responseSchema'=>self::schema()]]];
    }
    private function extractText(string $provider,mixed $body):string
    {
        if(!is_array($body))return '';
        if($provider==='openai'){
            foreach(($body['output']??[]) as $out)foreach(($out['content']??[]) as $c)
                if(($c['type']??'')==='output_text'&&is_string($c['text']??null))return trim($c['text']);
        }
        if($provider==='anthropic'){
            foreach(($body['content']??[]) as $c)
                if(($c['type']??'')==='text'&&is_string($c['text']??null))return trim($c['text']);
        }
        if($provider==='gemini'){
            foreach(($body['candidates']??[]) as $candidate)foreach(($candidate['content']['parts']??[]) as $part)
                if(is_string($part['text']??null))return trim($part['text']);
        }
        return '';
    }
    private function systemPrompt():string
    {
        return 'Recommend one SAFE-T review action using only supplied structured facts. Do not invent dates or execute actions. Return only JSON matching the supplied schema.';
    }
    private function contextJson(array $c):string
    {
        return json_encode(['facts'=>$c['facts']??[],'signature'=>$c['signature']??[],'variables'=>$c['variables']??[],'evidence_refs'=>$c['evidence_refs']??[]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    public static function schema():array
    {
        return ['type'=>'object','additionalProperties'=>false,
            'required'=>['action','rationale','confidence','uncertainties','parameters'],
            'properties'=>[
                'action'=>['type'=>'string','enum'=>self::ACTIONS],
                'rationale'=>['type'=>'string','minLength'=>1,'maxLength'=>2000],
                'confidence'=>['type'=>'number','minimum'=>0,'maximum'=>1],
                'uncertainties'=>['type'=>'array','items'=>['type'=>'string','maxLength'=>500],'maxItems'=>10],
                'parameters'=>['type'=>'object','additionalProperties'=>false,'required'=>['date_binding'],
                    'properties'=>['date_binding'=>['type'=>'string','enum'=>['NONE','PROMISED_DATE','APPEAL_DEADLINE']]]],
            ]];
    }
    private static function validate(mixed $s):array
    {
        if(!is_array($s)||array_diff(array_keys($s),['action','rationale','confidence','uncertainties','parameters'])!==[]
            ||!in_array($s['action']??'',self::ACTIONS,true)||!is_string($s['rationale']??null)||trim($s['rationale'])===''
            ||strlen($s['rationale'])>2000||!is_numeric($s['confidence']??null)||(float)$s['confidence']<0||(float)$s['confidence']>1
            ||!is_array($s['uncertainties']??null)||count($s['uncertainties'])>10||!is_array($s['parameters']??null)
            ||array_diff(array_keys($s['parameters']),['date_binding'])!==[]
            ||!in_array($s['parameters']['date_binding']??'',['NONE','PROMISED_DATE','APPEAL_DEADLINE'],true))
            throw new InvalidArgumentException('Invalid review AI suggestion.');
        foreach($s['uncertainties'] as $u)if(!is_string($u)||strlen($u)>500)throw new InvalidArgumentException('Invalid review AI uncertainty.');
        $s['confidence']=(float)$s['confidence'];return $s;
    }
    private function openAiInput(array $c):array
    {
        return [
            ['role'=>'system','content'=>[['type'=>'input_text','text'=>$this->systemPrompt()]]],
            ['role'=>'user','content'=>[['type'=>'input_text','text'=>$this->contextJson($c)]]],
        ];
    }
    private function post(array $request):array
    {
        $ch=curl_init($request['url']);if($ch===false)throw new RuntimeException('Review AI transport unavailable.');
        $headers=[];foreach($request['headers'] as $k=>$v)$headers[]=$k.': '.$v;
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_POSTFIELDS=>json_encode($request['body'],JSON_THROW_ON_ERROR),CURLOPT_TIMEOUT=>30]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        if($raw===false){$err=curl_error($ch);curl_close($ch);throw new RuntimeException('Review AI transport failed: '.substr($err,0,120));}
        curl_close($ch);
        try{$body=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new RuntimeException('Review AI HTTP body malformed.',0,$e);}
        return ['status'=>$status,'body'=>$body];
    }
}
