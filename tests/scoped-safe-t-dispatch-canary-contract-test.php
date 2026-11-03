<?php
declare(strict_types=1);
// Offline regression: verifies that a staged scoped profile cannot deliver an
// out-of-canary SAFE-T job through either the polling or direct daemon path.
require_once __DIR__.'/../includes/amazon-returns/Config.php';

function requireScope(bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException('SAFE_T_DISPATCH_SCOPE_FAIL: '.$reason);
}
$ids=[1263,1314,8803,13210,51429,101669,101670,101671];
$env=[
    'AMAZON_RETURNS_ENABLED'=>'1',
    'AMAZON_RETURNS_MODE'=>'production',
    'AMAZON_RETURNS_WRITE_PROFILE_FILE'=>__DIR__.'/../deploy/write-profile-safe-t-pr3556.json',
    'AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'=>implode(',',$ids),
    'AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'0',
];
$config=new SvAmazonReturnsConfig($env);
requireScope($config->scopedWriteCaseIds()===$ids,'valid exact eight-case scope');
requireScope(!$config->writeCaseAllowed(999999),'out-of-scope case accepted');
foreach (['', '1263', implode(',',$ids).',999999', implode(',',$ids).',1263'] as $bad) {
    $invalid=new SvAmazonReturnsConfig(array_merge($env,['AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'=>$bad]));
    requireScope($invalid->scopedWriteCaseIds()===[],'invalid canary must block claim');
}
$legacy=new SvAmazonReturnsConfig(array_merge($env,['AMAZON_RETURNS_WRITE_PROFILE_FILE'=>__DIR__.'/../deploy/write-profile.json']));
requireScope($legacy->scopedWriteCaseIds()===null,'legacy profile must retain legacy routing');

$base=__DIR__.'/../';
$outbox=(string)file_get_contents($base.'includes/amazon-returns/TenantOutbox.php');
$bridge=(string)file_get_contents($base.'includes/amazon-returns/BridgeService.php');
$daemon=(string)file_get_contents($base.'workers/amazon-returns/daemon.php');
$review=(string)file_get_contents($base.'includes/amazon-returns/ReviewService.php');
requireScope(str_contains($outbox, '?array $allowedCaseIds=null') && str_contains($outbox,'AND case_id IN ('),
    'outbox claim must filter scoped case IDs before taking a lease');
requireScope(str_contains($bridge, 'claimBatch(1,$enabled,$this->config->scopedWriteCaseIds())'),
    'polling bridge missing claim-time case filter');
requireScope(str_contains($daemon, '$this->config->scopedWriteCaseIds()'),
    'direct daemon missing claim-time case filter');
requireScope(str_contains($review, '$this->config->writeCaseAllowed($caseId)'),
    'learned review must not enqueue unapproved cases');
echo "scoped-safe-t-dispatch-canary-contract-test: OK (offline only; no external eligibility)\n";
