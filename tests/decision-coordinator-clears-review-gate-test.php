<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/DecisionCoordinator.php';
require_once __DIR__.'/../includes/amazon-returns/Config.php';
function gateSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
final class GateRules{public array $rows=[];public function active():array{return $this->rows;}}
final class GateReviews{
    public array $resolved=[];
    public function openQueue():array{return [];}
    public function resolveOpenForCase(int $caseId):int{$this->resolved[]=$caseId;return 0;}
    public function open(int $caseId,string $reason,string $hash,array $context):array{return [];}
}
final class GateCases{
    public array $updates=[];
    public function update(int $caseId,array $patch):void{$this->updates[]=['case_id'=>$caseId,'patch'=>$patch];}
    public function find(int $caseId):?array{return null;}
}
final class GateApps{public function record(array $row):int{return 1;}}
final class GateEvents{public function append(array $row):int{return 1;}}
final class GatePersistence{
    public GateRules $learnedRules; public GateReviews $reviews; public GateCases $cases; public GateApps $ruleApplications; public GateEvents $events;
    public function __construct(){ $this->learnedRules=new GateRules();$this->reviews=new GateReviews();$this->cases=new GateCases();$this->ruleApplications=new GateApps();$this->events=new GateEvents(); }
}
$p=new GatePersistence();
$engine=new SvAmazonSafeTDecisionEngine(null,new DateTimeImmutable('2026-09-09T22:00:00Z'));
$coordinator=new SvAmazonDecisionCoordinator($engine,$p,new SvAmazonReturnsConfig());
$case=[
    'id'=>1,'amazon_order_id'=>'701-0630116-9129834','safe_t_id'=>null,
    'state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,'physical_status'=>SvAmazonReturnPhysicalStatuses::NOT_RECEIVED,
    'refund_at'=>'2026-09-02 02:05:29','seller_debit_at'=>'2026-09-02 02:05:29','refund_initiator'=>SvAmazonRefundInitiators::AMAZON_CUSTOMER_SERVICE,
    'expected_reimbursement_amount'=>'81.00','reconciled_credit_amount'=>'0.00','marketplace_id'=>'A2Q3Y263D00KWC','program'=>SvAmazonReturnPrograms::FBA,
];
$decision=$coordinator->nextAction($case,[],['eligible'=>false,'state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED]);
gateSame('CHECK_FINANCES',$decision['action'],'classic FBA must stay automatic');
gateSame([['case_id'=>1,'patch'=>['state'=>SvAmazonReturnStates::CREDIT_PENDING]]],$p->cases->updates,'automatic finance route must clear the stale human-review state');
$p->cases->updates=[];
$case2=[
    'id'=>2,'amazon_order_id'=>'702-1830738-4884230','safe_t_id'=>null,
    'state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,'physical_status'=>SvAmazonReturnPhysicalStatuses::NOT_RECEIVED,
    'refund_at'=>'2026-08-12 00:47:36','seller_debit_at'=>'2026-08-12 00:47:36','refund_initiator'=>SvAmazonRefundInitiators::AMAZON_INITIATED,
    'expected_reimbursement_amount'=>'57.09','reconciled_credit_amount'=>'0.00','marketplace_id'=>'A2Q3Y263D00KWC','program'=>SvAmazonReturnPrograms::DELIVERY_BY_AMAZON,
];
$policy2=['eligible'=>false,'state'=>SvAmazonReturnStates::AWAITING_RETURN,'eligibility_at'=>'2026-09-26 00:47:36','policy_version_id'=>1];
$decision2=$coordinator->nextAction($case2,[],$policy2);
gateSame('WAIT',$decision2['action'],'not-yet-eligible deterministic case must not require review');
gateSame('NOT_YET_ELIGIBLE',$decision2['reason'],'future eligibility must remain automatic');
gateSame([['case_id'=>2,'patch'=>['state'=>SvAmazonReturnStates::AWAITING_RETURN]]],$p->cases->updates,'resolved review gate must adopt the deterministic policy state');
$p->cases->updates=[];
$case3=[
    'id'=>3,'amazon_order_id'=>'702-2093747-0466649','safe_t_id'=>null,
    'state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,'physical_status'=>SvAmazonReturnPhysicalStatuses::NOT_RECEIVED,
    'refund_at'=>null,'seller_debit_at'=>null,'refund_initiator'=>SvAmazonRefundInitiators::UNKNOWN,
    'expected_reimbursement_amount'=>'0.00','reconciled_credit_amount'=>'0.00','marketplace_id'=>'A2Q3Y263D00KWC','program'=>SvAmazonReturnPrograms::FBA,
];
$decision3=$coordinator->nextAction($case3,[],['eligible'=>false,'state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED]);
gateSame('CHECK_FINANCES',$decision3['action'],'classic FBA without refund evidence must still use automatic finance discovery');
gateSame([['case_id'=>3,'patch'=>['state'=>SvAmazonReturnStates::AWAITING_RETURN]]],$p->cases->updates,'automatic finance discovery must clear a stale review gate even before a refund baseline exists');
$p->cases->updates=[];
$case4=[
    'id'=>4,'amazon_order_id'=>'702-2751217-8386605','safe_t_id'=>null,
    'state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,'physical_status'=>SvAmazonReturnPhysicalStatuses::NOT_RECEIVED,
    'refund_at'=>'2026-04-28 21:06:57','seller_debit_at'=>'2026-04-28 21:06:57','refund_initiator'=>SvAmazonRefundInitiators::AMAZON_CUSTOMER_SERVICE,
    'expected_reimbursement_amount'=>'106.14','reconciled_credit_amount'=>'0.00','marketplace_id'=>'A2Q3Y263D00KWC','program'=>SvAmazonReturnPrograms::FBA,
];
$decision4=$coordinator->nextAction($case4,[],['eligible'=>false,'state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED]);
gateSame('WAIT',$decision4['action'],'expired recovery window must remain non-writing wait');
gateSame('RECOVERY_WINDOW_EXPIRED',$decision4['reason'],'expired recovery window must remain explicit');
gateSame([['case_id'=>4,'patch'=>['state'=>SvAmazonReturnStates::CREDIT_PENDING]]],$p->cases->updates,'expired automatic case with unrecovered financial exposure must not retain a human-review state');
$p5=new GatePersistence();
$enabled=new SvAmazonReturnsConfig(['AMAZON_RETURNS_LEARNED_RULE_EXECUTION'=>'1']);
$coordinator5=new SvAmazonDecisionCoordinator(new SvAmazonSafeTDecisionEngine(null,new DateTimeImmutable('2026-09-09T22:00:00Z')),$p5,$enabled);
$case5=[
    'id'=>5,'amazon_order_id'=>'702-5555555-5555555','safe_t_id'=>null,
    'state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,'physical_status'=>SvAmazonReturnPhysicalStatuses::NOT_RECEIVED,
    'refund_at'=>'2026-07-20 00:00:00','seller_debit_at'=>'2026-07-20 00:00:00','refund_initiator'=>SvAmazonRefundInitiators::UNKNOWN,
    'expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00','marketplace_id'=>'A2Q3Y263D00KWC','program'=>SvAmazonReturnPrograms::STANDARD,
];
$policy5=['eligible'=>true,'state'=>SvAmazonReturnStates::SAFE_T_ELIGIBLE,'eligibility_at'=>'2026-09-03 00:00:00','policy_version_id'=>'v2'];
$base5=(new SvAmazonSafeTDecisionEngine())->nextAction($case5,[],$policy5,new DateTimeImmutable('2026-09-09T22:00:00Z'));
$context5=SvAmazonReviewContext::build($case5,[],$policy5,$base5);
$p5->learnedRules->rows=[['id'=>51,'version'=>1,'status'=>'ACTIVE','match'=>['review_reason'=>$context5['signature']['review_reason'],'refund_initiator'=>'UNKNOWN'],'effect'=>['action'=>'CHECK_FINANCES','parameters'=>['date_binding'=>'NONE']]]];
$decision5=$coordinator5->nextAction($case5,[],$policy5);
gateSame('CHECK_FINANCES',$decision5['action'],'approved learned rule must resolve to its automatic action');
gateSame([5],$p5->reviews->resolved,'learned automatic decision must resolve any stale open review episode');
gateSame([['case_id'=>5,'patch'=>['state'=>SvAmazonReturnStates::SAFE_T_ELIGIBLE]]],$p5->cases->updates,'learned automatic decision must clear the persisted review gate');
$p6=new GatePersistence();
$coordinator6=new SvAmazonDecisionCoordinator(new SvAmazonSafeTDecisionEngine(null,new DateTimeImmutable('2026-09-09T22:00:00Z')),$p6,new SvAmazonReturnsConfig());
$case6=[
    'id'=>6,'amazon_order_id'=>'701-5544982-3737862','safe_t_id'=>null,
    'state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,'physical_status'=>SvAmazonReturnPhysicalStatuses::NOT_RECEIVED,
    'refund_at'=>'2026-08-25 20:00:05','seller_debit_at'=>'2026-08-25 20:00:05','refund_initiator'=>SvAmazonRefundInitiators::AMAZON_CUSTOMER_SERVICE,
    'expected_reimbursement_amount'=>'32.74','reconciled_credit_amount'=>'0.00','marketplace_id'=>'A2Q3Y263D00KWC','program'=>SvAmazonReturnPrograms::FBA,
];
$timeline6=[[
    'id'=>601,'case_id'=>6,'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-09-09 21:59:00',
    'payload'=>['refresh_complete'=>true,'ambiguous_reimbursement_transactions'=>0,'unsettled_financial_evidence'=>false,'outstanding_amount'=>'32.74'],
]];
$decision6=$coordinator6->nextAction($case6,$timeline6,['eligible'=>false,'state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED],new DateTimeImmutable('2026-09-09T22:00:00Z'));
gateSame('SELLER_SUPPORT_OPEN',$decision6['action'],'fresh classic FBA residual must escalate automatically to Seller Support');
gateSame([['case_id'=>6,'patch'=>['state'=>SvAmazonReturnStates::CREDIT_PENDING]]],$p6->cases->updates,'automatic Seller Support escalation must clear the persisted human-review gate');
echo "decision-coordinator-clears-review-gate-test: OK\n";
