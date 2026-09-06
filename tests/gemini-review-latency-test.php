<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/OpenAiReviewAdvisor.php';
function glSame(mixed $a,mixed $b,string $m):void{if($a!==$b)throw new RuntimeException($m.' want='.json_encode($a).' got='.json_encode($b));}
$cfg=new SvAmazonReturnsConfig([
 'OPENAI_API_KEY'=>'','ANTHROPIC_API_KEY'=>'','CLAUDE_API_KEY'=>'','GEMINI_API_KEY'=>'gemini-test',
 'GOOGLE_API_KEY'=>'','GOOGLE_IMAGEN_API_KEY'=>'','AMAZON_RETURNS_AI_ENV_FILE'=>'/nonexistent',
]);
$seen=null;$payload=['action'=>'WAIT','rationale'=>'Test','confidence'=>0.9,'uncertainties'=>[],'parameters'=>['date_binding'=>'NONE']];
$transport=function(array $request)use(&$seen,$payload):array{$seen=$request;return ['status'=>200,'body'=>['candidates'=>[['content'=>['parts'=>[['text'=>json_encode($payload)]]]]]]];};
$advisor=new SvAmazonOpenAiReviewAdvisor($cfg,$transport);$suggestion=$advisor->suggest([]);
glSame('WAIT',$suggestion['action'],'Gemini suggestion');
glSame('gemini-3.5-flash-lite',$advisor->model(),'Low-latency Gemini must be the default fallback model');
glSame(true,str_contains((string)($seen['url']??''),'gemini-3.5-flash-lite'),'Request must target low-latency Gemini');
glSame('LOW',$seen['body']['generationConfig']['thinkingConfig']['thinkingLevel']??null,'Gemini fallback must bound thinking latency');
echo "gemini-review-latency-test: OK\n";
