<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/Config.php';
require_once __DIR__.'/../includes/amazon-returns/SpApiEventSink.php';
require_once __DIR__.'/../includes/amazon-returns/GmailEventSink.php';
require_once __DIR__.'/../includes/amazon-returns/Projector.php';
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';

function faoSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}
function faoAssert(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException($message);
}

$genericAmazonOrder=['programs'=>[],'fulfillment'=>['fulfilledBy'=>'AMAZON']];
faoSame(
    SvAmazonReturnPrograms::FBA_ONSITE,
    SvAmazonSpApiEventSink::programFromOrder($genericAmazonOrder,SvAmazonReturnPrograms::FBA_ONSITE),
    'Account-level FBA Onsite policy must resolve generic AMAZON/FBA evidence to FBA_ONSITE.'
);

faoSame(
    SvAmazonReturnPrograms::DELIVERY_BY_AMAZON,
    SvAmazonSpApiEventSink::programFromOrder(
        ['programs'=>['DELIVERY_BY_AMAZON'],'fulfillment'=>['fulfilledBy'=>'AMAZON']],
        SvAmazonReturnPrograms::FBA_ONSITE
    ),
    'Explicit Delivery by Amazon evidence must not be overwritten by the FBA Onsite account policy.'
);

$shipmentPatch=SvAmazonGmailEventSink::casePatch(
    ['event_type'=>'FBA_SHIPMENT_EMAIL'],
    [],
    SvAmazonReturnPrograms::FBA_ONSITE
);
faoSame(
    SvAmazonReturnPrograms::FBA_ONSITE,
    $shipmentPatch['program']??null,
    'Generic FBA shipment email evidence must resolve to FBA_ONSITE for an FBA Onsite-only account.'
);

$refundPatch=SvAmazonGmailEventSink::casePatch(
    ['event_type'=>'REFUND_ISSUED_EMAIL','program'=>'FBA','amount'=>'10.00','occurred_at'=>'2026-10-07 12:00:00'],
    ['program'=>SvAmazonReturnPrograms::UNKNOWN],
    SvAmazonReturnPrograms::FBA_ONSITE
);
faoSame(
    SvAmazonReturnPrograms::FBA_ONSITE,
    $refundPatch['program']??null,
    'Generic FBA refund evidence must resolve to FBA_ONSITE for an FBA Onsite-only account.'
);

$config=new SvAmazonReturnsConfig(['AMAZON_RETURNS_FULFILLMENT_PROGRAM_OVERRIDE'=>'FBA_ONSITE']);
faoAssert(method_exists($config,'fulfillmentProgramOverride'),'Config must expose the tenant/account fulfillment program override.');
faoSame(SvAmazonReturnPrograms::FBA_ONSITE,$config->fulfillmentProgramOverride(),'Configured FBA Onsite override must normalize deterministically.');

// Historical FBA events must not restore the Classic FBA routing on an Onsite-only account.
$legacyCase=['id'=>77,'quantity_ordered'=>1,'state'=>'CREDIT_PENDING','program'=>SvAmazonReturnPrograms::FBA];
$legacyEvents=[['case_id'=>77,'event_type'=>'ORDER_SYNCED','occurred_at'=>'2026-09-01 00:00:00','payload'=>['program'=>SvAmazonReturnPrograms::FBA,'order_at'=>'2026-08-29 00:00:00']]];
$previousOverride=getenv('AMAZON_RETURNS_FULFILLMENT_PROGRAM_OVERRIDE');
try{
    putenv('AMAZON_RETURNS_FULFILLMENT_PROGRAM_OVERRIDE=FBA_ONSITE');
    $onsiteProjection=SvAmazonReturnProjector::projectFrom($legacyCase,$legacyEvents);
    faoSame(SvAmazonReturnPrograms::FBA_ONSITE,$onsiteProjection['program'],'Replaying a legacy FBA event must honor the configured Onsite account program.');
    $explicitStandard=SvAmazonReturnProjector::projectFrom($legacyCase,[array_replace($legacyEvents[0],['payload'=>['program'=>SvAmazonReturnPrograms::STANDARD]])]);
    faoSame(SvAmazonReturnPrograms::STANDARD,$explicitStandard['program'],'Onsite policy must not rewrite explicit merchant-fulfilled orders.');
    putenv('AMAZON_RETURNS_FULFILLMENT_PROGRAM_OVERRIDE');
    $unscoped=SvAmazonReturnProjector::projectFrom($legacyCase,$legacyEvents);
    faoSame(SvAmazonReturnPrograms::FBA,$unscoped['program'],'Without the account override, another seller must retain generic FBA.');
}finally{
    if($previousOverride===false)putenv('AMAZON_RETURNS_FULFILLMENT_PROGRAM_OVERRIDE');
    else putenv('AMAZON_RETURNS_FULFILLMENT_PROGRAM_OVERRIDE='.$previousOverride);
}

