<?php
declare(strict_types=1);

final class SvAmazonSellerSupportStatus
{
    private const TERMINAL=['RESOLVED','CLOSED','CANCELLED'];

    /** @return array{case_id:string,case_status:string,latest_text:string,content_fingerprint:string} */
    public static function normalize(array $support): array
    {
        $caseId=trim((string)($support['case_id']??''));
        if(preg_match('/^\d{8,14}$/',$caseId)!==1)throw new RuntimeException('Invalid Seller Support case ID.');
        $status=strtoupper(trim((string)($support['case_status']??'')));
        if($status==='' || preg_match('/^[A-Z0-9_:-]{2,64}$/',$status)!==1)throw new RuntimeException('Invalid Seller Support case status.');
        $latest=self::boundedText($support['latest_text']??'',12000);
        return [
            'case_id'=>$caseId,
            'case_status'=>$status,
            'latest_text'=>$latest,
            'content_fingerprint'=>hash('sha256',$latest),
        ];
    }

    public static function isTerminalStatus(string $status): bool
    {
        return in_array(strtoupper(trim($status)),self::TERMINAL,true);
    }

    public static function readKey(int $caseId,string $supportCaseId,DateTimeInterface $now): string
    {
        if($caseId<1 || preg_match('/^\d{8,14}$/',trim($supportCaseId))!==1)throw new InvalidArgumentException('Seller Support read key requires case and support case ID.');
        $ts=DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
        $bucket=intdiv($ts,7200)*7200;
        return hash('sha256','seller-support-read|'.$caseId.'|'.trim($supportCaseId).'|'.gmdate('YmdHi',$bucket));
    }

    /** @param list<array<string,mixed>> $timeline */
    public static function readKeyForTimeline(int $caseId,string $supportCaseId,DateTimeInterface $now,array $timeline): string
    {
        $daily=self::readKey($caseId,$supportCaseId,$now);
        $supportCaseId=trim($supportCaseId);
        $latestObservationRank=[0,0];
        $latestWrite=null;$latestWriteRank=[0,0];
        foreach($timeline as $event){
            if(!is_array($event) || (int)($event['case_id']??0)!==$caseId)continue;
            try{$at=new DateTimeImmutable((string)($event['occurred_at']??''),new DateTimeZone('UTC'));}catch(Throwable){continue;}
            $rank=[$at->getTimestamp(),(int)($event['id']??0)];
            $type=(string)($event['event_type']??'');
            $source=(string)($event['source']??'');
            $payload=is_array($event['payload']??null)?$event['payload']:[];
            if($type==='SELLER_SUPPORT_STATUS_OBSERVED' && $source==='SELLER_CENTRAL'
                && trim((string)($payload['case_id']??''))===$supportCaseId && $rank>$latestObservationRank){
                $latestObservationRank=$rank;
                continue;
            }
            if($type!=='SELLER_CENTRAL_ACTION_RESULT' || $source!=='SELLER_CENTRAL')continue;
            $action=strtoupper(trim((string)($payload['action']??'')));
            $status=strtoupper(trim((string)($payload['status']??'')));
            if(!in_array($action,['SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'],true)
                || !in_array($status,['ACCEPTED','ALREADY_EXISTS'],true))continue;
            $external=trim((string)($payload['external_id']??''));
            if($external!=='' && $external!==$supportCaseId)continue;
            if($rank>$latestWriteRank){$latestWrite=$event;$latestWriteRank=$rank;}
        }
        if($latestWrite===null || $latestWriteRank<=$latestObservationRank)return $daily;
        $eventId=(int)($latestWrite['id']??0);
        return hash('sha256','seller-support-read-after-write|'.$caseId.'|'.$supportCaseId.'|'.$eventId);
    }

    /** @return array{append:bool,idempotency_key:string} */
    public static function observationPlan(int $caseId,array $support,array $timeline): array
    {
        $current=self::normalize($support);
        $latest=null;$rank=[0,0];
        foreach($timeline as $event){
            if(!is_array($event) || (int)($event['case_id']??0)!==$caseId)continue;
            if(($event['event_type']??'')!=='SELLER_SUPPORT_STATUS_OBSERVED' || ($event['source']??'')!=='SELLER_CENTRAL')continue;
            $payload=$event['payload']??null;
            if(!is_array($payload) || trim((string)($payload['case_id']??''))!==$current['case_id'])continue;
            try{$at=new DateTimeImmutable((string)($event['occurred_at']??''),new DateTimeZone('UTC'));}catch(Throwable){continue;}
            $candidate=[$at->getTimestamp(),(int)($event['id']??0)];
            if($candidate>$rank){$rank=$candidate;$latest=$event;}
        }
        $base=self::fingerprint($current);
        if(is_array($latest)){
            $payload=is_array($latest['payload']??null)?$latest['payload']:[];
            try{$same=self::fingerprint(self::normalize($payload))===$base;}catch(Throwable){$same=false;}
            if($same)return ['append'=>false,'idempotency_key'=>(string)($latest['idempotency_key']??hash('sha256',$base))];
        }
        return ['append'=>true,'idempotency_key'=>hash('sha256','seller-support-status-v1|'.$base.'|'.(int)($latest['id']??0))];
    }

