<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/OpenAiReviewAdvisor.php';
function oaSame($a,$b,$m){if($a!==$b)throw new RuntimeException($m.' want='.json_encode($a).' got='.json_encode($b));}function oaThrows($f,$m){try{$f();}catch(Throwable){return;}throw new RuntimeException($m);}
$cfg=new SvAmazonReturnsConfig(['OPENAI_API_KEY'=>'test-key','AMAZON_RETURNS_REVIEW_AI_MODEL'=>'gpt-5.6-terra']);
$fake=function(array $request):array{
    oaSame(false,$request['body']['store'],'store false');
    oaSame('json_schema',$request['body']['text']['format']['type'],'schema type');
    $system=(string)($request['body']['input'][0]['content'][0]['text']??'');
    if(!preg_match('/portugu[eê]s|pt-br/i',$system))throw new RuntimeException('system prompt must require Brazilian Portuguese user-facing text');
    $payload=['action'=>'WAIT','rationale'=>'A Amazon informou uma data explícita para aguardar.','confidence'=>0.94,'uncertainties'=>['A data prometida ainda não foi atingida.'],'parameters'=>['date_binding'=>'PROMISED_DATE']];
    return ['status'=>200,'body'=>['output'=>[['content'=>[['type'=>'output_text','text'=>json_encode($payload,JSON_UNESCAPED_UNICODE)]]]]]];
};
$a=new SvAmazonOpenAiReviewAdvisor($cfg,$fake);$s=$a->suggest(['facts'=>['x'=>1],'signature'=>['review_reason'=>'X'],'variables'=>['PROMISED_DATE'=>'2026-09-10 00:00:00'],'evidence_refs'=>[]]);oaSame('WAIT',$s['action'],'action');oaSame('gpt-5.6-terra',$a->model(),'model');
$staleAppeal=function(array $request):array{
    $suggestion=['action'=>'SAFE_T_APPEAL','rationale'=>'Recorrer agora no SAFE-T.',
        'confidence'=>0.8,'uncertainties'=>[],'parameters'=>['date_binding'=>'NONE']];
    return ['status'=>200,'body'=>['output'=>[['content'=>[['type'=>'output_text','text'=>json_encode($suggestion)]]]]]];
};
$guarded=(new SvAmazonOpenAiReviewAdvisor($cfg,$staleAppeal))->suggest([
    'facts'=>['state'=>'APPEAL_DENIED_FINAL','appeal_deadline_at'=>'2026-10-21 10:43:00'],
    'signature'=>[],'variables'=>[],'evidence_refs'=>[],
]);
oaSame('CHECK_FINANCES',$guarded['action'],'AI must not recommend an appeal after final denial or expired date.');
$stillOpen=SvAmazonOpenAiReviewAdvisor::guardSuggestion(
    ['action'=>'SAFE_T_APPEAL','rationale'=>'Prazo valido','confidence'=>0.8,'uncertainties'=>[],'parameters'=>['date_binding'=>'NONE']],
    ['facts'=>['state'=>'SAFE_T_DENIED','appeal_deadline_at'=>'2099-10-21 10:43:00']],
    new DateTimeImmutable('2026-11-10 12:00:00',new DateTimeZone('UTC'))
);
oaSame('SAFE_T_APPEAL',$stillOpen['action'],'Valid official appeal windows must remain eligible as advice.');
$unverified=SvAmazonOpenAiReviewAdvisor::guardSuggestion(
    ['action'=>'SAFE_T_APPEAL','rationale'=>'Prazo incerto','confidence'=>0.7,'uncertainties'=>[],'parameters'=>['date_binding'=>'NONE']],
    ['facts'=>['state'=>'SAFE_T_DENIED','appeal_deadline_at'=>null]],
    new DateTimeImmutable('2026-11-10 12:00:00',new DateTimeZone('UTC'))
);
oaSame('CHECK_FINANCES',$unverified['action'],'Known absence of an official deadline cannot authorize appeal advice.');



$bad=function(array $r){return ['status'=>200,'body'=>['output'=>[['content'=>[['type'=>'output_text','text'=>'{"action":"EXEC"}']]]]]];};oaThrows(fn()=>(new SvAmazonOpenAiReviewAdvisor($cfg,$bad))->suggest([]),'bad output accepted');
oaThrows(fn()=>(new SvAmazonOpenAiReviewAdvisor(new SvAmazonReturnsConfig(['AMAZON_RETURNS_AI_ENV_FILE'=>'/nonexistent']),$fake))->suggest([]),'missing key accepted');
echo "openai-review-advisor-test: OK\n";
