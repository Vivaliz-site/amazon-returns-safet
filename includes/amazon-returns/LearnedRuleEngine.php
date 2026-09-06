<?php
declare(strict_types=1);
final class SvAmazonLearnedRuleEngine
{
    private const ACTIONS=['WAIT','CHECK_FINANCES','SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE','CLOSE_LOSS'];
    private const DATE_BINDINGS=['NONE','PROMISED_DATE','APPEAL_DEADLINE'];
    public function match(array $context,array $activeRules):array
    {
        if(($context['facts']['material_conflict']??false)===true || ($context['signature']['material_conflict']??false)===true){
            return ['status'=>'CONFLICT','rule'=>null,'effect'=>null,'conflicts'=>[],'reason'=>'MATERIAL_CONTEXT_CONFLICT'];
        }
        $matches=[];
        foreach($activeRules as $rule){
            if(($rule['status']??'ACTIVE')!=='ACTIVE')continue;
            $pred=$rule['match']??$rule['match_json']??[];
            if(!is_array($pred))throw new InvalidArgumentException('Invalid learned rule predicates.');
            foreach($pred as $k=>$expected){if(!array_key_exists($k,$context['signature']??[])||($context['signature'][$k]??null)!==$expected)continue 2;}
            $effect=self::normalizeEffect($rule['effect']??$rule['effect_json']??[],$context['variables']??[]);
            $matches[]=['rule'=>$rule,'effect'=>$effect,'specificity'=>count($pred)];
        }
        if(!$matches)return ['status'=>'NONE','rule'=>null,'effect'=>null,'conflicts'=>[]];
        $max=max(array_column($matches,'specificity')); $top=array_values(array_filter($matches,fn($m)=>$m['specificity']===$max));
        $hashes=[]; foreach($top as $m)$hashes[hash('sha256',json_encode($m['effect'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))]=true;
        if(count($hashes)>1)return ['status'=>'CONFLICT','rule'=>null,'effect'=>null,'conflicts'=>array_column($top,'rule'),'reason'=>'INCOMPATIBLE_LEARNED_RULES'];
        usort($top,fn($a,$b)=>(int)($a['rule']['id']??0)<=>(int)($b['rule']['id']??0));
        return ['status'=>'MATCH','rule'=>$top[0]['rule'],'effect'=>$top[0]['effect'],'conflicts'=>[]];
    }
    public static function normalizeEffect(array $effect,array $variables=[]):array
    {
        $action=(string)($effect['action']??''); if(!in_array($action,self::ACTIONS,true))throw new InvalidArgumentException('Unsupported learned rule action.');
        $params=$effect['parameters']??[]; if(!is_array($params))throw new InvalidArgumentException('Invalid learned rule parameters.');
        foreach(array_keys($params) as $k)if(!in_array($k,['date_binding'],true))throw new InvalidArgumentException('Unsupported learned rule parameter.');
        $binding=(string)($params['date_binding']??'NONE'); if(!in_array($binding,self::DATE_BINDINGS,true))throw new InvalidArgumentException('Unsupported learned rule date binding.');
        $out=['action'=>$action,'parameters'=>['date_binding'=>$binding]];
        if($binding!=='NONE'){$v=$variables[$binding]??null;if(!is_string($v)||trim($v)==='')throw new InvalidArgumentException('Required learned rule variable is unresolved.');$out['parameters']['resolved_date']=$v;}
        return $out;
    }
}
