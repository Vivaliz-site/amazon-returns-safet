<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/OpenAiReviewAdvisor.php';
function oaSame($a,$b,$m){if($a!==$b)throw new RuntimeException($m.' want='.json_encode($a).' got='.json_encode($b));}function oaThrows($f,$m){try{$f();}catch(Throwable){return;}throw new RuntimeException($m);}
$cfg=new SvAmazonReturnsConfig(['OPENAI_API_KEY'=>'test-key','AMAZON_RETURNS_REVIEW_AI_MODEL'=>'gpt-5.6-terra']);
$fake=function(array $request):array{oaSame(false,$request['body']['store'],'store false');oaSame('json_schema',$request['body']['text']['format']['type'],'schema type');$payload=['action'=>'WAIT','rationale'=>'Amazon gave explicit date','confidence'=>0.94,'uncertainties'=>[],'parameters'=>['date_binding'=>'PROMISED_DATE']];return ['status'=>200,'body'=>['output'=>[['content'=>[['type'=>'output_text','text'=>json_encode($payload)]]]]]];};
$a=new SvAmazonOpenAiReviewAdvisor($cfg,$fake);$s=$a->suggest(['facts'=>['x'=>1],'signature'=>['review_reason'=>'X'],'variables'=>['PROMISED_DATE'=>'2026-09-10 00:00:00'],'evidence_refs'=>[]]);oaSame('WAIT',$s['action'],'action');oaSame('gpt-5.6-terra',$a->model(),'model');
$bad=function(array $r){return ['status'=>200,'body'=>['output'=>[['content'=>[['type'=>'output_text','text'=>'{"action":"EXEC"}']]]]]];};oaThrows(fn()=>(new SvAmazonOpenAiReviewAdvisor($cfg,$bad))->suggest([]),'bad output accepted');
oaThrows(fn()=>(new SvAmazonOpenAiReviewAdvisor(new SvAmazonReturnsConfig([]),$fake))->suggest([]),'missing key accepted');
echo "openai-review-advisor-test: OK\n";
