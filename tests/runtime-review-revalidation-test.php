<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/Runtime.php';

$now = new DateTimeImmutable('2026-09-09T10:15:00Z');
$state = [];
foreach (SvAmazonReturnsRuntime::cadences() as $task => $_seconds) {
    $state[$task] = $now->format(DATE_ATOM);
}
$state['decision_stack_revision'] = 'old-revision';

$revision = str_repeat('a', 64);
$due = SvAmazonReturnsRuntime::dueTasks($state, $now, $revision);
if (!in_array('scheduler', $due, true)) {
    throw new RuntimeException('A changed decision-stack revision must force scheduler revalidation immediately.');
}

$state['decision_stack_revision'] = $revision;
$due = SvAmazonReturnsRuntime::dueTasks($state, $now, $revision);
if (in_array('scheduler', $due, true)) {
    throw new RuntimeException('An unchanged decision-stack revision must not force an extra scheduler run.');
}

$due = SvAmazonReturnsRuntime::decisionSafeOrder(['bootstrap', 'scheduler', 'review_operations', 'seller_central', 'sp_api', 'financial']);
$positions = array_flip($due);
foreach (['seller_central', 'sp_api', 'financial', 'scheduler', 'review_operations'] as $required) {
    if (!array_key_exists($required, $positions)) {
        throw new RuntimeException('Decision-safe task planning dropped required task: ' . $required);
    }
}
if (!($positions['seller_central'] < $positions['scheduler']
    && $positions['sp_api'] < $positions['scheduler']
    && $positions['financial'] < $positions['scheduler']
    && $positions['scheduler'] < $positions['review_operations'])) {
    throw new RuntimeException('Evidence refresh must run before scheduler, and scheduler before review reminders: ' . json_encode($due));
}

$due = SvAmazonReturnsRuntime::decisionSafeOrder(['bootstrap', 'gmail']);
if (!in_array('scheduler', $due, true)) {
    throw new RuntimeException('Any evidence refresh must schedule immediate decision revalidation in the same cycle.');
}
if (array_search('gmail', $due, true) > array_search('scheduler', $due, true)) {
    throw new RuntimeException('Gmail evidence must be ingested before scheduler revalidation.');
}

if (!method_exists(SvAmazonReturnsRuntime::class, 'decisionStackRevision')) {
    throw new RuntimeException('Runtime must expose an automatic decision-stack revision fingerprint.');
}
$liveRevision = SvAmazonReturnsRuntime::decisionStackRevision();
if (preg_match('/^[a-f0-9]{64}$/', $liveRevision) !== 1) {
    throw new RuntimeException('Decision-stack revision must be a SHA-256 fingerprint; got ' . $liveRevision);
}

echo "runtime-review-revalidation-test: OK\n";
