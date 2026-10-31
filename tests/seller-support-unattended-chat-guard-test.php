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
    str_contains($contact, 'attendHillChat(cdp, job, popupCaseIdsBeforeWrite)'),
    'Live-only Seller Support must hand off to the continuously attended chat flow.'
);
ssucAssert(
    !str_contains($contact, 'SELLER_SUPPORT_LIVE_CHAT_REQUIRES_ATTENDED_SESSION') && str_contains($worker, 'HUMAN_INTERVENTION_REQUIRED') && str_contains($worker, 'SUPPORT_CHAT_ATTENDED_AND_READ_BACK_CONFIRMED'),
    'Live-only support must be attended continuously, fail closed on unsupported questions, and require read-back before success.'
);

echo "seller-support-unattended-chat-guard-test: OK\n";
