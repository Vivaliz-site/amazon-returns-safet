<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/CaseConsultation.php';
function ccfSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.json_encode($expected).' actual='.json_encode($actual));
}
$case=['reconciled_credit_amount'=>'50.25'];
$events=[
    ['id'=>1,'event_type'=>'SAFE_T_REIMBURSEMENT_OBSERVED','source'=>'SP_API_FINANCES_V0','occurred_at'=>'2026-08-10 09:00:00','payload'=>['reimbursed_amount'=>['amount'=>'50.25','currency'=>'BRL']]],
    ['id'=>2,'event_type'=>'FINANCIAL_TRANSACTION_OBSERVED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-08-12 10:00:00','created_at'=>'2026-08-12 10:01:00','payload'=>['transaction'=>['transaction_id'=>'held','transaction_type'=>'Adjustment','transaction_status'=>'DEFERRED','description'=>'SERRACReimbursement','posted_at'=>'2026-08-12T10:00:00Z','total_amount'=>['amount'=>'50.25','currency'=>'BRL']]]],
    ['id'=>3,'event_type'=>'FINANCIAL_TRANSACTION_OBSERVED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-08-13 11:00:00','created_at'=>'2026-08-13 11:01:00','payload'=>['transaction'=>['transaction_id'=>'paid','transaction_type'=>'Adjustment','transaction_status'=>'RELEASED','description'=>'SERRACReimbursement','posted_at'=>'2026-08-13T11:00:00Z','total_amount'=>['amount'=>'50.25','currency'=>'BRL']]]],
];
$facts=SvAmazonCaseConsultation::financialFacts($case,$events);
ccfSame('2026-08-13 11:00:00',$facts['amazon_reimbursement_at']??null,'Only a released ledger credit may establish the Amazon reimbursement date.');
ccfSame('50.25',$facts['amazon_reimbursement_amount']??null,'Displayed Amazon reimbursement must equal reconciled seller credit.');
$unpaid=SvAmazonCaseConsultation::financialFacts(['reconciled_credit_amount'=>'0.00'],$events);
ccfSame(null,$unpaid['amazon_reimbursement_at']??null,'A reimbursement notice cannot become a seller payment date without reconciled credit.');$invoiceEvents=[
    ['id'=>10,'event_type'=>'SALES_INVOICE_LINKED','source'=>'ERP_OLIST_INVOICE','occurred_at'=>'2026-09-01 10:00:00','payload'=>['invoice_number'=>'002214','order_id'=>'702-5144267-2415462']],
    ['id'=>11,'event_type'=>'RETURN_INVOICE_LINKED','source'=>'ERP_OLIST_INVOICE','occurred_at'=>'2026-09-02 10:00:00','payload'=>['sales_invoice_number'=>'002214','return_invoice_number'=>'009901','order_id'=>'702-5144267-2415462']],
    ['id'=>12,'event_type'=>'RETURN_INVOICE_LINKED','source'=>'ERP_OLIST_INVOICE','occurred_at'=>'2026-09-03 10:00:00','payload'=>['sales_invoice_number'=>'002214','return_invoice_number'=>'009902','order_id'=>'702-5144267-2415462']],
];
$invoiceFacts=SvAmazonCaseConsultation::invoiceFacts($invoiceEvents);
ccfSame('002214',$invoiceFacts['sales_invoice_number']??null,'Sales invoice must remain visible.');
ccfSame('ERP_OLIST_INVOICE',$invoiceFacts['sales_invoice_source']??null,'Sales invoice origin must be visible.');
ccfSame(['009901','009902'],$invoiceFacts['return_invoice_numbers']??null,'All Tiny/Olist return invoices must be visible.');
$reportFacts=SvAmazonCaseConsultation::invoiceFacts([[
    'event_type'=>'RETURN_REPORT_OBSERVED','source'=>'SP_API_REPORTS','occurred_at'=>'2026-09-04 10:00:00',
    'payload'=>['invoice_number'=>'007700','order_id'=>'702-5144267-2415462'],
]]);
ccfSame('007700',$reportFacts['sales_invoice_number']??null,'Amazon returns-report invoice must be a fallback sales invoice for consultation.');
ccfSame('SP_API_REPORTS',$reportFacts['sales_invoice_source']??null,'Report-derived invoice origin must remain explicit until ERP confirms it.');
echo "case-consultation-facts-test: OK\n";
