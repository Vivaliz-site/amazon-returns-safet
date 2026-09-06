<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/ReviewContext.php';

function rcsAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rcsSame(mixed $want,mixed $got,string $message):void{
    if($want!==$got)throw new RuntimeException($message.' want='.json_encode($want).' got='.json_encode($got));
}
function rcsCase(array $changes=[]):array{
    return array_replace([
        'id'=>31,'amazon_order_id'=>'702-1111111-2222222','safe_t_id'=>'98143-99485-9285859',
        'marketplace_id'=>'A2Q3Y263D00KWC','program'=>'STANDARD','state'=>'SAFE_T_DENIED',
        'physical_status'=>'NOT_RECEIVED','refund_initiator'=>'AMAZON_AUTOMATIC',
        'refund_at'=>'2026-07-01 12:00:00','refund_amount'=>'100.00',
        'expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00',
        'eligibility_at'=>'2026-08-15 12:00:00','appeal_deadline_at'=>'2026-09-15 18:00:00',
    ],$changes);
}
function rcsTimeline(array $changes=[]):array{
    $event=[
        'id'=>501,'case_id'=>31,'event_type'=>'SAFE_T_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
        'occurred_at'=>'2026-09-05 14:00:00','evidence_sha256'=>hash('sha256','evidence-a'),
        'payload'=>['safe_t_id'=>'98143-99485-9285859','claim_status'=>'DENIED',
            'decision_text'=>'Aguarde ate 10/09/2026 para o ressarcimento.'],
    ];
    return [array_replace_recursive($event,$changes)];
}

$policy=['eligible'=>true,'state'=>'SAFE_T_ELIGIBLE','policy_version_id'=>22,'eligibility_at'=>'2026-08-15 12:00:00'];
$review=['action'=>'HUMAN_REVIEW','reason'=>'PROMISED_ACTION_DATE_UNRESOLVED'];
$a=SvAmazonReviewContext::build(rcsCase(),rcsTimeline(),$policy,$review);
$bCase=rcsCase([
    'id'=>44,'amazon_order_id'=>'702-9999999-8888888','safe_t_id'=>'12345-67890-1234567',
    'refund_at'=>'2026-07-08 09:00:00','refund_amount'=>'289.90',
    'expected_reimbursement_amount'=>'289.90','appeal_deadline_at'=>'2026-09-22 18:00:00',
]);
$bTimeline=rcsTimeline([
    'id'=>777,'case_id'=>44,'occurred_at'=>'2026-09-07 09:30:00',
    'evidence_sha256'=>hash('sha256','evidence-b'),
    'payload'=>['safe_t_id'=>'12345-67890-1234567','claim_status'=>'DENIED',
        'decision_text'=>'Aguarde ate 17/09/2026 para o ressarcimento.'],
]);
$b=SvAmazonReviewContext::build($bCase,$bTimeline,$policy,$review);
rcsSame($a['signature_hash'],$b['signature_hash'],'IDs/amounts/literal dates must be excluded from equality signature');
rcsAssert($a['variables']['ORDER_ID']!==$b['variables']['ORDER_ID'],'Order IDs belong in variables, not signature');
rcsAssert($a['variables']['PROMISED_DATE']!==$b['variables']['PROMISED_DATE'],'Promised literal date must remain variable');
rcsAssert($a['variables']['OUTSTANDING_AMOUNT']!==$b['variables']['OUTSTANDING_AMOUNT'],'Literal amount must remain variable');
rcsSame('SAFE_T_DENIED',$a['signature']['evidence_pattern'],'SAFE-T denial evidence pattern');
rcsSame('EXPLICIT_DATE',$a['signature']['wait_condition'],'Explicit Amazon wait category');
rcsSame('DEADLINE_KNOWN',$a['signature']['appeal_window'],'Known appeal deadline posture');
$program=SvAmazonReviewContext::build(rcsCase(['program'=>'DELIVERY_BY_AMAZON']),rcsTimeline(),$policy,$review);
rcsAssert($a['signature_hash']!==$program['signature_hash'],'Program is material');
$lifecycle=SvAmazonReviewContext::build(rcsCase(['state'=>'APPEAL_SUBMITTED']),rcsTimeline(),$policy,$review);
rcsSame('APPEAL_SUBMITTED',$lifecycle['signature']['lifecycle'],'Appeal-submitted lifecycle category');
rcsAssert($a['signature_hash']!==$lifecycle['signature_hash'],'Lifecycle is material');
$physical=SvAmazonReviewContext::build(rcsCase(['physical_status'=>'RECEIVED_DISCREPANT']),rcsTimeline(),$policy,$review);
rcsSame('OPENED',$physical['signature']['damaged_manual_opening'],'Existing SAFE-T means damaged manual initial opening exists');
rcsAssert($a['signature_hash']!==$physical['signature_hash'],'Physical/damaged posture is material');
$initiator=SvAmazonReviewContext::build(rcsCase(['refund_initiator'=>'SELLER']),rcsTimeline(),$policy,$review);
rcsAssert($a['signature_hash']!==$initiator['signature_hash'],'Refund initiator is material');
$partial=SvAmazonReviewContext::build(rcsCase(['reconciled_credit_amount'=>'50.00']),rcsTimeline(),$policy,$review);
rcsSame('PARTIAL_CREDIT',$partial['signature']['financial'],'Partial credit category');
rcsAssert($a['signature_hash']!==$partial['signature_hash'],'Financial posture is material');
$unresolvedWait=rcsTimeline(['payload'=>['safe_t_id'=>'98143-99485-9285859','claim_status'=>'DENIED','decision_text'=>'Aguarde nossa resposta para o ressarcimento.']]);
$wait=SvAmazonReviewContext::build(rcsCase(),$unresolvedWait,$policy,$review);
rcsSame('UNRESOLVED_DATE',$wait['signature']['wait_condition'],'Ambiguous wait date must fail closed as unresolved category');
rcsSame(null,$wait['variables']['PROMISED_DATE'],'Unresolved promise cannot invent a date');
$unknown=SvAmazonReviewContext::build(rcsCase(['program'=>'ALIEN','physical_status'=>'MYSTERY','refund_initiator'=>'OTHER']),rcsTimeline(),$policy,$review);
rcsSame('UNKNOWN',$unknown['signature']['program'],'Unknown program normalized');
rcsSame('UNKNOWN',$unknown['signature']['physical'],'Unknown physical status normalized');
rcsSame('UNKNOWN',$unknown['signature']['refund_initiator'],'Unknown refund initiator normalized');
rcsSame(true,$unknown['facts']['has_unknown_material_fact'],'Unknown material facts exposed for fail-closed matching');

