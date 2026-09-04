<?php

declare(strict_types=1);

$service = __DIR__ . '/../includes/amazon-returns/SafeTEmailReview.php';
if (is_file($service)) require_once $service;

function erSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
}
function erAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

erAssert(class_exists('SvAmazonSafeTEmailReview'), 'SAFE-T email review service must exist.');
$case = [
    'id'=>2,
    'amazon_order_id'=>'702-5349464-0245862',
    'safe_t_id'=>'12472-25597-6629839',
    'refund_at'=>'2026-06-21 21:20:33',
    'seller_debit_at'=>'2026-06-21 21:20:33',
    'physical_status'=>'NOT_RECEIVED',
    'support_case_id'=>'21839128801',
];
$timeline = [[
    'event_type'=>'SAFE_T_STATUS_OBSERVED',
    'payload'=>['claim_status'=>'DENIED','decision_text'=>'Após a análise do recurso, negamos sua solicitação. A reivindicação não foi registrada dentro do período elegível.'],
]];
$msg = SvAmazonSafeTEmailReview::compose($case, $timeline);
erSame('Safe-T-Review@amazon.com', $msg['to'], 'Detailed review recipient is fixed by Seller Support guidance.');
erAssert(str_contains($msg['subject'], '12472-25597-6629839') && str_contains($msg['subject'], '702-5349464-0245862'), 'Subject must identify SAFE-T and order.');
erAssert(str_contains($msg['body'], 'não foi registrada dentro do período elegível'), 'Body must include the real Amazon denial reason.');
erAssert(str_contains($msg['body'], '21839128801'), 'Known Seller Support case must be included.');

echo "amazon-returns-safe-t-email-review-test: OK\n";
