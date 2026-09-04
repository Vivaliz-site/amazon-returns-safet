<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/GmailParser.php';
require_once __DIR__ . '/../includes/amazon-returns/GmailApi.php';
require_once __DIR__ . '/../includes/amazon-returns/GmailEventSink.php';
require_once __DIR__ . '/../workers/amazon-returns/gmail-ingest.php';

function gmAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function gmSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
}

function message(string $id, string $subject, string $body = 'Mensagem Amazon sanitizada.', array $labels = []): array {
    return [
        'message_id' => $id,
        'thread_id' => 'thread-' . $id,
        'from' => 'Comunicações do Amazon Seller Central <donotreply@amazon.com>',
        'subject' => $subject,
        'received_at' => '2026-09-01T17:30:27Z',
        'body_text' => $body,
        'labels' => $labels,
    ];
}

$parser = new SvAmazonGmailParser();
$refund = $parser->parse(message('m-refund', 'Reembolso de 128.25 BRL iniciado para o pedido 701-1234567-7654321'));
gmSame(1, count($refund), 'Refund email must emit one event.');
gmSame('REFUND_ISSUED_EMAIL', $refund[0]['event_type'], 'Refund event type.');
gmSame('701-1234567-7654321', $refund[0]['order_id'], 'Refund order ID.');
gmSame('128.25', $refund[0]['amount'], 'Refund amount normalization.');
gmSame('BRL', $refund[0]['currency'], 'Refund currency.');
gmSame('GMAIL', $refund[0]['source'], 'Gmail is event source.');
gmSame(false, $refund[0]['financial_truth'], 'Gmail must never be financial truth.');

$returnAuth = $parser->parse(message('m-return', 'Notificação de autorização de devolução referente ao pedido de número 702-1111111-2222222'));
gmSame('RETURN_AUTHORIZED_EMAIL', $returnAuth[0]['event_type'], 'Return authorization event type.');
gmSame('702-1111111-2222222', $returnAuth[0]['order_id'], 'Return authorization order ID.');

$registered = $parser->parse(message('m-register', 'Sua solicitação do SAFE-T 98143-99485-9285859 foi registrada para o pedido 702-3333333-4444444'));
gmSame('SAFE_T_REGISTERED_EMAIL', $registered[0]['event_type'], 'SAFE-T registration event type.');
gmSame('98143-99485-9285859', $registered[0]['safe_t_id'], 'SAFE-T registration ID.');



gmSame('item-1', SvAmazonGmailEventSink::targetItemId(['item-1']), 'Gmail replay after SP-API single-item resolution must attach to the resolved item.');
gmSame(SvAmazonGmailEventSink::UNRESOLVED_ITEM_ID, SvAmazonGmailEventSink::targetItemId([]), 'No resolved item must use placeholder.');
gmSame(SvAmazonGmailEventSink::UNRESOLVED_ITEM_ID, SvAmazonGmailEventSink::targetItemId(['item-1','item-2']), 'Multi-item order must remain ambiguous.');

$refundPatch = SvAmazonGmailEventSink::casePatch($refund[0]);
gmSame('POLICY_REVIEW_REQUIRED', $refundPatch['state'], 'Gmail-only refund must remain blocked for policy review.');
gmAssert(!array_key_exists('seller_debit_at', $refundPatch), 'Gmail refund must not assert seller debit truth.');
gmAssert(!array_key_exists('refund_initiator', $refundPatch), 'Gmail refund must not infer refund initiator.');
$registeredPatch = SvAmazonGmailEventSink::casePatch($registered[0]);
gmSame('98143-99485-9285859', $registeredPatch['safe_t_id'], 'SAFE-T email may attach the observed claim ID.');
gmSame('SAFE_T_SUBMITTED', $registeredPatch['state'], 'Registered SAFE-T email updates observational state only.');

