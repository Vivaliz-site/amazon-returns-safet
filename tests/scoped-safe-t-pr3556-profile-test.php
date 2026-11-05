<?php
declare(strict_types=1);
// Synthetic contract only. This file must never read production secrets,
// clear the production kill switch, enqueue a job, or submit a claim.
require_once __DIR__.'/../includes/amazon-returns/Config.php';

function scopedAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('SCOPED_SAFE_T_PROFILE_FAIL: '.$message);
}
$path=__DIR__.'/../deploy/write-profile-safe-t-pr3556.json';
scopedAssert(is_file($path), 'staged profile missing');
$data=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
$expected=[
    'version'=>'safet-d45-pr3556-scoped-v1',
    'SAFE_T_SUBMIT'=>true,
    'SAFE_T_APPEAL'=>false,
    'SAFE_T_EMAIL_REVIEW'=>false,
    'SAFE_T_EMAIL_REPLY'=>false,
    'SELLER_SUPPORT_OPEN'=>false,
    'SELLER_SUPPORT_UPDATE'=>false,
    'ERP_SALES_RETURN_CREATE'=>false,
];
scopedAssert($data===$expected,'profile must have exactly one enabled action and no extra fields');
$allowedIds=[1263,1314,8803,13210,51429,101669,101670,101671];
$flags=[
    'AMAZON_RETURNS_ENABLED'=>'1',
    'AMAZON_RETURNS_MODE'=>'production',
    'AMAZON_RETURNS_WRITE_PROFILE_FILE'=>$path,
    'AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'=>implode(',',$allowedIds),
    'AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED'=>'0',
];
// A profile being present on disk cannot authorize a write.
$off=new SvAmazonReturnsConfig($flags+['AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'1']);
foreach(array_keys($expected) as $action) {
    if($action==='version') continue;
    scopedAssert($off->externalWriteAllowed($action)===false,'kill switch must deny '.$action);
}
$synthetic=new SvAmazonReturnsConfig($flags+['AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'0']);
scopedAssert($synthetic->writeProfileVersion()==='safet-d45-pr3556-scoped-v1','wrong profile loaded');
// The grant is expired at its exact UTC deadline, regardless of a
// previously loaded one-action profile or a failed rollback.
scopedAssert(SvAmazonReturnsConfig::scopedD45GrantActiveAt(new DateTimeImmutable('2026-11-09T23:58:59Z')),'grant rejected before deadline');
scopedAssert(!SvAmazonReturnsConfig::scopedD45GrantActiveAt(new DateTimeImmutable('2026-11-09T23:59:00Z')),'grant allowed at deadline');
scopedAssert(!SvAmazonReturnsConfig::scopedD45GrantActiveAt(new DateTimeImmutable('2026-11-10T00:00:00Z')),'grant allowed after deadline');
$activeNow=SvAmazonReturnsConfig::scopedD45GrantActiveAt(new DateTimeImmutable('now',new DateTimeZone('UTC')));
foreach($expected as $action=>$enabled) {
    if($action==='version') continue;
    scopedAssert($synthetic->externalWriteAllowed($action)===($enabled && $activeNow),'synthetic action mismatch '.$action);
}
foreach($allowedIds as $caseId) scopedAssert($synthetic->writeCaseAllowed($caseId),'allowlisted ID missing');
foreach([0,1,1264,8804,999999] as $caseId) scopedAssert(!$synthetic->writeCaseAllowed($caseId),'out-of-scope case accepted');
$invalid=new SvAmazonReturnsConfig(array_merge($flags,[
    'AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'0',
    'AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'=>'not-a-case',
]));
scopedAssert(!$invalid->writeCaseAllowed(1263),'invalid canary must fail closed');
// RED regression: previously a missing canary allowed every case, even with
// the scoped one-action profile selected in an isolated synthetic config.
$deniedCanaries=[
    'empty'=>'',
    'subset'=>implode(',',array_slice($allowedIds,0,7)),
    'superset'=>implode(',',array_merge($allowedIds,[999999])),
    'duplicate'=>implode(',',array_merge($allowedIds,[1263])),
    'leading_zero'=>'01263,'.implode(',',array_slice($allowedIds,1)),
];
foreach ($deniedCanaries as $scenario=>$canary) {
    $candidate=new SvAmazonReturnsConfig(array_merge($flags,[
        'AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'0',
        'AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'=>$canary,
    ]));
    scopedAssert(!$candidate->writeCaseAllowed(1263),'scoped profile must deny '.$scenario);
    scopedAssert(!$candidate->externalWriteAllowed('SAFE_T_SUBMIT'),'effective submit flag must deny '.$scenario);
}
$permuted=array_reverse($allowedIds);
$permutedConfig=new SvAmazonReturnsConfig(array_merge($flags,[
    'AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'0',
    'AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'=>implode(',',$permuted),
]));
foreach ($allowedIds as $caseId) {
    scopedAssert($permutedConfig->writeCaseAllowed($caseId),'valid unordered canary denied');
}
// RED in the previous release: a forged profile with the *same* scoped
// version but SELLER_SUPPORT_OPEN=true unexpectedly enabled support writes.
// All seven actions are an atomic owner-grant contract, not mere metadata.
foreach ([
    'SAFE_T_SUBMIT'=>false,
    'SAFE_T_APPEAL'=>true,
    'SAFE_T_EMAIL_REVIEW'=>true,
    'SAFE_T_EMAIL_REPLY'=>true,
    'SELLER_SUPPORT_OPEN'=>true,
    'SELLER_SUPPORT_UPDATE'=>true,
    'ERP_SALES_RETURN_CREATE'=>true,
] as $changedAction=>$forgedValue) {
    $forged=$expected;
    $forged[$changedAction]=$forgedValue;
    $fixture=tempnam(sys_get_temp_dir(),'safet-scope-');
    scopedAssert(is_string($fixture),'synthetic profile fixture unavailable');
    try {
        file_put_contents($fixture,json_encode($forged,JSON_THROW_ON_ERROR));
        $candidate=new SvAmazonReturnsConfig(array_merge($flags,[
            'AMAZON_RETURNS_WRITE_PROFILE_FILE'=>$fixture,
            'AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'0',
            'AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED'=>'1',
        ]));
        scopedAssert($candidate->writeProfileVersion()===null,'forged action must invalidate profile '.$changedAction);
        foreach(array_keys($expected) as $action) {
            if($action==='version')continue;
            scopedAssert(!$candidate->externalWriteAllowed($action),'forged profile enabled '.$action);
        }
    } finally {
        unlink($fixture);
    }
}
$legacyProfile=new SvAmazonReturnsConfig(array_merge($flags,[
    'AMAZON_RETURNS_WRITE_PROFILE_FILE'=>__DIR__.'/../deploy/write-profile.json',
    'AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'=>'',
]));
scopedAssert($legacyProfile->writeCaseAllowed(1263),'legacy default semantics changed');

require __DIR__."/scoped-safe-t-dispatch-canary-contract-test.php";
require __DIR__."/scoped-safe-t-pr3673-profile-test.php";
echo "scoped-safe-t-pr3556-profile-test: OK (synthetic only; no runtime authorization)\n";
