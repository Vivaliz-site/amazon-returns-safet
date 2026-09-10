<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/OpenAiReviewAdvisor.php';
function mpSame(mixed $a,mixed $b,string $m):void{if($a!==$b)throw new RuntimeException($m.' want='.json_encode($a).' got='.json_encode($b));}
function mpThrows(callable $f,string $m):void{try{$f();}catch(Throwable){return;}throw new RuntimeException($m);}
$cfg=new SvAmazonReturnsConfig([
 'OPENAI_API_KEY'=>'openai-test','ANTHROPIC_API_KEY'=>'anthropic-test','GEMINI_API_KEY'=>'gemini-test',
 'AMAZON_RETURNS_REVIEW_AI_MODEL'=>'openai-model','AMAZON_RETURNS_REVIEW_AI_ANTHROPIC_MODEL'=>'claude-model',
 'AMAZON_RETURNS_REVIEW_AI_GEMINI_MODEL'=>'gemini-model','AMAZON_RETURNS_REVIEW_AI_GEMINI_FALLBACK_MODEL'=>'gemini-fallback-model',
]);
$payload=['action'=>'WAIT','rationale'=>'Explicit wait','confidence'=>0.91,'uncertainties'=>[],'parameters'=>['date_binding'=>'PROMISED_DATE']];
$calls=[];
$fallback=function(array $request)use(&$calls,$payload):array{
 $calls[]=$request;
 if(str_contains($request['url'],'api.anthropic.com'))return ['status'=>429,'body'=>[]];
 if(str_contains($request['url'],'generativelanguage.googleapis.com'))return ['status'=>503,'body'=>[]];
 if(str_contains($request['url'],'api.openai.com'))return ['status'=>200,'body'=>['output'=>[['content'=>[['type'=>'output_text','text'=>json_encode($payload)]]]]]]];
 return ['status'=>500,'body'=>[]];
};
$advisor=new SvAmazonOpenAiReviewAdvisor($cfg,$fallback);
$s=$advisor->suggest(['facts'=>['x'=>1],'signature'=>[],'variables'=>[],'evidence_refs'=>[]]);
mpSame('WAIT',$s['action'],'OpenAI final fallback result');
mpSame('openai-model',$advisor->model(),'successful fallback model must be persisted');
mpSame('OPENAI',$advisor->provider(),'successful fallback provider must be persisted');
mpSame(4,count($calls),'API fallback must try Claude, both Gemini models, then OpenAI');
mpSame(true,str_contains($calls[0]['url'],'api.anthropic.com'),'Claude API first after local advisors');
mpSame(true,str_contains($calls[1]['url'],'gemini-model'),'Gemini primary second');
mpSame(true,str_contains($calls[2]['url'],'gemini-fallback-model'),'Gemini fallback third');
mpSame(true,str_contains($calls[3]['url'],'api.openai.com'),'OpenAI API must be last');
mpSame('json_schema',$calls[0]['body']['output_config']['format']['type']??null,'Claude schema enforced');
mpSame('application/json',$calls[1]['body']['generationConfig']['responseMimeType']??null,'Gemini JSON enforced');
mpSame(false,$calls[3]['body']['store']??null,'OpenAI store=false');
mpSame('json_schema',$calls[3]['body']['text']['format']['type']??null,'OpenAI schema enforced');
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