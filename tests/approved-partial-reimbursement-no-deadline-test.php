<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/SafeTDecisionEngine.php';

function aprEq(mixed $expected,mixed $actual,string $message):void
{
    if($expected!==$actual){
        throw new RuntimeException($message."\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true));
    }
}

$now=new DateTimeImmutable('2026-09-09 18:40:00',new DateTimeZone('UTC'));
$engine=new SvAmazonSafeTDecisionEngine();

$fixtures=[
    [
        'case'=>[
            'id'=>13246,'amazon_order_id'=>'702-5404465-2676215','program'=>'FBA',
            'physical_status'=>'NOT_RECEIVED','state'=>'CREDIT_PENDING',
            'refund_initiator'=>'AMAZON_CUSTOMER_SERVICE','refund_at'=>'2026-07-09 20:02:13',
            'seller_debit_at'=>'2026-07-09 20:02:13','expected_reimbursement_amount'=>'61.33',
            'reconciled_credit_amount'=>'47.06','safe_t_id'=>'91582-36431-8749346',
            'appeal_deadline_at'=>null,
        ],
        'outstanding'=>'14.27',
    ],
    [
        'case'=>[
            'id'=>13258,'amazon_order_id'=>'702-9768645-2085047','program'=>'FBA',
            'physical_status'=>'NOT_RECEIVED','state'=>'POLICY_REVIEW_REQUIRED',
            'refund_initiator'=>'UNKNOWN','refund_at'=>'2026-06-18 02:11:25',
            'seller_debit_at'=>'2026-06-18 02:11:25','expected_reimbursement_amount'=>'67.70',
            'reconciled_credit_amount'=>'49.61','safe_t_id'=>'51916-09249-3896045',
            'appeal_deadline_at'=>null,
        ],
        'outstanding'=>'18.09',
    ],
];

foreach($fixtures as $fixture){
    $case=$fixture['case'];
    $timeline=[
        [
            'id'=>1,'case_id'=>$case['id'],'event_type'=>'SAFE_T_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
            'occurred_at'=>'2026-09-09 17:24:28',
            'payload'=>[
                'safe_t_id'=>$case['safe_t_id'],'claim_status'=>'APPROVED','appeal_submitted'=>false,
                'appeal_denied'=>false,'appeal_deadline_at'=>null,'decision_text'=>null,
            ],
        ],
        [
            'id'=>2,'case_id'=>$case['id'],'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','source'=>'SP_API_FINANCES',
            'occurred_at'=>'2026-09-09 18:39:37',
            'payload'=>[
                'refresh_complete'=>true,'outstanding_amount'=>$fixture['outstanding'],
                'ambiguous_reimbursement_transactions'=>0,'unsettled_financial_evidence'=>false,
            ],
        ],
    ];
    $decision=$engine->nextAction($case,$timeline,['eligible'=>false,'state'=>'CREDIT_PENDING'],$now);
    aprEq('SELLER_SUPPORT_OPEN',$decision['action']??null,'Approved SAFE-T with a verified unpaid balance must not require a human merely because the appeal deadline is absent. Order '.$case['amazon_order_id']);
    aprEq('APPROVED_PARTIAL_REIMBURSEMENT_SUPPORT_RECOVERY',$decision['reason']??null,'Approved partial reimbursement without an appeal deadline needs an explicit automatic recovery reason. Order '.$case['amazon_order_id']);
}

echo "approved-partial-reimbursement-no-deadline-test: OK\n";
