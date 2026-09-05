<?php
declare(strict_types=1);

require_once __DIR__ . '/ReviewReplyAnalyzer.php';

final class SvAmazonGmailParser
{
    public function __construct(private ?SvAmazonSafeTReviewReplyAnalyzer $reviewAnalyzer = null)
    {
        $this->reviewAnalyzer ??= new SvAmazonSafeTReviewReplyAnalyzer();
    }

    /** @return list<array<string,mixed>> */
    public function parse(array $message): array
    {
        $messageId = trim((string)($message['message_id'] ?? $message['id'] ?? ''));
        $subject = trim((string)($message['subject'] ?? ''));
        $from = trim((string)($message['from'] ?? $message['from_'] ?? ''));
        if ($messageId === '' || $subject === '' || !$this->isAmazonSender($from)) return [];

        $body = (string)($message['body_text'] ?? $message['snippet'] ?? '');
        $combined = $subject . "\n" . $body;
        $orderId = $this->extractOrderId($combined);
        if ($orderId === null) return [];

        $eventType = null;
        $safeTId = null;
        $amount = null;
        $currency = null;
        $review = null;

        $isReviewChannel = stripos($from, 'safe-t-review@amazon.com') !== false
            || preg_match('/revis[aã]o\s+detalhada.*SAFE-T/iu', $subject) === 1;
        if ($isReviewChannel && preg_match('/\b([0-9]{5}-[0-9]{5}-[0-9]{7})\b/', $combined, $match) === 1) {
            $eventType = 'SAFE_T_EMAIL_REVIEW_RESPONSE';
            $safeTId = $match[1];
            $review = $this->reviewAnalyzer->analyze($message, ['terminal_close_allowed'=>false]);
        } elseif (preg_match('/\bReembolso\s+de\s+([0-9]+(?:[\.,][0-9]{1,2})?)\s+BRL\s+iniciado\s+para\s+o\s+pedido\b/iu', $subject, $match) === 1) {
            $eventType = 'REFUND_ISSUED_EMAIL';
            $amount = $this->normalizeAmount($match[1]);
            $currency = 'BRL';
        } elseif (preg_match('/Notifica(?:ç|c)ão\s+de\s+autoriza(?:ç|c)ão\s+de\s+devolu(?:ç|c)ão\s+referente\s+ao\s+pedido/iu', $subject) === 1) {
            $eventType = 'RETURN_AUTHORIZED_EMAIL';
        } elseif (preg_match('/Sua\s+solicita(?:ç|c)ão\s+do\s+SAFE-T\s+([0-9]+-[0-9]+-[0-9]+)\s+foi\s+registrada/iu', $subject, $match) === 1) {
            $eventType = 'SAFE_T_REGISTERED_EMAIL';
            $safeTId = $match[1];
        } elseif (preg_match('/Atualiza(?:ç|c)ão\s+da\s+solicita(?:ç|c)ão\s+do\s+SAFE-T\s+([0-9]+-[0-9]+-[0-9]+)/iu', $subject, $match) === 1) {
            $eventType = 'SAFE_T_UPDATED_EMAIL';
            $safeTId = $match[1];
        }
        if ($eventType === null) return [];

        $contentSha = hash('sha256', $this->canonicalText($subject) . "\n" . $this->canonicalText($body));
        $identityParts = ['gmail',$messageId,$eventType,$orderId,$safeTId ?? ''];
        return [[
            'event_type'=>$eventType,
            'source'=>'GMAIL',
            'financial_truth'=>false,
            'source_event_id'=>$messageId,
            'message_id'=>$messageId,
            'thread_id'=>trim((string)($message['thread_id'] ?? '')),
            'rfc_message_id'=>trim((string)($message['rfc_message_id'] ?? '')),
            'order_id'=>$orderId,
            'safe_t_id'=>$safeTId,
            'occurred_at'=>$this->normalizeDate($message['received_at'] ?? $message['email_ts'] ?? null),
            'amount'=>$amount,
            'currency'=>$currency,
            'review_outcome'=>$review['outcome'] ?? null,
            'review_suggested_action'=>$review['suggested_action'] ?? null,
            'review_reason'=>$review['reason'] ?? null,
            'review_next_action_at'=>$review['next_action_at'] ?? null,
            'review_excerpt'=>$review['excerpt'] ?? null,
            'content_sha256'=>$contentSha,
            'idempotency_key'=>hash('sha256',implode('|',$identityParts)),
        ]];
    }

    private function isAmazonSender(string $from): bool
    {
        if (preg_match('/<?([A-Z0-9._%+\-]+@([A-Z0-9.\-]+))>?/i',$from,$match) !== 1) return false;
        $domain = strtolower(rtrim($match[2],'.'));
        return $domain === 'amazon.com' || $domain === 'amazon.com.br'
            || str_ends_with($domain,'.amazon.com') || str_ends_with($domain,'.amazon.com.br');
    }

    private function extractOrderId(string $text): ?string
    {
        return preg_match('/\b([0-9]{3}-[0-9]{7}-[0-9]{7})\b/',$text,$match) === 1 ? $match[1] : null;
    }

    private function normalizeAmount(string $raw): string
    {
        $raw = str_replace(',','.',trim($raw));
        if (!is_numeric($raw)) throw new InvalidArgumentException('Invalid Gmail refund amount.');
        return number_format((float)$raw,2,'.','');
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_scalar($value) || trim((string)$value) === '') return null;
        try { return (new DateTimeImmutable((string)$value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
        catch (Throwable) { return null; }
    }

    private function canonicalText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $text = preg_replace('/\s+/u',' ',trim($text)) ?? trim($text);
        return mb_strtolower($text,'UTF-8');
    }
}
