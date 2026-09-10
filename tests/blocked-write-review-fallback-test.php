<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/DecisionCoordinator.php';

function bwrSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
final class BwrReviews{public array $opened=[];public function open(int $caseId,string $reason,string $hash,array $context):array{$this->opened[]=compact('caseId','reason','hash','context');return ['id'=>1,'case_id'=>$caseId,'reason'=>$reason,'status'=>'OPEN','version'=>1,'context'=>$context];}}
final class BwrRules{public function active():array{return [];}}
final class BwrApps{}
final class BwrEvents{public array $rows=[];public function append(array $event):int{$this->rows[$event['idempotency_key']]=$event;return count($this->rows);} }
final class BwrPersistence{public BwrReviews $reviews;public BwrRules $learnedRules;public BwrApps $ruleApplications;public BwrEvents $events;public function __construct(){$this->reviews=new BwrReviews();$this->learnedRules=new BwrRules();$this->ruleApplications=new BwrApps();$this->events=new BwrEvents();}}
final class BwrConfig{public function externalWriteAllowed(string $action):bool{return false;}public function writeCaseAllowed(int $caseId):bool{return true;}public function readiness():array{return ['gmail'=>['ready'=>true],'seller_central_bridge'=>['ready'=>true]];}public function learnedRuleExecutionEnabled():bool{return false;}}
$case=['id'=>77,'amazon_order_id'=>'702-1','program'=>'STANDARD','refund_at'=>'2026-06-10 12:00:00','seller_debit_at'=>'2026-06-10 12:00:00','refund_initiator'=>'UNKNOWN','expected_reimbursement_amount'=>'199.90','reconciled_credit_amount'=>'0.00','physical_status'=>'NOT_RECEIVED','state'=>'APPEAL_DENIED_FINAL','safe_t_id'=>'12472-25597-6629839','appeal_deadline_at'=>'2026-09-15 18:00:00'];
$timeline=[['event_type'=>'SAFE_T_STATUS_OBSERVED','source'=>'SELLER_CENTRAL','payload'=>['claim_status'=>'DENIED','decision_text'=>'Negativa final.']]];
$policy=['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED','policy_version_id'=>12];
$p=new BwrPersistence();$engine=new SvAmazonSafeTDecisionEngine(null,new DateTimeImmutable('2026-09-05T15:00:00Z'));
$decision=(new SvAmazonDecisionCoordinator($engine,$p,new BwrConfig()))->nextAction($case,$timeline,$policy,new DateTimeImmutable('2026-09-05T15:00:00Z'));
bwrSame('HUMAN_REVIEW',$decision['action']??null,'Disabled required write must become human review.');
bwrSame('WRITE_BLOCKED_SAFE_T_EMAIL_REVIEW',$decision['reason']??null,'Blocked action must be explicit in review reason.');
bwrSame(1,count($p->reviews->opened),'Blocked write must open exactly one review.');
bwrSame('SAFE_T_EMAIL_REVIEW',$p->reviews->opened[0]['context']['facts']['blocked_action']??null,'Original blocked action must be preserved.');
bwrSame('WRITE_DISABLED',$p->reviews->opened[0]['context']['facts']['write_blocker']??null,'Write blocker must be preserved.');
echo "blocked-write-review-fallback-test: OK\n";