<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';

// A return that came back physically damaged/discrepant must never get an automatic
// initial SAFE-T submission — opening that claim is manual-only for the user
// (docs/MEMORIA-DO-PROJETO.md, Decisão 05/09/2026). This must hold even on the
// PARTIAL_REIMBURSEMENT_RESIDUAL_UNPAID path, which runs before
// SvAmazonReturnActionRouter::decide() ever sees the case.
$case=[
    'id'=>90001,'amazon_order_id'=>'702-9999999-9999999','marketplace_id'=>'A2Q3Y263D00KWC',
    'program'=>'STANDARD','state'=>'POLICY_REVIEW_REQUIRED','physical_status'=>'RECEIVED_DISCREPANT',
    'refund_at'=>'2026-08-01 00:00:00','refund_initiator'=>'UNKNOWN',
    'expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'40.00','safe_t_id'=>null,
];
$timeline=[[
    'case_id'=>90001,'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','source'=>'SP_API_FINANCES',
    'occurred_at'=>'2026-09-27 12:00:00',
    'payload'=>['refresh_complete'=>true,'unsettled_financial_evidence'=>false,'ambiguous_reimbursement_transactions'=>0,'outstanding_amount'=>60],
]];
$policy=['eligible'=>true,'policy_version_id'=>7441,'eligibility_at'=>'2026-08-15 00:00:00'];
$now=new DateTimeImmutable('2026-09-27 12:30:00',new DateTimeZone('UTC'));

$decision=(new SvAmazonSafeTDecisionEngine())->nextAction($case,$timeline,$policy,$now);
if(($decision['action']??null)!=='HUMAN_REVIEW' || ($decision['reason']??null)!=='DAMAGED_RETURN_INITIAL_CLAIM_MANUAL_ONLY'){
    throw new RuntimeException('Damaged/discrepant return must force manual review, not auto-submit SAFE-T: '.json_encode($decision));
}

// Sanity: the same case without the physical discrepancy still follows the automated path.
$caseOk=$case; $caseOk['physical_status']='RECEIVED_OK';
$decisionOk=(new SvAmazonSafeTDecisionEngine())->nextAction($caseOk,$timeline,$policy,$now);
if(($decisionOk['action']??null)!=='SAFE_T_SUBMIT'){
    throw new RuntimeException('Non-discrepant partial-reimbursement case must still auto-submit SAFE-T: '.json_encode($decisionOk));
}

echo "amazon-returns-safe-t-damaged-partial-reimbursement-test: OK\n";
