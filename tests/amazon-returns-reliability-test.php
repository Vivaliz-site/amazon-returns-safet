<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/TenantOutbox.php';
require_once __DIR__ . '/../includes/amazon-returns/FinancialReconciler.php';
require_once __DIR__ . '/../workers/amazon-returns/scheduler.php';
require_once __DIR__ . '/../workers/amazon-returns/reconcile.php';

function rlSame(mixed $expected,mixed $actual,string $message):void
{
    if($expected!==$actual){
        throw new RuntimeException($message.'\nExpected: '.var_export($expected,true).'\nActual: '.var_export($actual,true));
    }
}
function rlAssert(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$now=new DateTimeImmutable('2026-09-01T12:00:00Z');
$retry=SvAmazonTenantReturnsOutbox::retryDecision(['attempt_count'=>1,'payload'=>[]],$now);
rlSame('RETRY',$retry['status'],'Early failure must retry.');
rlAssert($retry['next_at']>$now,'Retry must be scheduled in the future.');
$exhausted=SvAmazonTenantReturnsOutbox::retryDecision(['attempt_count'=>5,'payload'=>[]],$now);
rlSame('DEAD_LETTER',$exhausted['status'],'Max attempts must go to DLQ.');
$deadline=SvAmazonTenantReturnsOutbox::retryDecision([
    'attempt_count'=>1,'payload'=>['deadline_at'=>'2026-09-01T12:00:30Z'],
],$now);
rlSame('DEAD_LETTER',$deadline['status'],'Retry crossing deadline must fail terminally.');
rlSame(true,SvAmazonTenantReturnsOutbox::leaseExpired('2026-09-01T11:50:00Z',$now),'Stale lease must be reclaimable.');
rlSame(false,SvAmazonTenantReturnsOutbox::leaseExpired('2026-09-01T11:59:00Z',$now),'Fresh lease must not be stolen.');

$reconciler=new SvAmazonFinancialReconciler();
// Official BRL transactions require a matching, known case currency.
$case=['state'=>'SAFE_T_APPROVED','expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00','marketplace_id'=>'A2Q3Y263D00KWC'];
$none=$reconciler->reconcile($case,[]);
rlSame('CREDIT_PENDING',$none['state'],'Approved SAFE-T without credit is CREDIT_PENDING.');
rlSame('100.00',$none['outstanding_amount'],'No credit leaves full amount outstanding.');
$partial=$reconciler->reconcile($case,[['seller_effect_amount'=>'40.00','transaction_id'=>'credit-1']]);
rlSame('CREDIT_PENDING',$partial['state'],'Partial credit stays pending.');
rlSame('40.00',$partial['credit_amount'],'Partial credit amount.');
rlSame('60.00',$partial['outstanding_amount'],'Partial outstanding amount.');
$full=$reconciler->reconcile($case,[['seller_effect_amount'=>'100.00','transaction_id'=>'credit-2']]);
rlSame('RECOVERED',$full['state'],'Matching credit closes recovery.');
$duplicateCredit=$reconciler->reconcile($case,[
    ['transaction_id'=>'credit-deferred','transaction_type'=>'FBAInventoryReimbursement','transaction_status'=>'DEFERRED_RELEASED','posted_at'=>'2026-09-01T10:00:00Z','total_amount'=>['amount'=>'40.00','currency'=>'BRL']],
    ['transaction_id'=>'credit-released','transaction_type'=>'FBAInventoryReimbursement','transaction_status'=>'RELEASED','posted_at'=>'2026-09-03T10:00:00Z','total_amount'=>['amount'=>'40.00','currency'=>'BRL']],
]);
rlSame('40.00',$duplicateCredit['credit_amount'],'Deferred/released lifecycle must count once.');
rlSame(['credit-deferred','credit-released'],$duplicateCredit['transaction_ids'],'Both transaction IDs remain evidence.');
$reversed=$reconciler->reconcile([
    'state'=>'RECOVERED','expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'100.00',
],[
    ['seller_effect_amount'=>'100.00','transaction_id'=>'credit-2'],
    ['seller_effect_amount'=>'-100.00','transaction_id'=>'reversal-1'],
]);
rlSame('CREDIT_PENDING',$reversed['state'],'Later reversal must reopen exposure.');
rlSame(true,$reversed['reopened'],'Reversal of recovered case must be flagged.');

$worker=new SvAmazonReturnsReconcileWorker();
rlSame([], $worker->transactionsFromEvents([]), 'No financial event must remain no credit, not denial.');
$safeTTransactions=$worker->transactionsFromEvents([[
    'event_type'=>'SAFE_T_REIMBURSEMENT_OBSERVED',
    'payload'=>[
        'safe_t_claim_id'=>'98143-99485-9285859',
        'posted_at'=>'2026-08-05T14:30:00Z',
        'reimbursed_amount'=>['amount'=>'100.00','currency'=>'BRL'],
        'response_sha256'=>[str_repeat('a',64)],
    ],
]]);
rlSame(1,count($safeTTransactions),'SAFE-T reimbursement must become one authoritative credit.');
rlSame('SAFE_T_REIMBURSEMENT',$safeTTransactions[0]['transaction_type']??null,'SAFE-T credit classification must be explicit.');
rlSame('100.00',$safeTTransactions[0]['total_amount']['amount']??null,'SAFE-T credit amount must remain exact.');
rlSame('RECOVERED',$worker->reconcileCase($case,$safeTTransactions)['state'],'SAFE-T approval closes only after the reimbursement credit is observed.');

rlSame(false,SvAmazonReturnsScheduler::isWriteAction(['action'=>'WAIT']),'WAIT is not a write.');
rlSame(true,SvAmazonReturnsScheduler::isWriteAction(['action'=>'SELLER_SUPPORT_OPEN']),'Support open is a write.');
rlSame(true,SvAmazonReturnsScheduler::isWriteAction(['action'=>'SAFE_T_EMAIL_REVIEW']),'Review email is a write.');
rlSame('gmail',SvAmazonReturnsScheduler::dependencyForAction('SAFE_T_EMAIL_REVIEW'),'Review email depends on Gmail.');
rlSame('seller_central_bridge',SvAmazonReturnsScheduler::dependencyForAction('SAFE_T_APPEAL'),'Appeal depends on Seller Central bridge.');

echo "amazon-returns-reliability-test: OK\n";
