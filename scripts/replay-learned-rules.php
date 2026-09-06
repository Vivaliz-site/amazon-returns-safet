<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/Database.php';
require_once __DIR__.'/../includes/amazon-returns/Config.php';
require_once __DIR__.'/../includes/amazon-returns/TenantRegistry.php';
require_once __DIR__.'/../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__.'/../includes/amazon-returns/Projector.php';
require_once __DIR__.'/../includes/amazon-returns/PolicyEngine.php';
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__.'/../includes/amazon-returns/ReviewContext.php';
require_once __DIR__.'/../includes/amazon-returns/LearnedRuleEngine.php';

function replayCount(PDO $db,SvAmazonTenantContext $ctx,string $table):int
{
    $allowed=['amazon_return_cases','amazon_return_events','amazon_return_outbox','amazon_return_learned_rules'];
    if(!in_array($table,$allowed,true))throw new InvalidArgumentException('Unsupported replay count table.');
    $stmt=$db->prepare("SELECT COUNT(*) FROM {$table} WHERE tenant_id=:tenant_id AND amazon_connection_id=:connection_id");
    if(!$stmt||!$stmt->execute([':tenant_id'=>$ctx->tenantId(),':connection_id'=>$ctx->amazonConnectionId()]))throw new RuntimeException('Replay count failed.');
    return (int)$stmt->fetchColumn();
}

function replayCounts(PDO $db,SvAmazonTenantContext $ctx):array
{
    return ['cases'=>replayCount($db,$ctx,'amazon_return_cases'),'events'=>replayCount($db,$ctx,'amazon_return_events'),'outbox'=>replayCount($db,$ctx,'amazon_return_outbox'),'rules'=>replayCount($db,$ctx,'amazon_return_learned_rules')];
}
function replayHardGateViolations(string $action,array $case,array $timeline,array $policy,array $effect,DateTimeImmutable $now):array
{
    $v=[];$expected=max(0.0,(float)($case['expected_reimbursement_amount']??0));$credit=max(0.0,(float)($case['reconciled_credit_amount']??0));
    if($expected>0&&$credit+0.005>=$expected&&$action!=='WAIT')$v[]='FULL_CREDIT_RECONCILED';
    $safeT=trim((string)($case['safe_t_id']??''));
    if($action==='SAFE_T_SUBMIT'){
        if($safeT!=='')$v[]='SAFE_T_ALREADY_EXISTS';
        foreach($timeline as $e)if(is_array($e)&&($e['event_type']??'')==='PHYSICAL_RECEIVED'&&($e['source']??'')==='WAREHOUSE'){$v[]='PHYSICAL_RECEIPT_CONFIRMED';break;}
        if(($case['physical_status']??'')==='RECEIVED_DISCREPANT')$v[]='DAMAGED_INITIAL_CLAIM_MANUAL_ONLY';
        $initiator=(string)($case['refund_initiator']??'UNKNOWN');
        if(trim((string)($case['refund_at']??''))===''||!in_array($initiator,['AMAZON_AUTOMATIC','AMAZON_CUSTOMER_SERVICE','A_TO_Z'],true))$v[]='AMAZON_REFUND_UNCONFIRMED';
        if(($policy['eligible']??false)!==true)$v[]='D45_GATE_BLOCKED';
    }
    if($action==='SAFE_T_APPEAL'){
        if($safeT==='')$v[]='SAFE_T_REQUIRED';
        if(($case['state']??'')==='APPEAL_SUBMITTED')$v[]='APPEAL_ALREADY_SUBMITTED';
        try{$deadline=new DateTimeImmutable((string)($case['appeal_deadline_at']??''),new DateTimeZone('UTC'));if($deadline<$now)$v[]='APPEAL_WINDOW_EXPIRED';}catch(Throwable){$v[]='APPEAL_DEADLINE_UNRESOLVED';}
    }
    if(in_array($action,['SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'],true)&&$safeT==='')$v[]='SAFE_T_REQUIRED';
    if($action==='WAIT'&&trim((string)($effect['parameters']['resolved_date']??''))==='')$v[]='WAIT_DATE_UNRESOLVED';
    return array_values(array_unique($v));
}

