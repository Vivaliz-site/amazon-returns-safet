<?php
declare(strict_types=1);
// Synthetic authorization-boundary regression; NO provider request, outbox,
// production config mutation, credentials, claims or switch activation.
require_once __DIR__.'/../includes/amazon-returns/Config.php';
function scoped3673Assert(bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('PR3673_SCOPE_FAIL: '.$why);
}

$path=__DIR__.'/../deploy/write-profile-safe-t-pr3673.json';
scoped3673Assert(is_file($path),'supplemental staged profile absent');
$expected=[
    'version'=>'safet-d45-pr3673-scoped-v1',
    'SAFE_T_SUBMIT'=>true,
    'SAFE_T_APPEAL'=>false,
    'SAFE_T_EMAIL_REVIEW'=>false,
    'SAFE_T_EMAIL_REPLY'=>false,
    'SELLER_SUPPORT_OPEN'=>false,
    'SELLER_SUPPORT_UPDATE'=>false,
    'ERP_SALES_RETURN_CREATE'=>false,
];
$contents=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
scoped3673Assert($contents===$expected,'must have exactly one enabled action');
$cases=[42471,51429,101669,101670,101671];
$flags=[
    'AMAZON_RETURNS_ENABLED'=>'1',
    'AMAZON_RETURNS_MODE'=>'production',
    'AMAZON_RETURNS_WRITE_PROFILE_FILE'=>$path,
    'AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'=>implode(',',$cases),
    'AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED'=>'0',
];
$off=new SvAmazonReturnsConfig($flags+['AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'1']);
foreach (array_keys($expected) as $kind) {
    if ($kind==='version') continue;
    scoped3673Assert(!$off->externalWriteAllowed($kind),'kill switch unexpectedly allows '.$kind);
}
$synthetic=new SvAmazonReturnsConfig($flags+['AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'0']);
scoped3673Assert($synthetic->writeProfileVersion()===$expected['version'],'supplemental profile not recognized');
scoped3673Assert($synthetic->scopedWriteCaseIds()===$cases,'required case set not enforced');
foreach ($cases as $caseId) scoped3673Assert($synthetic->writeCaseAllowed($caseId),'approved case denied');
foreach ([0,1,1263,1314,8803,13210,999999] as $caseId) {
    scoped3673Assert(!$synthetic->writeCaseAllowed($caseId),'cross-grant/unlisted case accepted');
}
$version=$expected['version'];
scoped3673Assert(SvAmazonReturnsConfig::scopedSafeTGrantActiveAt($version,new DateTimeImmutable('2026-11-30T23:58:59Z')),'valid before expiry denied');
scoped3673Assert(!SvAmazonReturnsConfig::scopedSafeTGrantActiveAt($version,new DateTimeImmutable('2026-11-30T23:59:00Z')),'exact deadline accepted');
scoped3673Assert(!SvAmazonReturnsConfig::scopedSafeTGrantActiveAt($version,new DateTimeImmutable('2026-12-01T00:00:00Z')),'expired grant accepted');
scoped3673Assert(!SvAmazonReturnsConfig::scopedSafeTGrantActiveAt('safet-d45-pr3673-scoped-v2',new DateTimeImmutable('2026-11-01T00:00:00Z')),'unknown grant accepted');
$activeNow=SvAmazonReturnsConfig::scopedSafeTGrantActiveAt($version,new DateTimeImmutable('now',new DateTimeZone('UTC')));
foreach ($expected as $kind=>$enabled) {
    if ($kind==='version') continue;
    scoped3673Assert($synthetic->externalWriteAllowed($kind)===($enabled && $activeNow),'action contract changed '.$kind);
}
// No single ID is enough to represent a scope. Reject any partial,
// superset, duplicate, cross-grant, invalid or blank allowlist.
$badCanaries=[
    '',
    '42471',
    '42471,51429,101669,101670',
    implode(',',$cases).',1263',
    implode(',',$cases).',42471',
    '042471,51429,101669,101670,101671',
    '42471,51429,101669,101670,bad',
    '1263,1314,8803,13210,51429,101669,101670,101671',
];
foreach ($badCanaries as $raw) {
    $candidate=new SvAmazonReturnsConfig(array_merge($flags,[
        'AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'=>$raw,
        'AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'0',
    ]));
    scoped3673Assert(!$candidate->writeCaseAllowed(42471),'bad canary allowed case');
    scoped3673Assert($candidate->scopedWriteCaseIds()===[],'bad canary should block outbox');
    scoped3673Assert(!$candidate->externalWriteAllowed('SAFE_T_SUBMIT'),'bad canary enabled submit');
}
$permuted=new SvAmazonReturnsConfig(array_merge($flags,[
    'AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'=>implode(',',array_reverse($cases)),
    'AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'0',
]));
scoped3673Assert($permuted->scopedWriteCaseIds()===$cases,'order-independent exact canary denied');
// Forging any write action or borrowing an unknown scoped name must
// fail closed rather than revert to legacy unrestricted case selection.
foreach ([
    ['version'=>'safet-d45-pr3673-scoped-v2'],
    ['SAFE_T_SUBMIT'=>false],
    ['SAFE_T_APPEAL'=>true],
    ['SAFE_T_EMAIL_REVIEW'=>true],
    ['SAFE_T_EMAIL_REPLY'=>true],
    ['SELLER_SUPPORT_OPEN'=>true],
    ['SELLER_SUPPORT_UPDATE'=>true],
    ['ERP_SALES_RETURN_CREATE'=>true],
] as $mutation) {
    $forged=array_replace($expected,$mutation);
    $temp=tempnam(sys_get_temp_dir(),'safet3673-');
    scoped3673Assert(is_string($temp),'fixture allocation failed');
    try {
        file_put_contents($temp,json_encode($forged,JSON_THROW_ON_ERROR));
        $candidate=new SvAmazonReturnsConfig(array_merge($flags,[
            'AMAZON_RETURNS_WRITE_PROFILE_FILE'=>$temp,
            'AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'0',
            'AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED'=>'1',
        ]));
        scoped3673Assert($candidate->writeProfileVersion()===null,'forged version/action accepted');
        scoped3673Assert(!$candidate->externalWriteAllowed('SAFE_T_SUBMIT'),'forged profile opened external write');
    } finally { unlink($temp); }
}
echo "scoped-safe-t-pr3673-profile-test: OK (synthetic only; no runtime authorization)\n";
