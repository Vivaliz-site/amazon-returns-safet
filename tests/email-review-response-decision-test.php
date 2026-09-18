<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/SafeTDecisionEngine.php';

function errdSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function errdBaseCase(int $id, string $orderId): array
{
    return [
        'id' => $id,
        'amazon_order_id' => $orderId,
        'safe_t_id' => '98143-99485-' . $id,
        'state' => 'EMAIL_REVIEW_RESPONSE_PENDING',
        'program' => 'DELIVERY_BY_AMAZON',
        'refund_at' => '2026-09-01 12:00:00',
        'refund_initiator' => 'AMAZON_CUSTOMER_SERVICE',
        'expected_reimbursement_amount' => '100.00',
        'reconciled_credit_amount' => '0.00',
        'physical_status' => 'NOT_RECEIVED',
    ];
}

function errdResponseEvent(int $caseId, int $eventId, array $payload): array
{
    return [
        'id' => $eventId,
        'case_id' => $caseId,
        'event_type' => 'SAFE_T_EMAIL_REVIEW_RESPONSE',
        'source' => 'GMAIL',
        'occurred_at' => '2026-09-08 22:10:00',
        'payload' => $payload + [
            'review_excerpt' => 'resposta de teste',
            'gmail_thread_id' => 'thread-' . $caseId,
            'gmail_rfc_message_id' => '<message-' . $caseId . '@example.test>',
            'content_sha256' => hash('sha256', 'payload-' . $caseId),
        ],
    ];
}

$engine = new SvAmazonSafeTDecisionEngine(null, new DateTimeImmutable('2026-09-08T22:15:00Z'));
$policy = ['eligible' => true, 'state' => 'SAFE_T_ELIGIBLE'];

// A SAFE-T email review awaiting a response with no response event yet must never
// be silently skipped: it must block for an explicit human check.
$missingCase = errdBaseCase(931, '701-0000001-0000001');
$missingResult = $engine->nextAction($missingCase, [], $policy);
errdSame('BLOCKED_REVIEW', $missingResult['action'] ?? null, 'A missing email-review response must block for human review.');
errdSame('EMAIL_REVIEW_RESPONSE_MISSING', $missingResult['reason'] ?? null, 'Missing email-review response must be named explicitly.');

// A promised future action must wait, never be treated as resolved.
$waitCase = errdBaseCase(932, '701-0000002-0000002');
$waitEvent = errdResponseEvent(932, 502, ['review_outcome' => 'WAIT', 'review_suggested_action' => 'WAIT']);
$waitResult = $engine->nextAction($waitCase, [$waitEvent], $policy);
errdSame('WAIT', $waitResult['action'] ?? null, 'A promised future action from the email review must wait, not act.');
errdSame('EMAIL_REVIEW_PROMISED_FUTURE_ACTION', $waitResult['reason'] ?? null, 'Promised future action must be named explicitly.');

// An approved outcome must wait for a finance check, never assume the credit already landed.
$approvedCase = errdBaseCase(933, '701-0000003-0000003');
$approvedEvent = errdResponseEvent(933, 503, ['review_outcome' => 'APPROVED', 'review_suggested_action' => 'WAIT']);
$approvedResult = $engine->nextAction($approvedCase, [$approvedEvent], $policy);
errdSame('WAIT', $approvedResult['action'] ?? null, 'An approved email review must wait for finance confirmation, not close automatically.');
errdSame('EMAIL_REVIEW_APPROVED_AWAIT_FINANCES', $approvedResult['reason'] ?? null, 'Approved-awaiting-finances must be named explicitly.');

// An ambiguous outcome without delivery-contradiction evidence must block for human review.
$ambiguousCase = errdBaseCase(934, '701-0000004-0000004');
$ambiguousEvent = errdResponseEvent(934, 504, ['review_outcome' => 'UNKNOWN_AMBIGUOUS', 'review_suggested_action' => 'HUMAN_REVIEW']);
$ambiguousResult = $engine->nextAction($ambiguousCase, [$ambiguousEvent], $policy);
errdSame('BLOCKED_REVIEW', $ambiguousResult['action'] ?? null, 'A genuinely ambiguous email-review outcome must block for human review.');
errdSame('EMAIL_REVIEW_AMBIGUOUS', $ambiguousResult['reason'] ?? null, 'Ambiguous outcome must be named explicitly.');

// A final denial without a suggested closure must require terminal human review, never auto-close.
$deniedFinalCase = errdBaseCase(935, '701-0000005-0000005');
$deniedFinalEvent = errdResponseEvent(935, 505, ['review_outcome' => 'DENIED_FINAL', 'review_suggested_action' => 'HUMAN_REVIEW']);
$deniedFinalResult = $engine->nextAction($deniedFinalCase, [$deniedFinalEvent], $policy);
errdSame('BLOCKED_REVIEW', $deniedFinalResult['action'] ?? null, 'A final denial without an explicit closure suggestion must require terminal human review.');
errdSame('EMAIL_REVIEW_FINAL_REQUIRES_TERMINAL_REVIEW', $deniedFinalResult['reason'] ?? null, 'Final-denial-requires-review must be named explicitly.');

// An actionable outcome with a suggested action the router does not implement must
// require a human decision rather than silently doing nothing.
$unsupportedSuggestionCase = errdBaseCase(936, '701-0000006-0000006');
$unsupportedSuggestionEvent = errdResponseEvent(936, 506, ['review_outcome' => 'INFO_REQUESTED', 'review_suggested_action' => 'ESCALATE_TO_LEGAL']);
$unsupportedSuggestionResult = $engine->nextAction($unsupportedSuggestionCase, [$unsupportedSuggestionEvent], $policy);
errdSame('BLOCKED_REVIEW', $unsupportedSuggestionResult['action'] ?? null, 'An actionable outcome with an unimplemented suggested action must require a human decision.');
errdSame('EMAIL_REVIEW_REQUIRES_HUMAN_DECISION', $unsupportedSuggestionResult['reason'] ?? null, 'Unimplemented suggested action must be named explicitly.');

// A completely unrecognized outcome value must never be treated as any known
// resolution: it must block as explicitly unsupported.
$unsupportedOutcomeCase = errdBaseCase(937, '701-0000007-0000007');
$unsupportedOutcomeEvent = errdResponseEvent(937, 507, ['review_outcome' => 'SOMETHING_UNKNOWN', 'review_suggested_action' => 'HUMAN_REVIEW']);
$unsupportedOutcomeResult = $engine->nextAction($unsupportedOutcomeCase, [$unsupportedOutcomeEvent], $policy);
errdSame('BLOCKED_REVIEW', $unsupportedOutcomeResult['action'] ?? null, 'An unrecognized review outcome must block rather than fall through unnoticed.');
errdSame('EMAIL_REVIEW_OUTCOME_UNSUPPORTED', $unsupportedOutcomeResult['reason'] ?? null, 'Unsupported outcome must be named explicitly.');

echo "email-review-response-decision-test: OK\n";
