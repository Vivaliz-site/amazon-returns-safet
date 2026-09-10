<?php
declare(strict_types=1);
require_once __DIR__.'/SafeTDecisionEngine.php';
require_once __DIR__.'/ReviewContext.php';
require_once __DIR__.'/LearnedRuleEngine.php';
final class SvAmazonDecisionCoordinator
{
    public function __construct(private SvAmazonSafeTDecisionEngine $base,private object $persistence,private ?object $config=null,private ?SvAmazonLearnedRuleEngine $ruleEngine=null)
    {
        $this->ruleEngine??=new SvAmazonLearnedRuleEngine();
        $this->resolveTerminalReviews();
    }
    public function previewAction(array $case,array $timeline,array $policy,?DateTimeImmutable $now=null):array{return $this->decide($case,$timeline,$policy,$now,false);}
    public function nextAction(array $case,array $timeline,array $policy,?DateTimeImmutable $now=null):array
    {
        $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $decision=$this->decide($case,$timeline,$policy,$now,true);
        $this->recordDecision($case,$policy,$decision,$now);
        return $decision;
    }
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
        if($persist && ($blocker=$this->writeBlocker($base,$case))!==null){
            $action=strtoupper(trim((string)($base['action']??'')));
            $reason='WRITE_BLOCKED_'.$action;
            $reviewDecision=['action'=>'HUMAN_REVIEW','reason'=>$reason,'case_id'=>(int)($case['id']??0),'blocked_action'=>$action,'blocked_reason'=>$base['reason']??null,'write_blocker'=>$blocker,'next_action_at'=>$base['next_action_at']??null];
            $context=SvAmazonReviewContext::build($case,$timeline,$policy,$reviewDecision);
            $context['facts']['blocked_action']=$action;
            $context['facts']['write_blocker']=$blocker;
            $context['facts']['blocked_decision']=$this->safeBlockedDecision($base);
            $context['signature_hash']=hash('sha256',implode('|',[(string)$context['signature_hash'],$reason,(string)($base['idempotency_key']??'')]));
            $this->persistence->reviews->open((int)$case['id'],$reason,$context['signature_hash'],$context);
            return $reviewDecision+['review_reason'=>$reason,'review_context'=>$context];
        }
        if(!in_array($base['action']??'',['HUMAN_REVIEW','BLOCKED_REVIEW'],true)){
            if($persist){
                if(method_exists($this->persistence->reviews,'resolveOpenForCase'))$this->persistence->reviews->resolveOpenForCase((int)$case['id']);
                $this->clearResolvedReviewGate($case,$policy,$base);
            }
            return $base;
        }
        $context=SvAmazonReviewContext::build($case,$timeline,$policy,$base);$match=$this->ruleEngine->match($context,$this->persistence->learnedRules->active());
        if($match['status']==='MATCH'){
            $executionEnabled=$this->config!==null && method_exists($this->config,'learnedRuleExecutionEnabled') && $this->config->learnedRuleExecutionEnabled();
            if(!$executionEnabled)return $base+['learned_rule_shadow_match'=>$match['rule']['id']??null,'signature_hash'=>$context['signature_hash']];
            $decision=$this->base->guardLearnedEffect($match['effect'],$case,$timeline,$policy,$now);
            $decision['learned_rule_id']=$match['rule']['id']??null;$decision['learned_rule_version']=$match['rule']['version']??null;$decision['signature_hash']=$context['signature_hash'];
            if($persist){
                $this->auditMatch($case,$context,$match['rule'],$match['effect'],$decision);
                if(!in_array($decision['action']??'',['HUMAN_REVIEW','BLOCKED_REVIEW'],true)){
                    if(method_exists($this->persistence->reviews,'resolveOpenForCase'))$this->persistence->reviews->resolveOpenForCase((int)$case['id']);
                    $this->clearResolvedReviewGate($case,$policy,$decision);
                }
            }
            return $decision;
        }
        $reason=$match['status']==='CONFLICT'?'LEARNED_RULE_CONFLICT':(string)($base['reason']??'UNRESOLVED_REVIEW');
        if($persist)$this->persistence->reviews->open((int)$case['id'],$reason,$context['signature_hash'],$context);
        return $base+['review_reason'=>$reason,'review_context'=>$context,'learned_rule_conflicts'=>$match['conflicts']??[]];
    }
    private function writeBlocker(array $decision,array $case):?string
    {
        $action=strtoupper(trim((string)($decision['action']??'')));
        if(!in_array($action,['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'],true))return null;
        if($this->config===null)return null;
        if(method_exists($this->config,'externalWriteAllowed')&&!$this->config->externalWriteAllowed($action))return 'WRITE_DISABLED';
        $caseId=(int)($case['id']??0);
        if(method_exists($this->config,'writeCaseAllowed')&&!$this->config->writeCaseAllowed($caseId))return 'WRITE_CANARY_BLOCKED';
        if(method_exists($this->config,'readiness')){
            $dependency=in_array($action,['SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY'],true)?'gmail':'seller_central_bridge';
            $ready=$this->config->readiness()[$dependency]['ready']??false;
            if($ready!==true)return 'DEPENDENCY_UNREADY';
        }
        return null;
    }
    private function safeBlockedDecision(array $decision):array
    {
        $safe=[];
        foreach(['action','reason','idempotency_key','next_action_at','promised_by_date','outstanding_amount','support_route','operational_mode'] as $field){
            if(array_key_exists($field,$decision)&&is_scalar($decision[$field]))$safe[$field]=$decision[$field];
        }
        return $safe;
    }
    private function recordDecision(array $case,array $policy,array $decision,DateTimeImmutable $now):void
    {
        if(!isset($this->persistence->events)||!method_exists($this->persistence->events,'append'))return;
        $payload=[
            'action'=>(string)($decision['action']??'WAIT'),
            'reason'=>(string)($decision['reason']??'UNSPECIFIED'),
            'next_action_at'=>$decision['next_action_at']??null,
            'policy_version_id'=>$policy['policy_version_id']??null,
            'operational_idempotency_key'=>$decision['idempotency_key']??null,
            'blocked_action'=>$decision['blocked_action']??null,
            'write_blocker'=>$decision['write_blocker']??null,
        ];
        $material=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $key=hash('sha256','decision-evaluated-v1|'.(int)($case['id']??0).'|'.$material);
        $this->persistence->events->append(['case_id'=>(int)$case['id'],'event_type'=>'DECISION_EVALUATED','source'=>'INTERNAL','source_event_id'=>$key,'idempotency_key'=>$key,'occurred_at'=>$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'payload'=>$payload,'evidence_sha256'=>null]);
    }
    private function clearResolvedReviewGate(array $case,array $policy,array $decision):void
    {
        if(($case['state']??null)!==SvAmazonReturnStates::POLICY_REVIEW_REQUIRED)return;
        if(!isset($this->persistence->cases) || !method_exists($this->persistence->cases,'update'))return;
        $state=null;
        $policyState=(string)($policy['state']??'');
        if(SvAmazonReturnStates::isValid($policyState) && $policyState!==SvAmazonReturnStates::POLICY_REVIEW_REQUIRED){
            $state=$policyState;
        }else{
            $financialExposure=trim((string)($case['refund_at']??''))!==''
                || trim((string)($case['seller_debit_at']??''))!==''
                || (float)($case['expected_reimbursement_amount']??0)>0.00001;
            $state=$financialExposure
                ? SvAmazonReturnStates::CREDIT_PENDING
                : match((string)($case['physical_status']??'')){
                    SvAmazonReturnPhysicalStatuses::IN_TRANSIT=>SvAmazonReturnStates::IN_TRANSIT,
                    SvAmazonReturnPhysicalStatuses::CARRIER_DELIVERED_PENDING_PHYSICAL=>SvAmazonReturnStates::CARRIER_DELIVERED_PENDING_PHYSICAL,
                    default=>SvAmazonReturnStates::AWAITING_RETURN,
                };
        }
        if($state!==null)$this->persistence->cases->update((int)$case['id'],['state'=>$state]);
    }
    private function resolveTerminalReviews():void
    {
        if(!isset($this->persistence->reviews,$this->persistence->cases)
            || !method_exists($this->persistence->reviews,'openQueue')
            || !method_exists($this->persistence->reviews,'resolveOpenForCase')
            || !method_exists($this->persistence->cases,'find'))return;
        foreach(array_slice($this->persistence->reviews->openQueue(),0,500) as $review){
            $caseId=(int)($review['case_id']??0);
            if($caseId<1)continue;
            $case=$this->persistence->cases->find($caseId);
            if(!is_array($case))continue;
            $state=(string)($case['state']??'');
            $closed=trim((string)($case['closed_at']??''))!=='';
            if(!$closed && !in_array($state,[SvAmazonReturnStates::RECOVERED,SvAmazonReturnStates::CLOSED_LOSS,SvAmazonReturnStates::RECEIVED_OK],true))continue;
            $this->persistence->reviews->resolveOpenForCase($caseId);
        }
    }
    private function auditMatch(array $case,array $context,array $rule,array $effect,array $decision):void
    {
        $effectHash=hash('sha256',json_encode($effect,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $key=hash('sha256',implode('|',[(string)($rule['id']??0),(string)($rule['version']??0),(string)$case['id'],$context['signature_hash'],$effectHash]));
        $appId=$this->persistence->ruleApplications->record(['application_key'=>$key,'rule_id'=>(int)$rule['id'],'case_id'=>(int)$case['id'],'signature_hash'=>$context['signature_hash'],'effect_hash'=>$effectHash,'result'=>'MATCHED','blockers'=>[],'action_ref'=>(string)($decision['action']??'')]);
        $this->persistence->events->append(['case_id'=>(int)$case['id'],'event_type'=>'LEARNED_RULE_APPLIED','source'=>'INTERNAL','source_event_id'=>(string)$appId,'idempotency_key'=>$key,'occurred_at'=>gmdate('Y-m-d H:i:s'),'payload'=>['rule_id'=>(int)$rule['id'],'rule_version'=>(int)($rule['version']??0),'application_id'=>$appId,'action'=>(string)($decision['action']??''),'signature_hash'=>$context['signature_hash'],'effect_hash'=>$effectHash],'evidence_sha256'=>null]);
    }
}