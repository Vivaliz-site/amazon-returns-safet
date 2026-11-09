<?php
declare(strict_types=1);
// Contrato sintetico: nunca carrega segredos, outbox, banco, browser ou worker reais.
require_once __DIR__.'/../includes/amazon-returns/Config.php';
function renewalAssert(bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('PR3846_RENEWAL_CONTRACT_FAIL: '.$why);
}
$path=__DIR__.'/../deploy/write-profile-safe-t-pr3846.json';
renewalAssert(is_file($path), 'renewed one-action profile missing');
$expected=[
    'version'=>'safet-d45-pr3846-renewal-of-3556-v1',
    'SAFE_T_SUBMIT'=>true,
    'SAFE_T_APPEAL'=>false,
    'SAFE_T_EMAIL_REVIEW'=>false,
    'SAFE_T_EMAIL_REPLY'=>false,
    'SELLER_SUPPORT_OPEN'=>false,
    'SELLER_SUPPORT_UPDATE'=>false,
    'ERP_SALES_RETURN_CREATE'=>false,
];
renewalAssert(json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR)===$expected,
    'renewal must preserve exactly one write action');
$cases=[1263,1314,8803,13210,51429,101669,101670,101671];
$flags=[
    'AMAZON_RETURNS_ENABLED'=>'1',
    'AMAZON_RETURNS_MODE'=>'production',
    'AMAZON_RETURNS_WRITE_PROFILE_FILE'=>$path,
    'AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'=>implode(',',$cases),
    'AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED'=>'0',
];
$blocked=new SvAmazonReturnsConfig($flags+['AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'1']);
renewalAssert($blocked->writeFlags()===array_fill_keys(array_slice(array_keys($expected),1),false),
    'an installed file must not enable a live write');
$synthetic=new SvAmazonReturnsConfig($flags+['AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'0']);
renewalAssert($synthetic->writeProfileVersion()===$expected['version'], 'renewal version rejected');
renewalAssert($synthetic->scopedWriteCaseIds()===$cases,'renewed canary diverged');
foreach ($cases as $id) renewalAssert($synthetic->writeCaseAllowed($id),'approved case denied');
foreach ([42471,1264,999999,0] as $id) renewalAssert(!$synthetic->writeCaseAllowed($id),'unauthorized case accepted');
renewalAssert($synthetic->writeFlags()===array_replace(array_fill_keys(array_slice(array_keys($expected),1),false),['SAFE_T_SUBMIT'=>true]),
    'renewed profile must enable only SAFE_T_SUBMIT in a synthetic kill-off config');
foreach (['', '1263', implode(',',$cases).',42471',implode(',',$cases).',1263'] as $canary) {
    $invalid=new SvAmazonReturnsConfig(array_replace($flags,[
        'AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'=>$canary,
        'AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'0',
    ]));
    renewalAssert(!$invalid->externalWriteAllowed('SAFE_T_SUBMIT'),'invalid renewal canary allowed a write');
}
foreach (['SAFE_T_APPEAL','SELLER_SUPPORT_OPEN','ERP_SALES_RETURN_CREATE'] as $action) {
    $forged=$expected;
    $forged[$action]=true;
    $temp=tempnam(sys_get_temp_dir(),'safet3846-');
    renewalAssert(is_string($temp),'temporary synthetic fixture failed');
    try {
        file_put_contents($temp,json_encode($forged,JSON_THROW_ON_ERROR));
        $invalid=new SvAmazonReturnsConfig(array_replace($flags,[
            'AMAZON_RETURNS_WRITE_PROFILE_FILE'=>$temp,
            'AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'0',
        ]));
        renewalAssert(!$invalid->externalWriteAllowed('SAFE_T_SUBMIT'),'forged action allowed a write');
    } finally { unlink($temp); }
}
renewalAssert(SvAmazonReturnsConfig::scopedSafeTGrantActiveAt($expected['version'],new DateTimeImmutable('2026-11-10T00:00:00Z')),
    'approved renewal cannot be treated as expired original');
renewalAssert(!SvAmazonReturnsConfig::scopedSafeTGrantActiveAt('safet-d45-pr3846-fake-v1',new DateTimeImmutable('2026-11-10T00:00:00Z')),
    'unapproved version accepted');
renewalAssert(!SvAmazonReturnsConfig::scopedD45GrantActiveAt(new DateTimeImmutable('2026-11-10T00:00:00Z')),
    'original #3556 expiry was extended');
echo "scoped-safe-t-pr3846-profile-test: OK (synthetic only; no activation)\n";
