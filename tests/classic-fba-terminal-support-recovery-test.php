<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';

function cftrSame(mixed $want,mixed $got,string $why):void{
    if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));
}

$engine=new SvAmazonSafeTDecisionEngine();
$now=new DateTimeImmutable('2026-09-19 12:00:00',new DateTimeZone('UTC'));
$policy=['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'];
$case=[
    'id'=>13231,'amazon_order_id'=>'701-0009670-5933042','program'=>'FBA','safe_t_id'=>null,
    'support_case_id'=>'22144700811','state'=>'SUPPORT_ESCALATION','physical_status'=>'NOT_RECEIVED',
    'refund_at'=>'2026-08-01 00:23:39','seller_debit_at'=>'2026-08-01 00:23:39',
    'refund_initiator'=>'AMAZON_CUSTOMER_SERVICE','expected_reimbursement_amount'=>'124.20','reconciled_credit_amount'=>'0.00',
];
$finance=[
    'id'=>9001,'case_id'=>13231,'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','source'=>'SP_API_FINANCES',
    'occurred_at'=>'2026-09-19 11:55:00','payload'=>[
        'refresh_complete'=>true,'credit_amount'=>'0.00','outstanding_amount'=>'124.20',
        'ambiguous_reimbursement_transactions'=>0,'unsettled_financial_evidence'=>false,
    ],
];
$terminalAmbiguous=[
    'id'=>9002,'case_id'=>13231,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-19 11:50:00','payload'=>[
        'case_id'=>'22144700811','case_status'=>'RESOLVED',
        'latest_text'=>'O caso foi encerrado. Consulte os fluxos de devolucao disponiveis para o pedido.',
    ],
];

$decision=$engine->nextAction($case,[$finance,$terminalAmbiguous],$policy,$now);
cftrSame('SELLER_SUPPORT_OPEN',$decision['action']??null,'Terminal ambiguous Seller Support cannot strand a classic FBA case with a fresh verified unpaid balance in human review.');
cftrSame('CLASSIC_FBA_UNPAID_AFTER_FINANCE_RECONCILIATION',$decision['reason']??null,'Terminal ambiguous support must delegate back to the existing classic FBA recovery path.');
cftrSame('FBA_RETURNS_REIMBURSEMENT',$decision['support_route']??null,'Classic FBA recovery must continue through the FBA reimbursement support route.');

$stale=$finance;
$stale['occurred_at']='2026-09-19 08:00:00';
$verifyFirst=$engine->nextAction($case,[$stale,$terminalAmbiguous],$policy,$now);
cftrSame('CHECK_FINANCES',$verifyFirst['action']??null,'A terminal ambiguous support case must refresh finances before re-escalation when the financial proof is stale.');
cftrSame('CLASSIC_FBA_SEPARATE_REIMBURSEMENT_ROUTE',$verifyFirst['reason']??null,'Stale financial evidence must delegate to the existing finance refresh path instead of creating a human review.');

echo "classic-fba-terminal-support-recovery-test: OK\n";
