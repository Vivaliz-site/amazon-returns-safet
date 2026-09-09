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
        if ($this->isFbaShipmentSummary($subject, $body)) {
            return $this->parseFbaShipmentSummary($message, $messageId, $subject, $body);
        }

        $combined = $subject . "\n" . $body;
        $orderId = $this->extractOrderId($combined);
        if ($orderId === null) return [];

        $eventType = null;
        $safeTId = null;
        $amount = null;
        $currency = null;
        $refundInitiator = null;
        $program = null;
        $quantityOrdered = null;
        $quantityRefunded = null;
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
            if (preg_match('/Emissor\s+do\s+reembolso\s*:\s*Customer\s+Service\b/iu', $body) === 1) {
                $refundInitiator = 'AMAZON_CUSTOMER_SERVICE';
            } elseif (
                preg_match('/Emissor\s+do\s+reembolso\s*:/iu', $body) !== 1
                && preg_match('/Motivo\s+para\s+reembolso.*?Não\s+é\s+possível\s+realizar\s+a\s+entrega\b/isu', $body) === 1
            ) {
                $refundInitiator = 'AMAZON_CUSTOMER_SERVICE';
            }
            if (preg_match('/(?:Log[ií]stica|Rede\s+log[ií]stica\s+da\s+Amazon)\s*:\s*Enviado\s+pela\s+Amazon\b/iu', $body) === 1) {
                $program = 'FBA';
            }
            [$quantityOrdered,$quantityRefunded] = $this->extractRefundQuantities($body);
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
            'program'=>$program,
            'refund_initiator'=>$refundInitiator,
            'quantity_ordered'=>$quantityOrdered,
            'quantity_refunded'=>$quantityRefunded,
            'review_outcome'=>$review['outcome'] ?? null,
            'review_suggested_action'=>$review['suggested_action'] ?? null,
            'review_reason'=>$review['reason'] ?? null,
            'review_next_action_at'=>$review['next_action_at'] ?? null,
            'review_excerpt'=>$review['excerpt'] ?? null,
            'content_sha256'=>$contentSha,
            'idempotency_key'=>hash('sha256',implode('|',$identityParts)),
        ]];
    }

    private function isFbaShipmentSummary(string $subject,string $body): bool
    {
        return preg_match('/^A\s+Amazon\s+enviou\s+os\s+itens\s+vendidos$/iu',$subject)===1
            && preg_match('/programa\s+FBA\b/iu',$body)===1;
    }

    /** @return list<array<string,mixed>> */
    private function parseFbaShipmentSummary(array $message,string $messageId,string $subject,string $body): array
    {
        $matches=[];
        preg_match_all(
            '/N(?:u|ú)mero\s+do\s+pedido:\s*([0-9]{3}-[0-9]{7}-[0-9]{7})(.*?)(?=(?:Os\s+seguintes\s+itens\s+do\s+pedido\s+completo)|(?:Observe\s+que)|\z)/isu',
            $body,
            $matches,
            PREG_SET_ORDER
        );
        if($matches===[])return [];
        $contentSha=hash('sha256',$this->canonicalText($subject)."\n".$this->canonicalText($body));
        $occurredAt=$this->normalizeDate($message['received_at'] ?? $message['email_ts'] ?? null);
        $events=[];
        foreach($matches as $match){
            $orderId=(string)$match[1];
            $detail=(string)($match[2] ?? '');
            $tracking=null;
            if(preg_match('/Rastreamento:\s*([A-Z0-9][A-Z0-9._-]{3,})/iu',$detail,$trackingMatch)===1){
                $tracking=strtoupper(trim((string)$trackingMatch[1]));
            }
            $carrier=$this->carrierBeforeTracking($detail);
            $identityParts=['gmail',$messageId,'FBA_SHIPMENT_EMAIL',$orderId,$tracking ?? ''];
            $events[]=[
                'event_type'=>'FBA_SHIPMENT_EMAIL',
                'source'=>'GMAIL',
                'financial_truth'=>false,
                'source_event_id'=>$messageId,
                'message_id'=>$messageId,
                'thread_id'=>trim((string)($message['thread_id'] ?? '')),
                'rfc_message_id'=>trim((string)($message['rfc_message_id'] ?? '')),
                'order_id'=>$orderId,
                'safe_t_id'=>null,
                'occurred_at'=>$occurredAt,
                'amount'=>null,
                'currency'=>null,
                'program'=>'FBA',
                'tracking_id'=>$tracking,
                'carrier'=>$carrier,
                'customer_delivery_confirmed'=>false,
                'content_sha256'=>$contentSha,
                'idempotency_key'=>hash('sha256',implode('|',$identityParts)),
            ];
        }
        return $events;
    }

    /** @return array{0:?int,1:?int} */
    private function extractRefundQuantities(string $body): array
    {
        $rawLines=preg_split('/\R/u',$body) ?: [];
        $lines=[];
        foreach($rawLines as $raw){
            $line=trim((string)$raw);
            if($line!=='')$lines[]=$line;
        }
        $asinHeader=$orderedHeader=$refundedHeader=$reasonHeader=null;
        foreach($lines as $index=>$line){
            if($asinHeader===null && preg_match('/^ASIN$/iu',$line)===1)$asinHeader=$index;
            if($orderedHeader===null && preg_match('/^Quantidade\s+do\s+pedido$/iu',$line)===1)$orderedHeader=$index;
            if($refundedHeader===null && preg_match('/^Quantidade\s+da\s+devolu(?:ç|c)ão$/iu',$line)===1)$refundedHeader=$index;
            if($reasonHeader===null && preg_match('/^Motivo\s+para\s+reembolso$/iu',$line)===1)$reasonHeader=$index;
        }
        if($asinHeader===null || $orderedHeader===null || $refundedHeader===null || $reasonHeader===null
            || $orderedHeader<$asinHeader || $refundedHeader<$asinHeader || $reasonHeader<$refundedHeader){
            return [null,null];
        }
        $dataStart=$reasonHeader+1;
        $orderedIndex=$dataStart+($orderedHeader-$asinHeader);
        $refundedIndex=$dataStart+($refundedHeader-$asinHeader);
        $ordered=$lines[$orderedIndex]??null;
        $refunded=$lines[$refundedIndex]??null;
        if(!is_string($ordered) || preg_match('/^[0-9]+$/D',$ordered)!==1
            || !is_string($refunded) || preg_match('/^[0-9]+$/D',$refunded)!==1){
            return [null,null];
        }
        return [(int)$ordered,(int)$refunded];
    }

    private function carrierBeforeTracking(string $detail): ?string
    {
        $lines=preg_split('/\R/u',$detail) ?: [];
        foreach($lines as $index=>$line){
            if(preg_match('/^\s*Rastreamento\s*:/iu',(string)$line)!==1)continue;
            for($previous=$index-1;$previous>=0;$previous--){
                $candidate=trim((string)$lines[$previous]);
                if($candidate!=='')return $candidate;
            }
        }
        return null;
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