$reviewReply = message('m-review-reply', 'Re: Solicitação de revisão detalhada — SAFE-T 12472-25597-6629839 / Pedido 702-5349464-0245862', 'Após análise manual, negamos a solicitação de reembolso.');
$reviewReply['from'] = 'SAFE-T Review <Safe-T-Review@amazon.com>';
$reviewEvents = $parser->parse($reviewReply);
gmSame(1, count($reviewEvents), 'SAFE-T review email reply must emit one event.');
gmSame('SAFE_T_EMAIL_REVIEW_RESPONSE', $reviewEvents[0]['event_type'], 'Detailed review reply event type.');
gmSame('12472-25597-6629839', $reviewEvents[0]['safe_t_id'], 'Detailed review reply SAFE-T ID.');
gmSame('DENIED', $reviewEvents[0]['review_outcome'] ?? null, 'Detailed review reply outcome must classify explicit denial.');
$reviewPatch = SvAmazonGmailEventSink::casePatch($reviewEvents[0]);
gmSame('SUPPORT_ESCALATION', $reviewPatch['state'] ?? null, 'Denied detailed email review must move to Seller Support escalation stage.');

$updated = $parser->parse(message('m-update', 'Atualização da solicitação do SAFE-T 12472-25597-6629839 para o pedido 702-5555555-6666666'));
gmSame('SAFE_T_UPDATED_EMAIL', $updated[0]['event_type'], 'SAFE-T update event type.');
gmSame('12472-25597-6629839', $updated[0]['safe_t_id'], 'SAFE-T update ID.');

$unread = message('m-state', 'Reembolso de 22,44 BRL iniciado para o pedido 701-7777777-8888888', 'Mesmo corpo', ['INBOX','UNREAD']);
$read = $unread;
$read['labels'] = ['INBOX'];
$eventUnread = $parser->parse($unread)[0];
$eventRead = $parser->parse($read)[0];
gmSame($eventUnread['idempotency_key'], $eventRead['idempotency_key'], 'Unread state must not affect event identity.');
gmSame('22.44', $eventRead['amount'], 'Comma decimal must normalize.');
gmAssert(preg_match('/^[a-f0-9]{64}$/', $eventRead['content_sha256']) === 1, 'Content evidence must be SHA-256.');
gmAssert(!array_key_exists('body_text', $eventRead), 'Raw body must not be stored in domain event.');

$replay1 = $parser->parse($unread)[0];
$replay2 = $parser->parse($unread)[0];
gmSame($replay1['idempotency_key'], $replay2['idempotency_key'], 'Replay must produce deterministic idempotency key.');

$notAmazon = $unread;
$notAmazon['message_id'] = 'm-spoof';
$notAmazon['from'] = 'Fake Amazon <attacker@example.com>';
gmSame([], $parser->parse($notAmazon), 'Non-Amazon sender must be ignored.');
gmSame([], $parser->parse(message('m-other', 'Sua fatura mensal está disponível')), 'Unrelated Amazon email must be ignored.');

$ids = [];
$nextId = 1;
$append = static function(array $event) use (&$ids, &$nextId): int {
    $key = $event['idempotency_key'];
    if (isset($ids[$key])) return $ids[$key];
    return $ids[$key] = $nextId++;
};
$ingestor = new SvAmazonGmailIngestor($parser);
$first = $ingestor->ingest([$unread, message('m-other2', 'Outro aviso Amazon sem relação')], $append, 'history-101');
$second = $ingestor->ingest([$read], $append, 'history-102');
gmSame(1, $first['events'], 'First ingest must emit one relevant event.');
gmSame(1, $second['events'], 'Replay is still observed as an event candidate.');
gmSame(1, count($ids), 'Append idempotency prevents duplicate stored event.');
gmSame('history-102', $second['cursor'], 'Cursor advances independently from unread labels.');


