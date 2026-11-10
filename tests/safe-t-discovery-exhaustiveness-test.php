<?php
declare(strict_types=1);

function dxAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$source = (string)file_get_contents(__DIR__.'/../includes/amazon-returns/StatusBridgeService.php');
$needle = <<<'BLOCK'
if($kind==='SAFE_T_DISCOVERY' && $status==='NOT_FOUND')
BLOCK;
$begin = strpos($source, $needle);
dxAssert($begin !== false, 'SAFE_T_DISCOVERY NOT_FOUND handler must remain explicit');
$end = strpos($source, <<<'BLOCK'
if(in_array($status,['AUTH_REQUIRED'
BLOCK
, $begin);
dxAssert($end !== false, 'Discovery branch must precede general read failures');
$block = substr($source, $begin, $end-$begin);
dxAssert(!str_contains($block, 'markSucceeded('), 'Unverified absence must never mark discovery SUCCEEDED');
dxAssert(str_contains($block, 'SAFE_T_DISCOVERY_NOT_EXHAUSTIVE'), 'Unverified first-page absence must remain explicitly unresolved');
dxAssert(str_contains($block, '->defer('), 'Unverified absence must defer with scoped lease');
$js = (string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-safe-t-read-worker.mjs');
$begin = strpos($js, 'async function safeTDiscovery(job)');
$end = strpos($js, 'async function safeTRead(job)', $begin);
dxAssert($begin !== false && $end !== false, 'Discovery function present');
$function = substr($js, $begin, $end-$begin);
// The only permitted NOT_FOUND is a fully verified read-only 90-day ALL
// view, explicitly guarded again in the bridge; never infer global absence
// from one incomplete/filtered page or make this a submission permission.
dxAssert(substr_count($function, "result('NOT_FOUND'")===1
    && str_contains($function, "if (discovery.status === 'VERIFIED_LIST_NO_MATCH')")
    && str_contains($function, "'OFFICIAL_90_DAY_COMPLETE_LIST_NO_MATCH'")
    && strpos($function,"if (discovery.status === 'VERIFIED_LIST_NO_MATCH')") <
       strpos($function, "result('NOT_FOUND'"),
    'Only a verified complete 90-day ALL-view may emit scoped NOT_FOUND.');
dxAssert(str_contains($source,'verifiedCompleteClaimListEvidence')
    && str_contains($source,'SAFE_T_DISCOVERY_NOT_EXHAUSTIVE'),
    'Backend must independently validate full-list evidence and defer unverified absence.');
dxAssert(str_contains($function, 'scanSafeTClaimPages'), 'Discovery must use bounded read-only page scanner');
$helper = (string)file_get_contents(__DIR__.'/../scripts/amazon-returns/safe-t-discovery-pages.mjs');
dxAssert(str_contains($helper, 'SAFE_T_DISCOVERY_LIST_COVERAGE_UNVERIFIED'), 'Incomplete claim-list coverage must be explicit');
dxAssert(str_contains($helper, 'SAFE_T_DISCOVERY_PAGE_LIMIT_REACHED'), 'Bounded pagination must fail closed at limit');
dxAssert(!str_contains($helper, "status: 'NOT_FOUND'"), 'Unverified pagination must not report claim absence');
echo "safe-t-discovery-exhaustiveness-test: OK\n";
