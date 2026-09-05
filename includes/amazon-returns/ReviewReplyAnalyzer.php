<?php
declare(strict_types=1);

final class SvAmazonSafeTReviewReplyAnalyzer
{
    /** @return array{outcome:string,suggested_action:string,reason:string,content_sha256:string,excerpt:string} */
    public function analyze(array $message, array $context): array
    {
        $body = trim((string)($message['body_text'] ?? $message['snippet'] ?? ''));
        $from = trim((string)($message['from'] ?? ''));
        $normalized = self::normalize($body);
        $hash = hash('sha256', self::normalize((string)($message['subject'] ?? '')) . "\n" . $normalized);
        $excerpt = function_exists('mb_substr') ? mb_substr($body,0,600,'UTF-8') : substr($body,0,600);
        if ($body === '' || !self::amazonSender($from)) {
            return self::result('UNKNOWN_AMBIGUOUS','HUMAN_REVIEW','UNTRUSTED_OR_EMPTY_REPLY',$hash,$excerpt);
        }

        if (preg_match('/\b(?:solicitacao|reivindicacao|revisao)\b.{0,100}\b(?:foi |esta )?(?:aprovada|aprovado|concedida|concedido)\b/u',$normalized) === 1
            || str_contains($normalized,'reembolso sera processado')) {
            return self::result('APPROVED','CREDIT_PENDING','AMAZON_CONFIRMED_APPROVAL',$hash,$excerpt);
        }

        $asks = preg_match('/\b(?:envie|forneca|encaminhe|precisamos|necessitamos|responda)\b/u',$normalized) === 1;
        $evidence = preg_match('/\b(?:comprovante|rastreio|foto|fotos|evidencia|documento|informacao|informacoes|detalhes)\b/u',$normalized) === 1;
        if ($asks && $evidence) {
            $available = ($context['requested_evidence_available'] ?? false) === true;
            return self::result('INFO_REQUESTED',$available?'RESPOND_EMAIL':'HUMAN_REVIEW','AMAZON_REQUESTED_INFORMATION',$hash,$excerpt);
        }

        $denial = preg_match('/\b(?:neg(?:ad[oa]|amos)|nao podemos aprovar|nao e elegivel|fora do prazo|reafirmamos nossa decisao)\b/u',$normalized) === 1;
        $final = str_contains($normalized,'nao responderemos a outras comunicacoes')
            || str_contains($normalized,'decisao final')
            || str_contains($normalized,'nao sera reconsiderada');
        if ($denial || $final) {
            $newFact = ($context['new_material_fact'] ?? false) === true;
            $financial = ($context['financial_inconsistency'] ?? false) === true;
            $openChannel = ($context['open_channel'] ?? false) === true;
            if ($final && !$newFact && !$financial && !$openChannel) {
                $terminalCloseAllowed = ($context['terminal_close_allowed'] ?? false) === true;
                return self::result('DENIED_FINAL',$terminalCloseAllowed ? 'CLOSED_LOSS' : 'HUMAN_REVIEW','EXPLICIT_FINAL_DENIAL_NO_UNRESOLVED_PATH',$hash,$excerpt);
            }
            $action = $newFact || $openChannel ? 'RESPOND_EMAIL' : ($financial ? 'OPEN_SUPPORT' : 'HUMAN_REVIEW');
            return self::result('DENIED_ACTIONABLE',$action,'DENIAL_HAS_UNRESOLVED_ACTIONABLE_CONTEXT',$hash,$excerpt);
        }

        $wait = preg_match('/(?:reembolsad[oa]|reembolso).{0,100}(?:proativamente|automaticamente).{0,100}\bate\b/u',$normalized) === 1
            || preg_match('/\b(?:aguarde|espere).{0,80}\bate\b/u',$normalized) === 1
            || preg_match('/\b(?:aguarde|aguardar) (?:nossa|nosso) (?:resposta|retorno)\b/u',$normalized) === 1
            || preg_match('/\bentraremos em contato (?:assim que|quando)\b/u',$normalized) === 1
            || preg_match('/\brecebera uma atualizacao.{0,120}\b(?:analise|revisao)\b/u',$normalized) === 1;
        if ($wait) return self::result('WAIT','WAIT','AMAZON_PROMISED_FUTURE_ACTION',$hash,$excerpt);

        return self::result('UNKNOWN_AMBIGUOUS','HUMAN_REVIEW','REPLY_MEANING_NOT_RELIABLY_CLASSIFIED',$hash,$excerpt);
    }

    private static function amazonSender(string $from): bool
    {
        if (strpbrk($from, "\r\n") !== false) return false;
        $address = trim($from);
        if (preg_match('/^[^<>]*<([^<>]+)>$/D', $address, $match) === 1) $address = trim($match[1]);
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) return false;
        $domain = strtolower(substr(strrchr($address, '@'), 1));
        return $domain === 'amazon.com' || $domain === 'amazon.com.br'
            || str_ends_with($domain, '.amazon.com')
            || str_ends_with($domain, '.amazon.com.br');
    }

    private static function normalize(string $text): string
    {
        $text = html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $text = mb_strtolower($text,'UTF-8');
        $text = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$text) ?: $text;
        return preg_replace('/\s+/u',' ',trim($text)) ?? trim($text);
    }

    private static function result(string $outcome,string $action,string $reason,string $hash,string $excerpt): array
    {
        return [
            'outcome'=>$outcome,
            'suggested_action'=>$action,
            'reason'=>$reason,
            'content_sha256'=>$hash,
            'excerpt'=>$excerpt,
        ];
    }
}
