<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';

function cffrSame(mixed $want,mixed $got,string $why):void{
    if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));
}

$engine=new SvAmazonSafeTDecisionEngine();
$now=new DateTimeImmutable('2026-09-08 14:30:00',new DateTimeZone('UTC'));
$policy=['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'];
$base=[
    'id'=>496,'amazon_order_id'=>'702-2751217-8386605','program'=>'FBA','safe_t_id'=>null,
    'state'=>'POLICY_REVIEW_REQUIRED','physical_status'=>'NOT_RECEIVED',
    'refund_at'=>'2026-06-15 21:06:57','seller_debit_at'=>'2026-06-15 21:06:57',
    'refund_initiator'=>'UNKNOWN','expected_reimbursement_amount'=>'106.14','reconciled_credit_amount'=>'0.00',
];
$finance=[
    'id'=>1,'case_id'=>496,'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','source'=>'SP_API_FINANCES',
    'occurred_at'=>'2026-09-08 14:01:33','payload'=>[
        'refresh_complete'=>true,'credit_amount'=>'0.00','outstanding_amount'=>'106.14',
        'unclassified_transactions'=>3,'ambiguous_reimbursement_transactions'=>0,
        'unsettled_financial_evidence'=>false,
    ],
];
$fresh=$engine->nextAction($base,[$finance],$policy,$now);
cffrSame('SELLER_SUPPORT_OPEN',$fresh['action']??null,'Classic FBA with fresh verified unpaid balance must leave CHECK_FINANCES and open Seller Support');
cffrSame('CLASSIC_FBA_UNPAID_AFTER_FINANCE_RECONCILIATION',$fresh['reason']??null,'Classic FBA escalation reason must be auditable');

$recent=$base;
$recent['id']=1314;$recent['amazon_order_id']='702-9188918-3998665';
$recent['refund_at']='2026-09-05 00:07:53';$recent['seller_debit_at']='2026-09-05 00:07:53';
$recentFinance=$finance;$recentFinance['case_id']=1314;
$recentDecision=$engine->nextAction($recent,[$recentFinance],$policy,$now);
cffrSame('WAIT',$recentDecision['action']??null,'Classic FBA must not open Seller Support before the owner-approved D45 first action date');
cffrSame('CLASSIC_FBA_D45_PENDING',$recentDecision['reason']??null,'Classic FBA pre-D45 wait must remain auditable');
cffrSame('2026-10-20 00:07:53',$recentDecision['next_action_at']??null,'Classic FBA must wake exactly at D45 from the Amazon refund');

$partial=$base;
$partial['id']=508;$partial['amazon_order_id']='702-5835321-5101017';
$partial['expected_reimbursement_amount']='71.31';$partial['reconciled_credit_amount']='64.42';
$financePartial=$finance;$financePartial['case_id']=508;
$financePartial['payload']['credit_amount']='64.42';$financePartial['payload']['outstanding_amount']='6.89';
$partialDecision=$engine->nextAction($partial,[$financePartial],$policy,$now);
cffrSame('SELLER_SUPPORT_OPEN',$partialDecision['action']??null,'Classic FBA partial reimbursement above tolerance must resume to Seller Support after finance verification');

$stale=$finance;$stale['occurred_at']='2026-09-08 10:00:00';
cffrSame('CHECK_FINANCES',$engine->nextAction($base,[$stale],$policy,$now)['action']??null,'Classic FBA requires a fresh finance receipt before escalation');

$noRefund=$base;
$noRefund['id']=901;$noRefund['amazon_order_id']='701-0000000-0000901';$noRefund['refund_at']=null;$noRefund['seller_debit_at']=null;
$noRefundFinance=$finance;$noRefundFinance['case_id']=901;
$noRefundDecision=$engine->nextAction($noRefund,[$noRefundFinance],$policy,$now);
cffrSame('WAIT',$noRefundDecision['action']??null,'A just-checked FBA order without refund must not immediately request another full finance scan');
cffrSame('CLASSIC_FBA_FINANCE_RECENTLY_CHECKED',$noRefundDecision['reason']??null,'Fresh no-refund finance evidence must enter a bounded cooldown');
cffrSame('2026-09-08 16:01:33',$noRefundDecision['next_action_at']??null,'The next finance recheck must be scheduled exactly two hours after the fresh receipt');
$noRefundStale=$noRefundFinance;$noRefundStale['occurred_at']='2026-09-08 11:00:00';
cffrSame('CHECK_FINANCES',$engine->nextAction($noRefund,[$noRefundStale],$policy,$now)['action']??null,'A no-refund FBA order must resume finance checks after the cooldown expires');
cffrSame('CHECK_FINANCES',$engine->nextAction($noRefund,[],$policy,$now)['action']??null,'A no-refund FBA order with no finance evidence still requires an initial finance check');

$ambiguous=$base;$ambiguous['id']=902;$ambiguous['amazon_order_id']='701-0000000-0000902';
$ambiguousFinance=$finance;$ambiguousFinance['case_id']=902;$ambiguousFinance['payload']['ambiguous_reimbursement_transactions']=1;$ambiguousFinance['payload']['unsettled_financial_evidence']=true;
$ambiguousDecision=$engine->nextAction($ambiguous,[$ambiguousFinance],$policy,$now);
cffrSame('WAIT',$ambiguousDecision['action']??null,'Fresh but non-actionable finance evidence must not trigger a tight rescan loop');
cffrSame('CLASSIC_FBA_FINANCE_RECENTLY_CHECKED',$ambiguousDecision['reason']??null,'Ambiguous fresh finance evidence must cool down before retry');

$active=$base;$active['support_case_id']='12345678901';$active['support_case_status']='OPEN';
$wait=$engine->nextAction($active,[$finance],$policy,$now);
cffrSame('WAIT',$wait['action']??null,'Existing Seller Support case must suppress duplicate FBA escalation');
cffrSame('SUPPORT_ESCALATION_ALREADY_ACTIVE',$wait['reason']??null,'Duplicate Seller Support suppression must remain auditable');
echo "classic-fba-finance-resume-test: OK\n";