$noClaim=SvAmazonReviewContext::build(rcsCase(['safe_t_id'=>null,'state'=>'POLICY_REVIEW_REQUIRED']),[],['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'],$review);
rcsSame('NONE',$noClaim['signature']['lifecycle'],'No claim lifecycle');
rcsSame('NOT_APPLICABLE',$noClaim['signature']['appeal_window'],'No claim has no appeal window');

$conflictTimeline=rcsTimeline();
$conflictTimeline[]=[
    'id'=>502,'case_id'=>31,'event_type'=>'PHYSICAL_RECEIVED','source'=>'WAREHOUSE',
    'occurred_at'=>'2026-09-05 15:00:00','evidence_sha256'=>hash('sha256','warehouse'),
    'payload'=>['physical_status'=>'RECEIVED_OK','quantity'=>1],
];
$conflict=SvAmazonReviewContext::build(rcsCase(),$conflictTimeline,$policy,$review);
rcsSame(true,$conflict['facts']['material_conflict'],'Contradictory authoritative physical fact must fail closed');
rcsSame(true,$conflict['signature']['material_conflict'],'Conflict is part of canonical signature posture');
rcsAssert(count($conflict['evidence_refs'])>=2,'Relevant evidence references retained outside signature');
rcsAssert(!str_contains(json_encode($a['signature']),$a['variables']['ORDER_ID']),'Order ID leaked into signature');
rcsAssert(!str_contains(json_encode($a['signature']),(string)$a['variables']['PROMISED_DATE']),'Promised date leaked into signature');

$laterFinance=rcsTimeline();
$laterFinance[]=[
    'id'=>900,'case_id'=>31,'event_type'=>'FINANCIAL_RECONCILIATION_CONFIRMED','source'=>'SP_API_FINANCES',
    'occurred_at'=>'2026-09-05 16:00:00','evidence_sha256'=>hash('sha256','finance-later'),
    'payload'=>['credit_amount'=>'0.00','outstanding_amount'=>'100.00'],
];
$stableTrigger=SvAmazonReviewContext::build(rcsCase(),$laterFinance,$policy,$review);
rcsSame('SAFE_T_DENIED',$stableTrigger['signature']['evidence_pattern'],'Later routine finance evidence must not replace the Amazon evidence that triggered this review');
rcsSame($a['signature_hash'],$stableTrigger['signature_hash'],'Routine later evidence must not destabilize the similarity signature');

echo "review-context-signature-test: OK\n";
