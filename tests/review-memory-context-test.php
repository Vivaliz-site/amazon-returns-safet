<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ReviewMemoryContext.php';
function rmcSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
final class RmcReviews{public function forCase(int $id):array{return [['id'=>3,'status'=>'DECIDED','reason'=>'OLD','decision_mode'=>'APPROVED','human_decision'=>['final_action'=>'SAFE_T_APPEAL','parameters'=>['date_binding'=>'NONE']],'outcome'=>['classification'=>'RECOVERED']],['id'=>4,'status'=>'OPEN','reason'=>'CURRENT']];}}
final class RmcOutbox{public function historyForCase(int $id):array{return [['id'=>8,'kind'=>'SAFE_T_APPEAL','status'=>'SUCCEEDED','created_at'=>'2026-09-01 00:00:00','updated_at'=>'2026-09-01 00:01:00']];}}
final class RmcApps{public function forCase(int $id):array{return [['rule_id'=>9,'result'=>'QUEUED','action_ref'=>'8','outcome'=>'RECOVERED']];}}
final class RmcRules{public function active():array{return [['id'=>9,'version'=>2,'match'=>['program'=>'STANDARD','financial'=>'NO_CREDIT'],'effect'=>['action'=>'SAFE_T_APPEAL'],'outcome_counters'=>['RECOVERED'=>2]],['id'=>10,'version'=>1,'match'=>['program'=>'FBA'],'effect'=>['action'=>'SELLER_SUPPORT_OPEN'],'outcome_counters'=>[]]];}}
$p=(object)['reviews'=>new RmcReviews(),'outbox'=>new RmcOutbox(),'ruleApplications'=>new RmcApps(),'learnedRules'=>new RmcRules()];
$context=['facts'=>['case_id'=>7],'signature'=>['program'=>'STANDARD','financial'=>'NO_CREDIT']];
$enriched=(new SvAmazonReviewMemoryContext($p))->enrich($context);
rmcSame(1,count($enriched['memory']['case_decisions']??[]),'Only decided case reviews belong in AI memory.');
rmcSame('SAFE_T_APPEAL',$enriched['memory']['case_decisions'][0]['final_action']??null,'Human decision must be remembered.');
rmcSame('RECOVERED',$enriched['memory']['case_decisions'][0]['outcome']??null,'Observed outcome must be remembered.');
rmcSame(1,count($enriched['memory']['case_executions']??[]),'Execution history must be remembered.');
rmcSame(1,count($enriched['memory']['rule_applications']??[]),'Rule application history must be remembered.');
rmcSame(1,count($enriched['memory']['matching_learned_rules']??[]),'Only learned rules matching the current signature must be supplied.');
echo "review-memory-context-test: OK\n";