<?php
declare(strict_types=1);
require_once __DIR__.'/ReviewAdvisor.php';
require_once __DIR__.'/Config.php';
final class SvAmazonOpenAiReviewAdvisor implements SvAmazonReviewAdvisor
{
    private const ACTIONS=['WAIT','CHECK_FINANCES','SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE','CLOSE_LOSS'];
    public function __construct(private SvAmazonReturnsConfig $config,private $transport=null){}
    public function model():string{return $this->config->reviewAiModel();}
    public function suggest(array $reviewContext):array
    {
        if(!$this->config->reviewAiReady())throw new RuntimeException('Review AI is not configured.');
        $body=['model'=>$this->model(),'store'=>false,'input'=>$this->input($reviewContext),'text'=>['format'=>['type'=>'json_schema','name'=>'safe_t_review_suggestion','strict'=>true,'schema'=>self::schema()]]];
        $request=['url'=>'https://api.openai.com/v1/responses','headers'=>['Authorization'=>'Bearer '.$this->config->openAiKey(),'Content-Type'=>'application/json'],'body'=>$body];
        $response=$this->transport?($this->transport)($request):$this->post($request);$status=(int)($response['status']??0);if($status<200||$status>=300)throw new RuntimeException('Review AI request failed.');
        $text=null;foreach(($response['body']['output']??[]) as $out)foreach(($out['content']??[]) as $c)if(($c['type']??'')==='output_text'){$text=$c['text']??null;break 2;}
        if(!is_string($text)||trim($text)==='')throw new RuntimeException('Review AI output missing.');
        try{$decoded=json_decode($text,true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new RuntimeException('Review AI output malformed.',0,$e);}return self::validate($decoded);
    }
    public static function schema():array{return ['type'=>'object','additionalProperties'=>false,'required'=>['action','rationale','confidence','uncertainties','parameters'],'properties'=>['action'=>['type'=>'string','enum'=>self::ACTIONS],'rationale'=>['type'=>'string','minLength'=>1,'maxLength'=>2000],'confidence'=>['type'=>'number','minimum'=>0,'maximum'=>1],'uncertainties'=>['type'=>'array','items'=>['type'=>'string','maxLength'=>500],'maxItems'=>10],'parameters'=>['type'=>'object','additionalProperties'=>false,'required'=>['date_binding'],'properties'=>['date_binding'=>['type'=>'string','enum'=>['NONE','PROMISED_DATE','APPEAL_DEADLINE']]]]]];}
    private static function validate(mixed $s):array
    {if(!is_array($s)||array_diff(array_keys($s),['action','rationale','confidence','uncertainties','parameters'])!==[]||!in_array($s['action']??'',self::ACTIONS,true)||!is_string($s['rationale']??null)||trim($s['rationale'])===''||strlen($s['rationale'])>2000||!is_numeric($s['confidence']??null)||(float)$s['confidence']<0||(float)$s['confidence']>1||!is_array($s['uncertainties']??null)||count($s['uncertainties'])>10||!is_array($s['parameters']??null)||array_diff(array_keys($s['parameters']),['date_binding'])!==[]||!in_array($s['parameters']['date_binding']??'',['NONE','PROMISED_DATE','APPEAL_DEADLINE'],true))throw new InvalidArgumentException('Invalid review AI suggestion.');foreach($s['uncertainties'] as $u)if(!is_string($u)||strlen($u)>500)throw new InvalidArgumentException('Invalid review AI uncertainty.');$s['confidence']=(float)$s['confidence'];return $s;}
    private function input(array $c):array{return [['role'=>'system','content'=>[['type'=>'input_text','text'=>'Recommend one SAFE-T review action using only supplied structured facts. Do not invent dates or execute actions.']]],['role'=>'user','content'=>[['type'=>'input_text','text'=>json_encode(['facts'=>$c['facts']??[],'signature'=>$c['signature']??[],'variables'=>$c['variables']??[],'evidence_refs'=>$c['evidence_refs']??[]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]]]];}
    private function post(array $request):array{$ch=curl_init($request['url']);if($ch===false)throw new RuntimeException('Review AI transport unavailable.');$headers=[];foreach($request['headers'] as $k=>$v)$headers[]=$k.': '.$v;curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($request['body'],JSON_THROW_ON_ERROR),CURLOPT_TIMEOUT=>30]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);if($raw===false){$err=curl_error($ch);curl_close($ch);throw new RuntimeException('Review AI transport failed: '.substr($err,0,120));}curl_close($ch);try{$body=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new RuntimeException('Review AI HTTP body malformed.',0,$e);}return ['status'=>$status,'body'=>$body];}
}
