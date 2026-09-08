<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/SafeTEmailReview.php';

$case = [
    'id' => 920,
    'amazon_order_id' => '701-0630116-9129834',
    'safe_t_id' => '98143-99485-9285859',
    'state' => 'EMAIL_REVIEW_RESPONSE_PENDING',
    'physical_status' => 'NOT_RECEIVED',
    'customer_delivery_confirmed' => true,
    'customer_tracking_ids' => ['TBR420573721'],
    'customer_delivery_at' => '2026-08-31 11:22:02',
];
$timeline = [[
    'event_type' => 'SAFE_T_EMAIL_REVIEW_RESPONSE',
    'payload' => [
        'review_suggested_action' => 'RESPOND_EMAIL',
        'review_excerpt' => 'O cliente alega que não recebeu o pedido.',
        'gmail_thread_id' => 'thread-920',
        'gmail_rfc_message_id' => '<message-920@example.test>',
    ],
]];

$message = SvAmazonSafeTEmailReview::composeReply($case, $timeline);
$body = $message['body'] ?? '';
foreach (['TBR420573721', 'entregue ao destinatário', 'divergência'] as $expected) {
    if (mb_stripos($body, $expected, 0, 'UTF-8') === false) {
        throw new RuntimeException('Automatic SAFE-T reply must cite trusted delivery contradiction: missing ' . $expected . "\n" . $body);
    }
}

echo "customer-nonreceipt-email-evidence-test: OK\n";
