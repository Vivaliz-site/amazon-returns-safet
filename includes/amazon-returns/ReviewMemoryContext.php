<?php
declare(strict_types=1);

final class SvAmazonReviewMemoryContext
{
    public function __construct(private object $persistence){}

    public function enrich(array $context):array
    {
        $caseId=(int)($context['facts']['case_id']??0);
        $signature=is_array($context['signature']??null)?$context['signature']:[];
        $decisions=[];$engineDecisions=[];$executions=[];$applications=[];$rules=[];

        if($caseId>0&&isset($this->persistence->reviews)&&method_exists($this->persistence->reviews,'forCase')){
            foreach(array_reverse($this->persistence->reviews->forCase($caseId)) as $row){
                if(($row['status']??'')!=='DECIDED')continue;
                $decision=is_array($row['human_decision']??null)?$row['human_decision']:[];
                $outcome=is_array($row['outcome']??null)?$row['outcome']:[];
                $decisions[]=[
                    'review_id'=>(int)($row['id']??0),'reason'=>$row['reason']??null,
                    'decision_mode'=>$row['decision_mode']??($decision['decision_mode']??null),
                    'final_action'=>$decision['final_action']??null,
                    'date_binding'=>$decision['parameters']['date_binding']??'NONE',
                    'outcome'=>$outcome['classification']??null,
                ];
                if(count($decisions)>=10)break;
            }
        }

        if($caseId>0&&isset($this->persistence->events)&&method_exists($this->persistence->events,'eventsForCase')){
            foreach(array_reverse($this->persistence->events->eventsForCase($caseId)) as $row){
                if(($row['event_type']??'')!=='DECISION_EVALUATED')continue;
                $payload=is_array($row['payload']??null)?$row['payload']:[];
                $engineDecisions[]=['action'=>$payload['action']??null,'reason'=>$payload['reason']??null,'next_action_at'=>$payload['next_action_at']??null,'policy_version_id'=>$payload['policy_version_id']??null,'blocked_action'=>$payload['blocked_action']??null,'write_blocker'=>$payload['write_blocker']??null,'occurred_at'=>$row['occurred_at']??null];
                if(count($engineDecisions)>=10)break;
            }
        }

        if($caseId>0&&isset($this->persistence->outbox)&&method_exists($this->persistence->outbox,'historyForCase')){
            foreach(array_reverse(array_slice($this->persistence->outbox->historyForCase($caseId),-10)) as $row){
                $executions[]=['id'=>(int)($row['id']??0),'kind'=>$row['kind']??null,'status'=>$row['status']??null,'created_at'=>$row['created_at']??null,'updated_at'=>$row['updated_at']??null];
            }
        }

        if($caseId>0&&isset($this->persistence->ruleApplications)&&method_exists($this->persistence->ruleApplications,'forCase')){
            foreach(array_slice($this->persistence->ruleApplications->forCase($caseId),-10) as $row){
                $applications[]=['rule_id'=>(int)($row['rule_id']??0),'result'=>$row['result']??null,'action_ref'=>$row['action_ref']??null,'outcome'=>$row['outcome']??null];
            }
        }

        if(isset($this->persistence->learnedRules)&&method_exists($this->persistence->learnedRules,'active')){
            foreach($this->persistence->learnedRules->active() as $row){
                $match=is_array($row['match']??null)?$row['match']:[];
                if(!$this->matches($match,$signature))continue;
                $rules[]=['rule_id'=>(int)($row['id']??0),'version'=>(int)($row['version']??0),'match'=>$match,'effect'=>$row['effect']??[],'outcome_counters'=>$row['outcome_counters']??[]];
                if(count($rules)>=10)break;
            }
        }

        $context['memory']=['case_decisions'=>$decisions,'engine_decisions'=>$engineDecisions,'case_executions'=>$executions,'rule_applications'=>$applications,'matching_learned_rules'=>$rules];
        return $context;
    }

    private function matches(array $match,array $signature):bool
    {
        if($match===[])return false;
        foreach($match as $key=>$value)if(!array_key_exists($key,$signature)||$signature[$key]!==$value)return false;
        return true;
    }
}