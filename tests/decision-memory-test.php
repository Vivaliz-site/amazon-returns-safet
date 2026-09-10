<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/DecisionCoordinator.php';
function dmSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
final class DmEvents{public array $rows=[];public function append(array $event):int{$this->rows[$event['idempotency_key']]=$event;return count($this->rows);}}
final class DmReviews{public function resolveOpenForCase(int $id):int{return 0;}}
final class DmRules{public function active():array{return [];}}
final class DmApps{}
final class DmP{public DmEvents $events;public DmReviews $reviews;public DmRules $learnedRules;public DmApps $ruleApplications;public function __construct(){$this->events=new DmEvents();$this->reviews=new DmReviews();$this->learnedRules=new DmRules();$this->ruleApplications=new DmApps();}}
$case=['id'=>77,'amazon_order_id'=>'702-1','program'=>'STANDARD','refund_at'=>'2026-06-10 12:00:00','seller_debit_at'=>'2026-06-10 12:00:00','refund_initiator'=>'AMAZON_AUTOMATIC','expected_reimbursement_amount'=>'199.90','reconciled_credit_amount'=>'199.90','physical_status'=>'NOT_RECEIVED','state'=>'SAFE_T_APPROVED','safe_t_id'=>'12472-25597-6629839'];
$policy=['eligible'=>true,'state'=>'SAFE_T_ELIGIBLE','policy_version_id'=>12,'eligibility_at'=>'2026-07-25 12:00:00'];
$p=new DmP();$engine=new SvAmazonSafeTDecisionEngine(null,new DateTimeImmutable('2026-09-05T15:00:00Z'));$coordinator=new SvAmazonDecisionCoordinator($engine,$p,null);
$a=$coordinator->nextAction($case,[],$policy,new DateTimeImmutable('2026-09-05T15:00:00Z'));
$b=$coordinator->nextAction($case,[],$policy,new DateTimeImmutable('2026-09-05T15:01:00Z'));
dmSame('WAIT',$a['action']??null,'Fixture must produce a deterministic non-write decision.');
dmSame('WAIT',$b['action']??null,'Repeated decision remains stable.');
dmSame(1,count($p->events->rows),'Identical material decision must be stored once, not inflate memory every cycle.');
$event=array_values($p->events->rows)[0];
dmSame('DECISION_EVALUATED',$event['event_type']??null,'Decision memory event type.');
dmSame('INTERNAL',$event['source']??null,'Decision memory is internal evidence.');
dmSame('WAIT',$event['payload']['action']??null,'Decision action is remembered.');
dmSame(12,$event['payload']['policy_version_id']??null,'Policy version is remembered.');
echo "decision-memory-test: OK\n";