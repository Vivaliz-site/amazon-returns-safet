<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/ShadowAudit.php';

function shSame(mixed $expected,mixed $actual,string $message):void {
    if($expected!==$actual) throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true));
}

$source=['id'=>2,'amazon_order_id'=>'702-1','state'=>'SAFE_T_DENIED','refund_amount'=>'68.29','updated_at'=>'2026-09-04 10:00:00'];
$target=$source;
$target['updated_at']='2026-09-04 11:00:00';
shSame([],SvAmazonReturnsShadowAudit::caseDiff($source,$target),'Operational timestamps must not create shadow mismatches.');

$target['refund_amount']='136.58';
$diff=SvAmazonReturnsShadowAudit::caseDiff($source,$target);
shSame(['refund_amount'=>['source'=>'68.29','target'=>'136.58']],$diff,'Economic amount differences must be reported.');

$legacyDuplicated=[
    'refund_amount'=>'136.58',
    'expected_reimbursement_amount'=>'68.29',
];
$corrected=[
    'refund_amount'=>'68.29',
    'expected_reimbursement_amount'=>'68.29',
];
shSame([],SvAmazonReturnsShadowAudit::migrationCaseDiff($legacyDuplicated,$corrected),'Migration shadow may normalize only the proven duplicate lifecycle refund.');
shSame(true,SvAmazonReturnsShadowAudit::hasLegacyLifecycleRefundNormalization($legacyDuplicated,$corrected),'Known duplicate lifecycle normalization must remain visible in the report.');
$otherDifference=$corrected;
$otherDifference['refund_amount']='60.00';
shSame(['refund_amount'=>['source'=>'136.58','target'=>'60.00']],SvAmazonReturnsShadowAudit::migrationCaseDiff($legacyDuplicated,$otherDifference),'Unrelated refund differences must still fail shadow audit.');

$beforeCredit=['expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00'];
$afterCredit=['expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'40.00'];
$creditEvents=[[
    'event_type'=>'SAFE_T_REIMBURSEMENT_OBSERVED',
    'payload'=>['reimbursed_amount'=>['amount'=>'40.00','currency'=>'BRL']],
]];
shSame([],SvAmazonReturnsShadowAudit::migrationCaseDiff($beforeCredit,$afterCredit,$creditEvents),'Authoritative post-migration credit advancement must not be a projection mismatch.');
shSame(true,SvAmazonReturnsShadowAudit::hasAuthoritativeCreditAdvancement($beforeCredit,$afterCredit,$creditEvents),'Accepted credit advancement must remain visible in the report.');
shSame(['reconciled_credit_amount'=>['source'=>'0.00','target'=>'40.00']],SvAmazonReturnsShadowAudit::migrationCaseDiff($beforeCredit,$afterCredit,[]),'Credit without a financial event must still fail shadow audit.');

$unknownInitiator=['refund_initiator'=>'UNKNOWN'];
$verifiedInitiator=['refund_initiator'=>'AMAZON_AUTOMATIC'];
$reportEvents=[[
    'event_type'=>'RETURN_REPORT_OBSERVED','source'=>'SP_API_REPORTS',
    'payload'=>['refund_initiator'=>'AMAZON_AUTOMATIC'],
]];
shSame([],SvAmazonReturnsShadowAudit::migrationCaseDiff($unknownInitiator,$verifiedInitiator,$reportEvents),'Verified report evidence may correct a legacy UNKNOWN projection.');
shSame(true,SvAmazonReturnsShadowAudit::hasVerifiedInitiatorProjectionCorrection($unknownInitiator,$verifiedInitiator,$reportEvents),'Verified initiator correction must remain visible in the report.');
shSame(['refund_initiator'=>['source'=>'UNKNOWN','target'=>'AMAZON_AUTOMATIC']],SvAmazonReturnsShadowAudit::migrationCaseDiff($unknownInitiator,$verifiedInitiator,[]),'Initiator change without report evidence must still fail shadow audit.');

shSame([],SvAmazonReturnsShadowAudit::decisionDiff(['action'=>'WAIT','reason'=>'X'],['action'=>'WAIT','reason'=>'X']),'Equal decisions must match.');
$dd=SvAmazonReturnsShadowAudit::decisionDiff(['action'=>'SAFE_T_APPEAL','reason'=>'DENIED'],['action'=>'WAIT','reason'=>'SAFE_T_ALREADY_EXISTS']);
shSame('SAFE_T_APPEAL',$dd['action']['source'] ?? null,'Decision action mismatch must be explicit.');

$script=__DIR__.'/../scripts/shadow-audit.php';
shSame(true,is_file($script),'Shadow audit CLI must exist.');
$scriptText=(string)file_get_contents($script);
shSame(true,str_contains($scriptText,'shopvivaliz'),'Shadow audit must compare the source database.');
shSame(true,str_contains($scriptText,'amazon_returns_safet'),'Shadow audit must compare the isolated database.');
shSame(true,str_contains($scriptText,'SvAmazonSafeTDecisionEngine'),'Shadow audit must compare real decision outputs.');
shSame(true,str_contains($scriptText,'ShadowAuditRepository'),'Shadow audit must isolate SQL in a scoped repository.');
shSame(true,str_contains($scriptText,'normalized_legacy_refund_amount_count'),'Shadow audit must report every accepted legacy refund normalization.');
shSame(true,str_contains($scriptText,'normalized_authoritative_credit_count'),'Shadow audit must report every evidence-backed credit advancement.');
shSame(true,str_contains($scriptText,'normalized_verified_initiator_count'),'Shadow audit must report every evidence-backed initiator correction.');
shSame(true,str_contains($scriptText,'AMAZON_RETURNS_SOURCE_TENANT_SLUG'),'Shadow audit must accept explicit source tenant identity.');
shSame(false,str_contains($scriptText,'$sourceTenantSlugEnv='),'Shadow audit must not retain dead source tenant env-name variables.');
shSame(true,str_contains($scriptText,'AMAZON_RETURNS_TARGET_TENANT_SLUG'),'Shadow audit must accept explicit target tenant identity.');
shSame(false,str_contains($scriptText,'EventStore.php'),'Shadow audit must not use the global event store.');

echo "shadow-audit-test: OK\n";