    public static function resolution(array $support): string
    {
        $support=self::normalize($support);
        $terminal=self::isTerminalStatus($support['case_status']);
        $sellerAction=self::sellerActionPending($support['case_status']);
        $text=mb_strtolower($support['latest_text'],'UTF-8');
        if($terminal && str_contains($text,'safe-t-review@amazon.com'))return 'EMAIL_REVIEW';
        if(($terminal || $sellerAction) && self::directsSafeTSubmission($text))return 'SAFE_T_SUBMIT';
        if(!$terminal){
            if($sellerAction && self::returnNotReceivedDispute($text))return 'RETURN_NOT_RECEIVED_DISPUTE';
            if($sellerAction && self::buyerRefundOnly($text))return 'BUYER_REFUND_ONLY';
            return 'ACTIVE';
        }
        $money=preg_match('/(?:reembols|reimbursement|cr[eé]dito|credit)/u',$text)===1;
        $processed=preg_match('/(?:processad|processed|successful|sucesso|emitid|issued)/u',$text)===1;
        $delay=preg_match('/(?:4\s*(?:a|to|[-–])\s*5\s*(?:dias\s*[uú]teis|business\s*days)|reimbursement\s*id|id\s*(?:do|de)?\s*reembolso)/u',$text)===1;
        if($money && $processed && $delay)return 'REIMBURSEMENT_PROCESSING';
        $safeT=str_contains($text,'safe-t') || str_contains($text,'safet');
        $appeal=preg_match('/(?:appeal|recurso|recorr)/u',$text)===1;
        if($safeT && $appeal)return 'SAFE_T_APPEAL';
        if(self::returnNotReceivedDispute($text))return 'RETURN_NOT_RECEIVED_DISPUTE';
        if(self::buyerRefundOnly($text))return 'BUYER_REFUND_ONLY';
        return 'TERMINAL_AMBIGUOUS';
    }

    private static function directsSafeTSubmission(string $text): bool
    {
        if(!str_contains($text,'safe-t') && !str_contains($text,'safet'))return false;
        return preg_match('/(?:necess[aá]ri[oa]|deve(?:mos)?|precisa(?:mos)?|orientad[oa]s?|instru[ií]d[oa]s?).{0,140}(?:registr|abrir|criar|protocol|enviar|submit|file|open).{0,180}(?:safe-?t|safet)/u',$text)===1
            || preg_match('/(?:registr|abrir|criar|protocol|enviar|submit|file|open).{0,100}(?:nova?\s+)?(?:reivindica[cç][aã]o|claim).{0,100}(?:safe-?t|safet)/u',$text)===1;
    }

    private static function sellerActionPending(string $status): bool
    {
        $normalized=preg_replace('/[^A-Z0-9]+/','',strtoupper(trim($status))) ?? '';
        return in_array($normalized,['PENDINGSELLERACTION','AWAITINGSELLERACTION'],true);
    }

    private static function returnNotReceivedDispute(string $text): bool
    {
        $returnAsserted=preg_match('/(?:item|produto|devolu[cç][aã]o).{0,100}(?:foi\\s+)?devolvid[oa].{0,80}(?:\\d{1,2}[\\/.-]\\d{1,2}[\\/.-]\\d{2,4}|data\\s+de\\s+devolu[cç][aã]o)|(?:returned|return).{0,100}(?:item|product).{0,80}(?:date|\\d{1,2}[\\/.-]\\d{1,2}[\\/.-]\\d{2,4})/u',$text)===1;
        $deadline=preg_match('/(?:7\\s*dias|7\\s*days|seven\\s+days).{0,180}(?:devolu[cç][aã]o|returned|return)|(?:devolu[cç][aã]o|returned|return).{0,180}(?:7\\s*dias|7\\s*days|seven\\s+days)/u',$text)===1;
        $notReceived=preg_match('/(?:produto|item).{0,120}(?:n[aã]o|nao|not).{0,40}(?:retorn|devolv|recebid)|(?:n[aã]o|nao|not).{0,40}(?:retorn|devolv|recebid).{0,120}(?:produto|item)/u',$text)===1;
        return $returnAsserted && $deadline && $notReceived;
    }

    private static function buyerRefundOnly(string $text): bool
    {
        $buyerRefund=preg_match('/(?:reembolsad[oa]|reembolso|refund(?:ed)?|estorno).{0,120}(?:comprador|cliente(?: final)?|buyer|customer)|(?:comprador|cliente(?: final)?|buyer|customer).{0,120}(?:reembolsad[oa]|reembolso|refund(?:ed)?|estorno)/u',$text)===1;
        if(!$buyerRefund)return false;
        $sellerPaid=preg_match('/(?:o\\s+)?vendedor\\s+(?:já\\s+|ja\\s+)?(?:foi\\s+)?(?:ressarci|reembolsad|creditad)|(?:nossa|sua)\\s+conta\\s+de\\s+vendedor.{0,80}(?:creditad|reembolsad|ressarci)|(?:creditad|reembolsad|ressarci).{0,80}(?:nossa|sua)\\s+conta\\s+de\\s+vendedor|(?:the\\s+)?seller(?: account)?\\s+(?:was|has been|is)\\s+(?:credited|reimbursed)|(?:credited|reimbursed).{0,80}(?:the\\s+)?seller account/u',$text)===1;
        return !$sellerPaid;
    }

    public static function reimbursementDueAt(DateTimeImmutable $observedAt,int $businessDays=5): DateTimeImmutable
    {
        $remaining=max(1,$businessDays);$cursor=$observedAt->setTimezone(new DateTimeZone('UTC'));
        while($remaining>0){
            $cursor=$cursor->modify('+1 day');
            $weekday=(int)$cursor->format('N');
            if($weekday<=5)$remaining--;
        }
        return $cursor;
    }

    private static function fingerprint(array $support): string
    {
        return hash('sha256',implode('|',[$support['case_id'],$support['case_status'],$support['content_fingerprint']]));
    }

    private static function boundedText(mixed $value,int $limit): string
    {
        if(!is_scalar($value))return '';
        $text=preg_replace('/\s+/u',' ',trim((string)$value)) ?? trim((string)$value);
        return mb_substr($text,0,$limit,'UTF-8');
    }
}
