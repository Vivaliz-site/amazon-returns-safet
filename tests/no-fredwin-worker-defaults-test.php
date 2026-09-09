<?php
declare(strict_types=1);

function noFredAssert(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$root=dirname(__DIR__);
$write=(string)file_get_contents($root.'/scripts/amazon-returns/seller-central-bridge-worker.mjs');
$read=(string)file_get_contents($root.'/scripts/amazon-returns/seller-central-safe-t-read-worker.mjs');

noFredAssert(!str_contains($write,"'fred-win-seller-central'"),'Write worker must not default to retired Fred-Win.');
noFredAssert(!str_contains($read,"'fred-win-safe-t-status'"),'Read worker must not default to retired Fred-Win.');
noFredAssert(str_contains($write,"'vm-a1-seller-central'"),'Write worker must fail over to the primary VM identity when no explicit ID is set.');
noFredAssert(str_contains($read,"'vm-a1-safe-t-status'"),'Read worker must fail over to the primary VM identity when no explicit ID is set.');

echo "no-fredwin-worker-defaults-test: OK\n";