// The first recovery claim for an eligible FBA Onsite order is SAFE-T,
// even if an older Seller Support attempt could not confirm its own readback.
$onsiteCase=[
    'id'=>13206,'amazon_order_id'=>'702-4464185-9728222','program'=>SvAmazonReturnPrograms::FBA_ONSITE,
    'state'=>'CREDIT_PENDING','physical_status'=>'NOT_RECEIVED','safe_t_id'=>null,'support_case_id'=>null,
    'refund_at'=>'2026-08-29 06:08:57','seller_debit_at'=>'2026-08-29 06:08:57',
    'refund_initiator'=>'AMAZON_CUSTOMER_SERVICE','expected_reimbursement_amount'=>'20.46',
    'reconciled_credit_amount'=>'0.00',
];
$failedSupport=[
    'id'=>312,'case_id'=>13206,'event_type'=>'SELLER_CENTRAL_ACTION_RESULT',
    'source'=>'SELLER_CENTRAL','occurred_at'=>'2026-10-13 12:30:00',
    'payload'=>['action'=>'SELLER_SUPPORT_OPEN','status'=>'HUMAN_INTERVENTION_REQUIRED',
        'reason'=>'SELLER_SUPPORT_CHAT_READ_BACK_NOT_CONFIRMED'],
];
$eligible=['eligible'=>true,'state'=>'SAFE_T_ELIGIBLE','policy_version_id'=>5,'eligibility_at'=>'2026-10-13 06:08:57'];
$decisionEngine=new SvAmazonSafeTDecisionEngine();
$now=new DateTimeImmutable('2026-10-13 13:00:00',new DateTimeZone('UTC'));
$freshDecision=$decisionEngine->nextAction($onsiteCase,[$failedSupport],$eligible,$now);
faoSame('SAFE_T_SUBMIT',$freshDecision['action']??null,'An eligible Onsite SAFE-T claim must not be blocked by unrelated failed Seller Support readback.');
$notEligible=$decisionEngine->nextAction($onsiteCase,[$failedSupport],['eligible'=>false,'state'=>'AWAITING_RETURN'],$now);
faoAssert(($notEligible['action']??null)!=='SAFE_T_SUBMIT','Eligibility remains mandatory even with an old Seller Support failure.');
$genericFba=$onsiteCase;$genericFba['program']=SvAmazonReturnPrograms::FBA;
$genericDecision=$decisionEngine->nextAction($genericFba,[$failedSupport],$eligible,$now);
faoSame('BLOCKED_REVIEW',$genericDecision['action']??null,'Other accounts without the Onsite classification must retain their support-readback guard.');
$blockedOnsite=$onsiteCase;
$blockedOnsite['id']=13224;
$blockedOnsite['support_case_id']='22504154261';
$unreopenable=$failedSupport;
$unreopenable['case_id']=13224;
$unreopenable['payload']['reason']='SUPPORT_CASE_NOT_REOPENABLE';
$blockedDecision=$decisionEngine->nextAction($blockedOnsite,[$unreopenable],$eligible,$now);
faoSame('SAFE_T_SUBMIT',$blockedDecision['action']??null,'Unreopenable Seller Support case must not obstruct the first eligible SAFE-T submission.');

$alreadySubmitted=$onsiteCase;$alreadySubmitted['safe_t_id']='12797-64249-3531034';
$submittedDecision=$decisionEngine->nextAction($alreadySubmitted,[$failedSupport],$eligible,$now);
faoAssert(($submittedDecision['action']??null)!=='SAFE_T_SUBMIT','An existing claim must never be resubmitted.');

echo "fba-onsite-account-override-test: OK\n";
