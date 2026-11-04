<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';

$now = new DateTimeImmutable('2026-09-07 12:00:00', new DateTimeZone('UTC'));
$engine = new SvAmazonSafeTDecisionEngine();
$case = [
    'id'=>990001, 'amazon_order_id'=>'700-0000000-0000000',
    'program'=>'DELIVERY_BY_AMAZON', 'order_at'=>'2026-06-01 00:00:00',
    'refund_at'=>'2026-07-10 00:00:00', 'seller_debit_at'=>'2026-07-10 00:00:00',
    'physical_status'=>'NOT_RECEIVED', 'refund_initiator'=>'UNKNOWN',
    'expected_reimbursement_amount'=>'100.00', 'reconciled_credit_amount'=>'0.00',
    'state'=>'SAFE_T_ELIGIBLE', 'safe_t_id'=>null,
    'customer_delivery_confirmed'=>true,
];
$policy = ['eligible'=>true, 'policy_version_id'=>1, 'eligibility_at'=>'2026-08-24 00:00:00'];
$finance = [[
    'id'=>1, 'case_id'=>990001, 'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED',
    'source'=>'SP_API_FINANCES', 'occurred_at'=>'2026-09-07 11:00:00',
    'payload'=>['refresh_complete'=>true, 'outstanding_amount'=>'100.00',
        'ambiguous_reimbursement_transactions'=>0, 'unsettled_financial_evidence'=>false],
]];
$expect = static function (string $wantAction, string $wantReason, array $decision, string $scenario): void {
    if (($decision['action']??null)!==$wantAction || ($decision['reason']??null)!==$wantReason) {
        throw new RuntimeException($scenario.' expected '.$wantAction.'/'.$wantReason
            .' got '.json_encode($decision));
    }
};
$expect('BLOCKED_REVIEW','REFUND_INITIATOR_AMAZON_PROOF_REQUIRED',
    $engine->nextAction($case,$finance,$policy,$now),'delivery alone is not issuer proof');
$partial=$case;
$partial['customer_delivery_confirmed']=false;
$partial['reconciled_credit_amount']='19.00';
$expect('BLOCKED_REVIEW','REFUND_INITIATOR_AMAZON_PROOF_REQUIRED',
    $engine->nextAction($partial,$finance,$policy,$now),'partial seller reimbursement is not issuer proof');
$expect('BLOCKED_REVIEW','REFUND_INITIATOR_AMAZON_PROOF_REQUIRED',
    $engine->guardLearnedEffect(['action'=>'SAFE_T_SUBMIT'],$case,[],$policy,$now),
    'learned effects cannot bypass issuer proof');
$known=$case;
$known['refund_initiator']='AMAZON_INITIATED';
$known['customer_delivery_confirmed']=false;
if (($engine->nextAction($known,$finance,$policy,$now)['action']??null)!=='SAFE_T_SUBMIT') {
    throw new RuntimeException('Known Amazon-initiated refund with positive policy remains eligible for D45 processing');
}
if (($engine->nextAction($partial,[],$policy,$now)['action']??null)!=='CHECK_FINANCES') {
    throw new RuntimeException('Unknown initiator must still permit safe read-only financial checks');
}
echo "safe-t-unknown-refund-proof-test: OK\n";
