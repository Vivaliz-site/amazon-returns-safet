<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/OpenAiReviewAdvisor.php';
function mpSame(mixed $a,mixed $b,string $m):void{if($a!==$b)throw new RuntimeException($m.' want='.json_encode($a).' got='.json_encode($b));}
function mpThrows(callable $f,string $m):void{try{$f();}catch(Throwable){return;}throw new RuntimeException($m);}
$cfg=new SvAmazonReturnsConfig([
 'OPENAI_API_KEY'=>'openai-test','ANTHROPIC_API_KEY'=>'anthropic-test','GEMINI_API_KEY'=>'gemini-test',
 'AMAZON_RETURNS_REVIEW_AI_MODEL'=>'openai-model','AMAZON_RETURNS_REVIEW_AI_ANTHROPIC_MODEL'=>'claude-model',
 'AMAZON_RETURNS_REVIEW_AI_GEMINI_MODEL'=>'gemini-model',
]);
$payload=['action'=>'WAIT','rationale'=>'Explicit wait','confidence'=>0.91,'uncertainties'=>[],'parameters'=>['date_binding'=>'PROMISED_DATE']];
$calls=[];
$fallback=function(array $request)use(&$calls,$payload):array{
 $calls[]=$request;
 if(str_contains($request['url'],'api.openai.com'))return ['status'=>503,'body'=>[]];
 if(str_contains($request['url'],'api.anthropic.com'))return ['status'=>429,'body'=>[]];
 return ['status'=>200,'body'=>['candidates'=>[['content'=>['parts'=>[['text'=>json_encode($payload)]]]]]]];
};$advisor=new SvAmazonOpenAiReviewAdvisor($cfg,$fallback);
$s=$advisor->suggest(['facts'=>['x'=>1],'signature'=>[],'variables'=>[],'evidence_refs'=>[]]);
mpSame('WAIT',$s['action'],'Gemini fallback result');
mpSame('gemini-model',$advisor->model(),'successful fallback model must be persisted');
mpSame(3,count($calls),'fallback must try all providers in order');
mpSame(true,str_contains($calls[0]['url'],'api.openai.com'),'OpenAI first');
mpSame(true,str_contains($calls[1]['url'],'api.anthropic.com'),'Claude second');
mpSame(true,str_contains($calls[2]['url'],'generativelanguage.googleapis.com'),'Gemini third');
mpSame(false,$calls[0]['body']['store']??null,'OpenAI store=false');
mpSame('json_schema',$calls[0]['body']['text']['format']['type']??null,'OpenAI schema enforced');
mpSame('json_schema',$calls[1]['body']['output_config']['format']['type']??null,'Claude schema enforced');
mpSame('application/json',$calls[2]['body']['generationConfig']['responseMimeType']??null,'Gemini JSON enforced');
$onlyClaude=new SvAmazonReturnsConfig(['ANTHROPIC_API_KEY'=>'a','AMAZON_RETURNS_REVIEW_AI_ANTHROPIC_MODEL'=>'c']);
mpSame(true,$onlyClaude->reviewAiReady(),'any configured provider makes review AI ready');
mpThrows(fn()=>(new SvAmazonOpenAiReviewAdvisor(new SvAmazonReturnsConfig(['AMAZON_RETURNS_AI_ENV_FILE'=>'/nonexistent']),$fallback))->suggest([]),'no provider accepted');
$secretFile=tempnam(sys_get_temp_dir(),'safet-ai-');
file_put_contents($secretFile,"OPENAI_API_KEY=file-openai\nANTHROPIC_API_KEY=file-anthropic\nGEMINI_API_KEY=file-gemini\n");
$fileCfg=new SvAmazonReturnsConfig(['AMAZON_RETURNS_AI_ENV_FILE'=>$secretFile]);
mpSame('file-openai',$fileCfg->openAiKey(),'OpenAI key can come from protected AI env file');
mpSame('file-anthropic',$fileCfg->anthropicKey(),'Anthropic key can come from protected AI env file');
mpSame('file-gemini',$fileCfg->geminiKey(),'Gemini key can come from protected AI env file');
unlink($secretFile);
echo "multi-provider-review-advisor-test: OK\n";
