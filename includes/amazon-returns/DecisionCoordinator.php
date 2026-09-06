<?php
declare(strict_types=1);
require_once __DIR__.'/SafeTDecisionEngine.php';
require_once __DIR__.'/ReviewContext.php';
require_once __DIR__.'/LearnedRuleEngine.php';
final class SvAmazonDecisionCoordinator
{
    public function __construct(private SvAmazonSafeTDecisionEngine $base,private object $persistence,private ?object $config=null,private ?SvAmazonLearnedRuleEngine $ruleEngine=null){$this->ruleEngine??=new SvAmazonLearnedRuleEngine();}
    public function previewAction(array $case,array $timeline,array $policy,?DateTimeImmutable $now=null):array{return $this->decide($case,$timeline,$policy,$now,false);}
    public function nextAction(array $case,array $timeline,array $policy,?DateTimeImmutable $now=null):array{return $this->decide($case,$timeline,$policy,$now,true);}
    public function buildReviewContext(array $case,array $timeline,array $policy,?DateTimeImmutable $now=null): ?array
    {
        $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));$base=$this->base->nextAction($case,$timeline,$policy,$now);
        if(!in_array($base['action']??'',['HUMAN_REVIEW','BLOCKED_REVIEW'],true))return null;
        return SvAmazonReviewContext::build($case,$timeline,$policy,$base);
    }
    public function guardProposedEffect(array $effect,array $case,array $timeline,array $policy,?DateTimeImmutable $now=null): array
    {
        $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));$normalized=SvAmazonLearnedRuleEngine::normalizeEffect($effect,SvAmazonReviewContext::build($case,$timeline,$policy,['action'=>'HUMAN_REVIEW','reason'=>'PREVIEW'])['variables']);
        return $this->base->guardLearnedEffect($normalized,$case,$timeline,$policy,$now);
    }
    private function decide(array $case,array $timeline,array $policy,?DateTimeImmutable $now,bool $persist):array
    {
        $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));$base=$this->base->nextAction($case,$timeline,$policy,$now);
        if(!in_array($base['action']??'',['HUMAN_REVIEW','BLOCKED_REVIEW'],true))return $base;
        $context=SvAmazonReviewContext::build($case,$timeline,$policy,$base);$match=$this->ruleEngine->match($context,$this->persistence->learnedRules->active());
        if($match['status']==='MATCH'){
            $executionEnabled=$this->config!==null && method_exists($this->config,'learnedRuleExecutionEnabled') && $this->config->learnedRuleExecutionEnabled();
            if(!$executionEnabled)return $base+['learned_rule_shadow_match'=>$match['rule']['id']??null,'signature_hash'=>$context['signature_hash']];
            $decision=$this->base->guardLearnedEffect($match['effect'],$case,$timeline,$policy,$now);
            $decision['learned_rule_id']=$match['rule']['id']??null;$decision['learned_rule_version']=$match['rule']['version']??null;$decision['signature_hash']=$context['signature_hash'];
            if($persist)$this->auditMatch($case,$context,$match['rule'],$match['effect'],$decision);
            return $decision;
        }
        $reason=$match['status']==='CONFLICT'?'LEARNED_RULE_CONFLICT':(string)($base['reason']??'UNRESOLVED_REVIEW');
        if($persist)$this->persistence->reviews->open((int)$case['id'],$reason,$context['signature_hash'],$context);
        return $base+['review_reason'=>$reason,'review_context'=>$context,'learned_rule_conflicts'=>$match['conflicts']??[]];
    }
    private function auditMatch(array $case,array $context,array $rule,array $effect,array $decision):void
    {
        $effectHash=hash('sha256',json_encode($effect,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $key=hash('sha256',implode('|',[(string)($rule['id']??0),(string)($rule['version']??0),(string)$case['id'],$context['signature_hash'],$effectHash]));
        $appId=$this->persistence->ruleApplications->record(['application_key'=>$key,'rule_id'=>(int)$rule['id'],'case_id'=>(int)$case['id'],'signature_hash'=>$context['signature_hash'],'effect_hash'=>$effectHash,'result'=>'MATCHED','blockers'=>[],'action_ref'=>(string)($decision['action']??'')]);
        $this->persistence->events->append(['case_id'=>(int)$case['id'],'event_type'=>'LEARNED_RULE_APPLIED','source'=>'INTERNAL','source_event_id'=>(string)$appId,'idempotency_key'=>$key,'occurred_at'=>gmdate('Y-m-d H:i:s'),'payload'=>['rule_id'=>(int)$rule['id'],'rule_version'=>(int)($rule['version']??0),'application_id'=>$appId,'action'=>(string)($decision['action']??''),'signature_hash'=>$context['signature_hash'],'effect_hash'=>$effectHash],'evidence_sha256'=>null]);
    }
}