function replayWriteJson(array $report,?string $path):void
{
    $json=json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
    if($path!==null){if(file_put_contents($path,$json,LOCK_EX)===false)throw new RuntimeException('Unable to write replay report.');}
    echo $json;
}
$options=getopt('',['all-open','json:']);
if(!isset($options['all-open'])){fwrite(STDERR,"Usage: php scripts/replay-learned-rules.php --all-open [--json=/path/report.json]\n");exit(2);}
$jsonPath=isset($options['json'])&&is_string($options['json'])&&trim($options['json'])!==''?trim($options['json']):null;
$db=amazon_returns_require_pdo();$config=new SvAmazonReturnsConfig();$context=SvAmazonTenantRegistry::resolveCurrent($db,$config);$p=SvAmazonTenantPersistence::create($db,$context);
$before=replayCounts($db,$context);$tenantViolations=$p->learnedRules->ownershipViolationCount();$rules=$p->learnedRules->active();
$malformed=[];foreach($rules as $rule){try{SvAmazonLearnedRuleEngine::normalizeEffect(is_array($rule['effect']??null)?$rule['effect']:[],['PROMISED_DATE'=>'2099-01-01 00:00:00','APPEAL_DEADLINE'=>'2099-01-01 00:00:00']);}catch(Throwable){$malformed[]=(int)($rule['id']??0);}}
$cases=$p->cases->openCases(5000);$policies=$p->policies->allActive();$engine=new SvAmazonSafeTDecisionEngine();$ruleEngine=new SvAmazonLearnedRuleEngine();$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$report=['total_cases'=>count($cases),'base_auto_decisions'=>0,'review_gated_cases'=>0,'learned_matches'=>0,'conflicts'=>0,'hard_gate_blocks'=>0,'hard_gate_bypass_count'=>0,'malformed_effect_count'=>count($malformed),'malformed_rule_ids'=>$malformed,'tenant_scope_violation_count'=>$tenantViolations,'projected_actions'=>[],'cases'=>[],'before_counts'=>$before];
foreach($cases as $case){
    $caseId=(int)($case['id']??0);$timeline=$p->events->eventsForCase($caseId);$projected=SvAmazonReturnProjector::projectFrom($case,$timeline);$projected['policies']=$policies;$policy=SvAmazonReturnPolicyEngine::evaluate($projected,$now);$base=$engine->nextAction($projected,$timeline,$policy,$now);
    $row=['case_id'=>$caseId,'order_id'=>$case['amazon_order_id']??null,'safe_t_id'=>$case['safe_t_id']??null,'base_action'=>$base['action']??'WAIT','base_reason'=>$base['reason']??null,'match_status'=>'NOT_EVALUATED','projected_action'=>$base['action']??'WAIT','projected_reason'=>$base['reason']??null,'hard_gate_violations'=>[]];
    if(!in_array($base['action']??'',['HUMAN_REVIEW','BLOCKED_REVIEW'],true))$report['base_auto_decisions']++;
    else{
        $report['review_gated_cases']++;$contextRow=SvAmazonReviewContext::build($projected,$timeline,$policy,$base);
        try{$match=$ruleEngine->match($contextRow,$rules);$row['match_status']=$match['status'];
            if($match['status']==='MATCH'){
                $report['learned_matches']++;$guarded=$engine->guardLearnedEffect($match['effect'],$projected,$timeline,$policy,$now);$row['rule_id']=$match['rule']['id']??null;$row['projected_action']=$guarded['action']??'HUMAN_REVIEW';$row['projected_reason']=$guarded['reason']??null;
                $violations=replayHardGateViolations((string)($match['effect']['action']??''),$projected,$timeline,$policy,$match['effect'],$now);$row['hard_gate_violations']=$violations;
                if(($guarded['action']??null)!==($match['effect']['action']??null))$report['hard_gate_blocks']++;
                if($violations!==[]&&($guarded['action']??null)===($match['effect']['action']??null))$report['hard_gate_bypass_count']++;
            }elseif($match['status']==='CONFLICT'){$report['conflicts']++;$row['projected_action']='HUMAN_REVIEW';$row['projected_reason']='LEARNED_RULE_CONFLICT';}
        }catch(Throwable $e){$row['match_status']='MALFORMED';$row['projected_action']='HUMAN_REVIEW';$row['projected_reason']='MALFORMED_RULE_EFFECT';}
    }
    $action=(string)$row['projected_action'];$report['projected_actions'][$action]=($report['projected_actions'][$action]??0)+1;$report['cases'][]=$row;
}
$after=replayCounts($db,$context);$report['after_counts']=$after;$report['mutation_free']=$before===$after;
replayWriteJson($report,$jsonPath);
exit(($report['mutation_free']&&$report['hard_gate_bypass_count']===0&&$report['tenant_scope_violation_count']===0)?0:1);
