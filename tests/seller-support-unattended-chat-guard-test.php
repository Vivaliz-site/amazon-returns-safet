<?php
declare(strict_types=1);

function ssucAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$worker = (string) file_get_contents(__DIR__ . '/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$start = strpos($worker, 'async function contactSupportAndReadBack');
$end = $start === false ? false : strpos($worker, 'async function fillGeneralSupportIssue', $start);
ssucAssert($start !== false && $end !== false, 'Seller Support contact flow must remain auditable.');

$contact = substr($worker, (int) $start, (int) $end - (int) $start);
ssucAssert(
    !str_contains($contact, 'clickHillChat(cdp)'),
    'Unattended Seller Support automation must never start a live chat that it cannot continuously attend.'
);
ssucAssert(
    str_contains($contact, 'SELLER_SUPPORT_LIVE_CHAT_REQUIRES_ATTENDED_SESSION'),
    'When email is unavailable and only live chat remains, the worker must fail closed with an explicit retryable reason.'
);

echo "seller-support-unattended-chat-guard-test: OK\n";
