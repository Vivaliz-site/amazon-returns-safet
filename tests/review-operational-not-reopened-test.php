<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/DecisionCoordinator.php';
require_once __DIR__.'/../includes/amazon-returns/Config.php';
require_once __DIR__.'/../includes/amazon-returns/ReviewOperations.php';
function arSame(mixed $want,mixed $got,string $label):void {
    if($want!==$got)throw new RuntimeException($label.' want='.json_encode($want).' got='.json_encode($got));
}
final class ArLearnedRules {public function active():array{return [];}}
final class ArReviewMemory {
    public array $open=[95364=>['case_id'=>95364,'reason'=>'REFUND_INITIATOR_UNKNOWN']];
    public array $resolved=[];
    public function open(int $id,string $reason,string $hash,array $context):array {
        $this->open[$id]=['case_id'=>$id,'reason'=>$reason];
        return $this->open[$id];
    }
    public function resolveOpenForCase(int $caseId):int {
        if(!isset($this->open[$caseId]))return 0;
        unset($this->open[$caseId]);
        $this->resolved[]=$caseId;
        return 1;
    }
}
final class ArPersistence {
    public ArLearnedRules $learnedRules;
    public ArReviewMemory $reviews;
    public function __construct(){$this->learnedRules=new ArLearnedRules();$this->reviews=new ArReviewMemory();}
}
$p=new ArPersistence();
$engine=new SvAmazonSafeTDecisionEngine(null,new DateTimeImmutable('2026-11-11 12:00:00',new DateTimeZone('UTC')));
$coord=new SvAmazonDecisionCoordinator($engine,$p,new SvAmazonReturnsConfig());
$case=['id'=>95364,'amazon_order_id'=>'701-3960857-9657016','safe_t_id'=>null,
    'state'=>'POLICY_REVIEW_REQUIRED','physical_status'=>'NOT_RECEIVED',
    'refund_at'=>'2026-09-27 02:39:59','refund_initiator'=>'UNKNOWN',
    'expected_reimbursement_amount'=>'22.60','reconciled_credit_amount'=>'0.00',
    'marketplace_id'=>'A2Q3Y263D00KWC','program'=>'FBA_ONSITE'];
$policy=['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'];
$decision=$coord->nextAction($case,[],$policy);
arSame('BLOCKED_REVIEW',$decision['action'],'Missing Amazon initiator proof stays a real operational blocker');
arSame('REFUND_INITIATOR_UNKNOWN',$decision['reason'],'Missing proof stays explicit, not invented');
arSame('SYSTEM',$decision['review_owner']??null,'Missing proof goes to system, not owner');
arSame([],array_keys($p->reviews->open),'Former operational review closed and not re-created');
arSame([95364],$p->reviews->resolved,'Legacy review resolved with case-scoped operation');
$again=$coord->nextAction($case,[],$policy);
arSame([],array_keys($p->reviews->open),'Repeated scheduling must not reopen same operational review');
arSame([95364],$p->reviews->resolved,'Repeated scheduling must not create a new closure');
foreach([
    'SUPPORT_CASE_NOT_REOPENABLE',
    'SUPPORT_AMBIGUOUS_PRIOR_SUBMISSION_UNRESOLVED',
    'SELLER_SUPPORT_CHAT_READ_BACK_NOT_CONFIRMED',
    'REFUND_INITIATOR_UNKNOWN',
] as $reason){
    arSame(false,SvAmazonReviewOperations::requiresManualDecision([
        'reason'=>$reason,'context'=>['signature'=>['material_conflict'=>false],'facts'=>[]],
    ]),'Known system-owned reason '.$reason);
}
$manual=$case;
$manual['id']=999;
$manual['safe_t_id']='12345-98765-1234567';
$manual['state']='SAFE_T_DENIED';
$manual['refund_initiator']='AMAZON_CUSTOMER_SERVICE';
$manual['appeal_deadline_at']=null;
$manualDecision=$coord->nextAction($manual,[],['eligible'=>true,'state'=>'SAFE_T_DENIED']);
arSame('HUMAN_REVIEW',$manualDecision['action'],'Genuine manual exception remains visible');
arSame('HUMAN',$manualDecision['review_owner']??null,'Genuine manual exception must remain assigned to owner');
arSame([999],array_keys($p->reviews->open),'Manual exception creates a review');
echo "review-operational-not-reopened-test: OK\n";
