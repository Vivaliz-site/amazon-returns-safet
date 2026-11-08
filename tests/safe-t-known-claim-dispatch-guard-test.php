<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTExternalWindow.php';

function linkedClaimAssert(bool $ok,string $msg): void {
    if(!$ok) throw new RuntimeException('SAFE_T_EXISTING_CLAIM_GUARD: '.$msg);
}

// The already-linked #8803 must never be treated as a fresh opening.
linkedClaimAssert(SvAmazonSafeTExternalWindow::openingAlreadyClaimed([
    'safe_t_id'=>'64204-51742-6364222'
]), 'linked claim must block a second opening');
linkedClaimAssert(SvAmazonSafeTExternalWindow::openingAlreadyClaimed([
    'safe_t_id'=>' 64204-51742-6364222 '
]), 'whitespace must not hide a linked claim');
foreach ([[],['safe_t_id'=>null],['safe_t_id'=>''],['safe_t_id'=>'   ']] as $case) {
    linkedClaimAssert(!SvAmazonSafeTExternalWindow::openingAlreadyClaimed($case),
        'unlinked claim must remain eligible for independent checks');
}

// Both the polling HTTP bridge and the direct daemon must terminally close a
// known duplicate BEFORE checking the opening window or contacting the UI.
// This does not assert external absence: that is an independent provider gate.
foreach ([
    '../includes/amazon-returns/BridgeService.php'=>'polling API',
    '../workers/amazon-returns/daemon.php'=>'direct daemon',
] as $path=>$label) {
    $source=(string)file_get_contents(__DIR__.'/'.$path);
    $guard=strpos($source,'SvAmazonSafeTExternalWindow::openingAlreadyClaimed($case)');
    $window=$guard===false?false:strpos($source,'$gate=SvAmazonSafeTExternalWindow::inspect(', $guard);
    linkedClaimAssert($guard!==false && $window!==false && $guard<$window,
        $label.' missing known-claim gate before SAFE-T window');
    $section=substr($source,$guard,$window-$guard);
    foreach (['->outbox->markSuperseded(', 'SAFE_T_LOCAL_CLAIM_ALREADY_LINKED', 'continue;'] as $required) {
        linkedClaimAssert(str_contains($section,$required), $label.' missing terminal step: '.$required);
    }
    linkedClaimAssert(!str_contains($section,'->outbox->defer('),$label.' must not retry known duplicate');
}
echo "safe-t-known-claim-dispatch-guard-test: OK (offline, no provider write)\n";
