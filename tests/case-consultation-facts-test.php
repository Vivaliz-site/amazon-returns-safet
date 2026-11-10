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

// A newer Amazon Support email must be visible without silently replacing the
// older canonical binding, and Gmail alone must never count as Seller Central proof.
$supportRefs=SvAmazonCaseConsultation::supportCaseReferences(
    ['support_case_id'=>'22000000001'],
    [
        ['event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
         'occurred_at'=>'2026-10-15 10:00:00','payload'=>['case_id'=>'22000000001','case_status'=>'OPEN']],
        ['event_type'=>'SELLER_SUPPORT_EMAIL','source'=>'GMAIL',
         'occurred_at'=>'2026-10-27 21:19:01','payload'=>['support_case_id'=>'22000000002']],
        ['event_type'=>'SELLER_SUPPORT_EMAIL','source'=>'GMAIL',
         'occurred_at'=>'2026-10-27 22:00:00','payload'=>['support_case_id'=>'22000000002']],
        ['event_type'=>'SELLER_SUPPORT_EMAIL','source'=>'GMAIL',
         'occurred_at'=>'2026-10-28 10:00:00','payload'=>['support_case_id'=>'123']],
    ]
);
ccfSame(2,count($supportRefs),'Support references must deduplicate valid IDs and ignore malformed IDs.');
ccfSame('22000000002',$supportRefs[0]['id'],'Newest support thread should be visible first.');
ccfSame(false,$supportRefs[0]['is_primary'],'Newer Gmail thread must not overwrite the binding.');
ccfSame(false,$supportRefs[0]['seller_central_verified'],'A support email is not an official portal status readback.');
ccfSame('2026-10-27 22:00:00',$supportRefs[0]['last_observed_at'],'Latest support email timestamp must win.');
ccfSame('22000000001',$supportRefs[1]['id'],'Canonical support binding must remain visible.');
ccfSame(true,$supportRefs[1]['is_primary'],'Canonical binding must remain distinguished.');
ccfSame(true,$supportRefs[1]['seller_central_verified'],'Only a Seller Central status event proves an observed status.');
ccfSame([],SvAmazonCaseConsultation::supportCaseReferences([],[]),'Absent support evidence must project an empty catalog.');
echo "case-consultation-facts-test: OK\n";
