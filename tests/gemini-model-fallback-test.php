<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/OpenAiReviewAdvisor.php';
function gfSame(mixed $a,mixed $b,string $m):void{if($a!==$b)throw new RuntimeException($m.' want='.json_encode($a).' got='.json_encode($b));}
$cfg=new SvAmazonReturnsConfig(['OPENAI_API_KEY'=>'o','ANTHROPIC_API_KEY'=>'a','GEMINI_API_KEY'=>'g','AMAZON_RETURNS_REVIEW_AI_MODEL'=>'o-model','AMAZON_RETURNS_REVIEW_AI_ANTHROPIC_MODEL'=>'c-model','AMAZON_RETURNS_REVIEW_AI_GEMINI_MODEL'=>'gemini-3.5-flash-lite']);
$payload=['action'=>'SAFE_T_APPEAL','rationale'=>'Preserve rights','confidence'=>0.9,'uncertainties'=>[],'parameters'=>['date_binding'=>'APPEAL_DEADLINE']];
$calls=[];
$transport=function(array $request)use(&$calls,$payload):array{$calls[]=$request['url']; if(count($calls)<4)return ['status'=>503,'body'=>['error'=>['message'=>'unavailable']]]; return ['status'=>200,'body'=>['candidates'=>[['content'=>['parts'=>[['text'=>json_encode($payload)]]]]]]];};
$a=new SvAmazonOpenAiReviewAdvisor($cfg,$transport);$s=$a->suggest(['facts'=>[],'signature'=>[],'variables'=>[],'evidence_refs'=>[]]);
gfSame('SAFE_T_APPEAL',$s['action'],'action');
gfSame(4,count($calls),'must try second Gemini model after primary fails');
gfSame(true,str_contains($calls[2],'gemini-3.5-flash-lite'),'primary Gemini model');
gfSame(true,str_contains($calls[3],'gemini-3.1-flash-lite'),'secondary Gemini model');
gfSame('gemini-3.1-flash-lite',$a->model(),'persist actual fallback Gemini model');
echo "gemini-model-fallback-test: OK\n";