$calls = [];
$sentCreated = false;
$transport = static function(string $method, string $url, array $headers, ?array $body = null) use (&$calls, &$sentCreated): array {
    $calls[] = [$method, $url, $headers, $body];
    if (str_contains($url, '/profile')) return ['status'=>200,'json'=>['historyId'=>'200']];
    if (str_contains($url, '/history?')) return ['status'=>200,'json'=>['history'=>[['messagesAdded'=>[['message'=>['id'=>'m-api-1']]]]]]];
    if (str_contains($url, '/messages?') && str_contains($url, 'rfc822msgid')) return ['status'=>200,'json'=>$sentCreated ? ['messages'=>[['id'=>'sent-1','threadId'=>'sent-thread-1']]] : []];
    if (str_contains($url, '/messages?')) return ['status'=>200,'json'=>['messages'=>[]]];
    if (str_contains($url, '/messages/send')) { $sentCreated = true; return ['status'=>200,'json'=>['id'=>'sent-1','threadId'=>'sent-thread-1']]; }
    if (str_contains($url, '/messages/m-api-1?')) return ['status'=>200,'json'=>[
        'id'=>'m-api-1','threadId'=>'t-api-1','internalDate'=>'1788283827000',
        'payload'=>['headers'=>[
            ['name'=>'From','value'=>'Amazon <donotreply@amazon.com>'],
            ['name'=>'Subject','value'=>'Reembolso de 10.50 BRL iniciado para o pedido 701-0000000-0000001'],
        ],'mimeType'=>'text/plain','body'=>['data'=>rtrim(strtr(base64_encode('Pedido 701-0000000-0000001'), '+/', '-_'), '=')]],
    ]];
    throw new RuntimeException('Unexpected Gmail API URL: '.$url);
};
$gmailApi = new SvAmazonGmailApiClient(
    new SvAmazonReturnsConfig(['GMAIL_OAUTH_ACCESS_TOKEN'=>'test-token']),
    $transport
);
$pulled = $gmailApi->pull('100');
gmSame('200', $pulled['cursor'], 'Gmail cursor must advance to profile historyId.');
gmSame(1, count($pulled['messages']), 'History ingestion must fetch added messages.');
gmSame('m-api-1', $pulled['messages'][0]['message_id'], 'Normalized Gmail message ID.');
gmSame('Amazon <donotreply@amazon.com>', $pulled['messages'][0]['from'], 'Gmail From header normalization.');
gmAssert(!in_array('UNREAD', $pulled['messages'][0]['labels'] ?? [], true), 'Unread state is not required for source cursor semantics.');
gmAssert(str_starts_with($calls[1][1], 'https://gmail.googleapis.com/gmail/v1/users/me/history?'), 'Incremental pull must use Gmail history API.');
gmAssert(method_exists($gmailApi, 'send'), 'Gmail client must expose authenticated send for SAFE-T detailed review.');
$sent = $gmailApi->send('Safe-T-Review@amazon.com', 'Revisão SAFE-T 12472-25597-6629839', 'Corpo sanitizado');
gmSame('sent-1', $sent['message_id'], 'Gmail send must return message ID.');
gmSame('sent-thread-1', $sent['thread_id'], 'Gmail send must return thread ID.');
$sendCall = $calls[count($calls)-1];
gmSame('POST', $sendCall[0], 'Gmail send uses POST.');
gmAssert(str_contains($sendCall[1], '/messages/send'), 'Gmail send endpoint.');
gmAssert(is_array($sendCall[3]) && isset($sendCall[3]['raw']), 'Gmail send must submit RFC822 raw payload.');
$sentCreated = false;
$callsBeforeOnce = count($calls);
$once1 = $gmailApi->sendOnce('Safe-T-Review@amazon.com', 'Revisão SAFE-T 12472-25597-6629839', 'Corpo sanitizado', str_repeat('a', 64));
$once2 = $gmailApi->sendOnce('Safe-T-Review@amazon.com', 'Revisão SAFE-T 12472-25597-6629839', 'Corpo sanitizado', str_repeat('a', 64));
gmSame($once1['message_id'], $once2['message_id'], 'Idempotent Gmail send must resolve the already-sent RFC822 message.');
$onceCalls = array_slice($calls, $callsBeforeOnce);
$postCount = count(array_filter($onceCalls, static fn(array $call): bool => $call[0] === 'POST' && str_contains($call[1], '/messages/send')));
gmSame(1, $postCount, 'Idempotent Gmail send must POST only once across retry.');
$bootstrapStart = count($calls);
$gmailApi->pull(null, 2);
$bootstrapCalls = array_slice($calls, $bootstrapStart);
$bootstrapUrl = '';
foreach ($bootstrapCalls as $call) if (str_contains($call[1], '/messages?')) { $bootstrapUrl = $call[1]; break; }
gmAssert(str_contains(urldecode($bootstrapUrl), 'Safe-T-Review@amazon.com'), 'Gmail bootstrap/recovery query must include detailed SAFE-T review replies.');

echo "amazon-returns-gmail-test: OK\n";
