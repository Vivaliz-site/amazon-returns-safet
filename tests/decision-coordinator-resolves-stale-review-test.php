<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/DecisionCoordinator.php';
require_once __DIR__.'/../includes/amazon-returns/Config.php';
function staleSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
final class StaleRules{public function active():array{return [];}}
final class StaleReviews{
    public array $open=[];
    public array $resolved=[];
    public function open(int $caseId,string $reason,string $hash,array $context):array{$this->open[$caseId]=['case_id'=>$caseId,'reason'=>$reason,'hash'=>$hash,'context'=>$context];return $this->open[$caseId];}
    public function resolveOpenForCase(int $caseId):int{if(!isset($this->open[$caseId]))return 0;unset($this->open[$caseId]);$this->resolved[]=$caseId;return 1;}
}
final class StaleApps{public function record(array $row):int{return 1;}}
final class StaleEvents{public function append(array $row):int{return 1;}}
final class StalePersistence{public StaleRules $learnedRules;public StaleReviews $reviews;public StaleApps $ruleApplications;public StaleEvents $events;public function __construct(){$this->learnedRules=new StaleRules();$this->reviews=new StaleReviews();$this->ruleApplications=new StaleApps();$this->events=new StaleEvents();}}
$p=new StalePersistence();
$engine=new SvAmazonSafeTDecisionEngine(null,new DateTimeImmutable('2026-09-06T23:00:00Z'));
$coordinator=new SvAmazonDecisionCoordinator($engine,$p,new SvAmazonReturnsConfig());
$case=['id'=>1263,'amazon_order_id'=>'702-2491487-8294621','safe_t_id'=>null,'state'=>'POLICY_REVIEW_REQUIRED','physical_status'=>'NOT_RECEIVED','refund_at'=>'2026-09-01 12:00:00','refund_initiator'=>'UNKNOWN','expected_reimbursement_amount'=>'35.50','reconciled_credit_amount'=>'0.00','marketplace_id'=>'A2Q3Y263D00KWC','program'=>'DELIVERY_BY_AMAZON'];
$policy=['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'];
$first=$coordinator->nextAction($case,[],$policy);
staleSame('BLOCKED_REVIEW',$first['action'],'unknown initiator with confirmed refund opens review');
staleSame(1,count($p->reviews->open),'one open review');
$case['refund_at']=null;$case['expected_reimbursement_amount']='0.00';
$second=$coordinator->nextAction($case,[],$policy);
staleSame('WAIT',$second['action'],'new deterministic evidence resolves prior review');
staleSame(0,count($p->reviews->open),'deterministic decision must remove stale open review');
staleSame([1263],$p->reviews->resolved,'resolution must be case-scoped');
echo "decision-coordinator-resolves-stale-review-test: OK\n";
