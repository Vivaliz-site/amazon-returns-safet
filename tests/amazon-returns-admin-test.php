<?php

declare(strict_types=1);

function adAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function source(string $relative): string {
    $path = __DIR__ . '/../' . $relative;
    if (!is_file($path)) throw new RuntimeException('Missing admin file: ' . $relative);
    return (string)file_get_contents($path);
}

$files = [
    'admin/amazon-returns/index.php',
    'admin/amazon-returns/intake.php',
    'admin/amazon-returns/api/summary.php',
    'admin/amazon-returns/api/intake.php',
    'admin/amazon-returns/api/case.php',
];
foreach ($files as $file) {
    $src = source($file);
    adAssert(str_contains($src, 'admin-guard.php'), $file . ' must require admin guard.');
}

$intakeApi = source('admin/amazon-returns/api/intake.php');
adAssert(str_contains($intakeApi, 'sv_csrf_valid'), 'Physical intake write must validate CSRF.');
adAssert(str_contains($intakeApi, 'SvAmazonReturnEventStore::append'), 'Physical intake must append immutable event.');
adAssert(str_contains($intakeApi, 'SvAmazonReturnProjector::project'), 'Physical intake must reproject after event append.');
adAssert(str_contains($intakeApi, 'operation_id'), 'Intake must use client operation id for retry idempotency.');
adAssert(str_contains($intakeApi, 'WAREHOUSE_PHOTO'), 'Discrepancy evidence photos must be stored as protected evidence metadata.');
adAssert(str_contains($intakeApi, 'EMPTY_PACKAGE') && str_contains($intakeApi, 'WRONG_ITEM') && str_contains($intakeApi, 'DAMAGED'), 'Intake must support discrepancy conditions.');
adAssert(!str_contains($intakeApi, 'CARRIER_DELIVERED'), 'Intake API must not infer physical receipt from carrier state.');

$summary = source('admin/amazon-returns/api/summary.php');
foreach (['unclassified','eligible_without_action','expired_without_treatment','credit_without_reconciliation'] as $gate) {
    adAssert(str_contains($summary, $gate), 'Summary must expose health gate ' . $gate);
}
adAssert(str_contains($summary, "WHEN program='UNKNOWN' THEN 1"), 'Unknown program must remain a critical classification gate.');
adAssert(str_contains($summary, "program IN ('STANDARD','FBA_ONSITE','DELIVERY_BY_AMAZON')"), 'Only SAFE-T-managed programs require initiator and seller debit for the classification gate.');
adAssert(!str_contains($summary, "refund_initiator='UNKNOWN' OR program='UNKNOWN' OR seller_debit_at IS NULL"), 'Known FBA cases must not be treated as unexplained SAFE-T cases.');

foreach (['at_risk','eligible_now','safe_t_submitted','denied','appeal','support','approved_awaiting_credit','recovered','loss'] as $bucket) {
    adAssert(str_contains($summary, $bucket), 'Summary must expose money/queue bucket ' . $bucket);
}

$intakePage = source('admin/amazon-returns/intake.php');
adAssert(str_contains($intakePage, 'Registrar devolução recebida'), 'Intake page needs explicit operator task title.');
adAssert(str_contains($intakePage, 'Quantidade do item correto recebida'), 'Intake must distinguish correct item quantity from package arrival.');
adAssert(str_contains($intakePage, 'crypto.randomUUID'), 'Browser must persist an operation id for safe retries.');
adAssert(str_contains($intakePage, 'type="file"'), 'Discrepancy intake must support photo evidence.');

$dashboard = source('admin/amazon-returns/index.php');
adAssert(str_contains($dashboard, '/admin/amazon-returns/intake.php'), 'Dashboard must link directly to physical intake.');
adAssert(str_contains($dashboard, 'Casos elegíveis sem ação'), 'Dashboard must make zero-action health gate visible.');

echo "amazon-returns-admin-test: OK\n";
