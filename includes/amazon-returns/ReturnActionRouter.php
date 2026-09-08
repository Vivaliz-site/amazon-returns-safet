<?php
declare(strict_types=1);
require_once __DIR__ . '/Enums.php';

/** Select an operational route; returning null delegates the existing claim lifecycle. */
final class SvAmazonReturnActionRouter
{
    public static function decide(array $case,array $timeline,array $policy,DateTimeImmutable $now): ?array
    {
        $events=self::trustedEvents($timeline,(int)($case['id']??0));
        $claim=trim((string)($case['safe_t_id']??''));
        $events=array_values(array_filter($events,static function(array $event) use ($claim):bool {
            $id=trim((string)($event['payload']['safe_t_id']??''));
            return $claim==='' || $id==='' || $id===$claim;
        }));
        $physical=(string)($case['physical_status']??'');
        if($physical==='RECEIVED_DISCREPANT'){
            if($claim==='')return self::decision('HUMAN_REVIEW','DAMAGED_RETURN_INITIAL_CLAIM_MANUAL_ONLY',$case);
            return null;
        }
        if($claim==='' && self::deliveryBackedUnknownRefund($case) && ($policy['eligible']??false)===true && self::hasOutstandingSellerLoss($case)){
            if(!self::freshUnpaidFinance($events,$now->modify('-2 hours'),$now)){
                return self::decision('CHECK_FINANCES','DELIVERED_CUSTOMER_REFUNDED_VERIFY_FINANCES',$case);
            }
            return null;
        }
        if($claim==='' && self::amazonCustomerRefund($case) && ($policy['eligible']??false)===true && self::hasOutstandingSellerLoss($case)){
            return null;
        }
        if(($case['program']??'')==='FBA' && $claim==='')return self::decision('CHECK_FINANCES','CLASSIC_FBA_SEPARATE_REIMBURSEMENT_ROUTE',$case);
        $sent=self::latest($events,['SAFE_T_EMAIL_REVIEW_SENT','SAFE_T_EMAIL_REPLY_SENT']);
        $reply=self::latest($events,['SAFE_T_EMAIL_REVIEW_RESPONSE']);
        if($sent!==null && ($reply===null || self::rank($sent)>self::rank($reply)))return self::decision('WAIT','EXISTING_EMAIL_REVIEW_AWAITING_RESPONSE',$case);
        $message=self::latest($events,['SAFE_T_STATUS_OBSERVED','SAFE_T_EMAIL_REVIEW_RESPONSE']);
        $text=self::normalized((string)($message['payload']['decision_text']??$message['payload']['review_excerpt']??''));
        $promise=self::promiseDeadline($text);
        if($promise===null && preg_match('/(?:sera reembolsad[oa]|ressarcimento proativo|reembolsad[oa] proativamente|aguarde (?:ate|nossa)|aguardar nossa resposta)/',$text)===1)
            return self::decision('HUMAN_REVIEW','PROMISED_ACTION_DATE_UNRESOLVED',$case);
        if($promise!==null){
            if($now<$promise)return self::decision('WAIT_PROACTIVE_CREDIT','EXPLICIT_AMAZON_REIMBURSEMENT_PROMISE',$case,$promise);
            if(!self::freshUnpaidFinance($events,$promise,$now))return self::decision('CHECK_FINANCES','PROMISE_EXPIRED_VERIFY_REAL_CREDIT',$case);
            if($claim!=='' && self::acceptedAppeal($events,$claim))return self::decision('WAIT','APPEAL_ALREADY_SUBMITTED_AWAITING_RESPONSE',$case);
            if($claim!=='' && ($message['payload']['appeal_submitted']??null)===false){
                $deadline=self::date($case['appeal_deadline_at']??$message['payload']['appeal_deadline_at']??null);
                if($deadline===null)return self::decision('HUMAN_REVIEW','OFFICIAL_APPEAL_DEADLINE_MISSING',$case);
                if($deadline<$now)return self::decision('SAFE_T_APPEAL','MISSED_APPEAL_WINDOW_RECOVERY_ATTEMPT',$case);
                return self::decision('SAFE_T_APPEAL','PROMISE_EXPIRED_UNPAID_APPEAL_REQUIRED',$case);
            }
        }
        if($claim!==''){
            if(in_array($case['state']??'',['SAFE_T_DENIED','APPEAL_REQUIRED','SAFE_T_INFO_REQUESTED'],true)){
                $deadline=self::date($case['appeal_deadline_at']??null);
                if($deadline===null)return self::decision('HUMAN_REVIEW','OFFICIAL_APPEAL_DEADLINE_MISSING',$case);
                if($deadline<$now){
                    if(($message['payload']['appeal_submitted']??null)===false)return self::decision('SAFE_T_APPEAL','MISSED_APPEAL_WINDOW_RECOVERY_ATTEMPT',$case);
                    return self::decision('HUMAN_REVIEW','APPEAL_WINDOW_EXPIRED',$case);
                }
            }
            return null;
        }
        $transport=self::latest($events,['RETURN_REPORT_OBSERVED','RETURN_TRANSPORT_OBSERVED']);
        $data=is_array($transport['payload']??null)?$transport['payload']:[];
        if($data===[] && in_array($case['return_status_source']??'',['SP_API_REPORTS','SELLER_CENTRAL'],true))$data=$case;
        $status=self::normalized((string)($data['return_status']??''));
        if(in_array($status,['perdido no transporte','lost in transit','lost','nao foi possivel entregar','undeliverable','recusado pelo cliente','refused','avariado pela transportadora','carrier damaged','damaged','danificado - descartado'],true)){
            $days=self::waitingDays($case);$basis=self::date($case['seller_debit_at']??null);
            if($days===null || $basis===null)return self::decision('HUMAN_REVIEW','PROACTIVE_POLICY_OR_DEBIT_UNVERIFIED',$case);
            $deadline=$basis->modify('+'.$days.' days');
            if($now<$deadline)return self::decision('WAIT_PROACTIVE_CREDIT','PROACTIVE_REIMBURSEMENT_PERIOD',$case,$deadline);
            if(!self::freshUnpaidFinance($events,$deadline,$now))return self::decision('CHECK_FINANCES','PROACTIVE_PERIOD_EXPIRED_VERIFY_CREDIT',$case);
            if(self::activeSupport($case))return self::decision('WAIT','SUPPORT_ESCALATION_ALREADY_ACTIVE',$case);
            return self::decision('SELLER_SUPPORT_OPEN','PROACTIVE_CREDIT_OVERDUE_AFTER_FINANCIAL_CHECK',$case);
        }
        if(in_array($status,['entregue ao vendedor','devolvido ao vendedor','returned to seller','delivered to seller'],true)
            || $physical==='CARRIER_DELIVERED_PENDING_PHYSICAL'){
            $delivered=self::date($data['return_delivery_at']??null);
            if($delivered===null)return self::decision('HUMAN_REVIEW','CARRIER_MARKED_DELIVERY_DATE_MISSING',$case);
            $end=$delivered->setTimezone(new DateTimeZone('America/Sao_Paulo'))->setTime(0,0)->modify('+8 days');
            if($now>=$end)return self::decision('HUMAN_REVIEW','SEVEN_CALENDAR_DAY_CLAIM_WINDOW_EXPIRED',$case);
            return self::decision('DAMAGE_EVIDENCE_REVIEW','CARRIER_DELIVERY_REQUIRES_PHYSICAL_EVIDENCE',$case,$end);
        }
        if(($policy['eligible']??false)!==true)return null;
        if(in_array($status,['retornando ao vendedor','returning to seller','return to seller in transit'],true))return null;
        return self::decision('HUMAN_REVIEW','RETURN_TRANSPORT_STATUS_UNVERIFIED',$case);
    }

    private static function deliveryBackedUnknownRefund(array $case): bool
    {
        return ($case['customer_delivery_confirmed']??false)===true
            && trim((string)($case['refund_at']??''))!==''
            && (string)($case['refund_initiator']??SvAmazonRefundInitiators::UNKNOWN)===SvAmazonRefundInitiators::UNKNOWN;
    }

    private static function amazonCustomerRefund(array $case): bool
    {
        if(trim((string)($case['refund_at']??''))==='')return false;
        return in_array((string)($case['refund_initiator']??''),[
            SvAmazonRefundInitiators::AMAZON_AUTOMATIC,
            SvAmazonRefundInitiators::AMAZON_CUSTOMER_SERVICE,
            SvAmazonRefundInitiators::A_TO_Z,
        ],true);
    }

    private static function hasOutstandingSellerLoss(array $case): bool
    {
        $expected=(float)($case['expected_reimbursement_amount']??0);
        $credited=(float)($case['reconciled_credit_amount']??0);
        $physical=(string)($case['physical_status']??'');
        $missingItem=$physical!==SvAmazonReturnPhysicalStatuses::RECEIVED_OK;
        $missingCredit=$expected>0 && $credited+0.00001<$expected;
        return $missingItem || $missingCredit;
    }

    private static function waitingDays(array $case): ?int
    {
        $program=$case['program']??'';
        if($program==='STANDARD')return 45;
        if(!in_array($program,['FBA_ONSITE','DELIVERY_BY_AMAZON'],true))return null;
        $order=self::date($case['order_at']??null);
        return $order===null?null:($order->format('Y-m-d')<'2026-04-21'?45:60);
    }

    private static function freshUnpaidFinance(array $events,DateTimeImmutable $after,DateTimeImmutable $now): bool
    {
        $event=self::latest($events,['FINANCIAL_RECONCILIATION_CHECKED']);
        $at=self::date($event['occurred_at']??null);
        $p=is_array($event['payload']??null)?$event['payload']:[];
        $ambiguity=$p['ambiguous_reimbursement_transactions']??null;
        $unsettled=$p['unsettled_financial_evidence']??null;
        return $at!==null && $at>=$after && $at<=$now && $at>=$now->modify('-2 hours')
            && ($p['refresh_complete']??false)===true
            && is_int($ambiguity) && $ambiguity===0
            && is_bool($unsettled) && $unsettled===false
            && is_numeric($p['outstanding_amount']??null) && (float)$p['outstanding_amount']>0;
    }

    private static function acceptedAppeal(array $events,string $claim): bool
    {
        for($i=count($events)-1;$i>=0;$i--){
            $event=$events[$i];
            if(($event['event_type']??'')!=='SELLER_CENTRAL_ACTION_RESULT')continue;
            $payload=is_array($event['payload']??null)?$event['payload']:[];
            if(strtoupper(trim((string)($payload['action']??'')))!=='SAFE_T_APPEAL')continue;
            if(strtoupper(trim((string)($payload['status']??'')))!=='ACCEPTED' || ($payload['submitted']??false)!==true)continue;
            $external=trim((string)($payload['external_id']??''));
            if($external==='' || $external===$claim)return true;
        }
        return false;
    }

    private static function activeSupport(array $case): bool
    {
        return trim((string)($case['support_case_id']??''))!==''
            && !in_array(strtoupper((string)($case['support_case_status']??'OPEN')),['CLOSED','RESOLVED','CANCELLED'],true);
    }

    private static function decision(string $action,string $reason,array $case,?DateTimeImmutable $next=null): array
    {
        return ['action'=>$action,'reason'=>$reason,'case_id'=>(int)($case['id']??0),'operational_mode'=>$action,
            'next_action_at'=>$next?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'idempotency_key'=>hash('sha256',implode('|',['route-v1',$case['id']??0,$case['refund_at']??'',$action,$reason]))];
    }

    private static function trustedEvents(array $timeline,int $caseId): array
    {
        $events=[];
        foreach($timeline as $e){
            if(!is_array($e) || (isset($e['case_id']) && (int)$e['case_id']!==$caseId))continue;
            if(!in_array($e['source']??'',['SELLER_CENTRAL','GMAIL','SP_API_REPORTS','SP_API_FINANCES','SP_API_ORDERS'],true))continue;
            $events[]=$e;
        }
        usort($events,static fn(array $a,array $b):int=>self::rank($a)<=>self::rank($b));
        return $events;
    }
    private static function latest(array $events,array $types): ?array
    {
        for($i=count($events)-1;$i>=0;$i--)if(in_array($events[$i]['event_type']??'',$types,true))return $events[$i];
        return null;
    }
    private static function rank(array $e): array{return [self::date($e['occurred_at']??null)?->getTimestamp()??0,(int)($e['id']??0)];}
    private static function normalized(string $s): string
    {
        $s=mb_strtolower(html_entity_decode(strip_tags($s),ENT_QUOTES|ENT_HTML5,'UTF-8'),'UTF-8');
        $s=preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u','',$s)??$s;
        return trim(preg_replace('/\s+/',' ',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s)?:$s)??$s);
    }
    private static function date(mixed $value): ?DateTimeImmutable
    {
        if($value instanceof DateTimeInterface)return DateTimeImmutable::createFromInterface($value);
        if(!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ]|$)/',$value,$m)!==1 || !checkdate((int)$m[2],(int)$m[3],(int)$m[1]))return null;
        try{$date=new DateTimeImmutable($value,new DateTimeZone('UTC'));return DateTimeImmutable::getLastErrors()===false?$date:null;}catch(Throwable){return null;}
    }

    public static function promiseDeadline(string $text): ?DateTimeImmutable
    {
        $text=self::normalized($text);
        if(preg_match('/reembols|ressarc|aguard|espere|wait|reimburse/',$text)!==1)return null;
        $dates=[];
        if(preg_match_all('/(?:ate|until)\s+(\d{1,2})\/(\d{1,2})\/(\d{4})\b/',$text,$matches,PREG_SET_ORDER)){
            foreach($matches as $m)if(checkdate((int)$m[2],(int)$m[1],(int)$m[3]))$dates[]=sprintf('%04d-%02d-%02d',$m[3],$m[2],$m[1]);
        }
        if(preg_match_all('/(?:ate|until)\s+(\d{4})-(\d{2})-(\d{2})\b/',$text,$matches,PREG_SET_ORDER)){
            foreach($matches as $m)if(checkdate((int)$m[2],(int)$m[3],(int)$m[1]))$dates[]=sprintf('%04d-%02d-%02d',$m[1],$m[2],$m[3]);
        }
        $months=['janeiro'=>1,'fevereiro'=>2,'marco'=>3,'abril'=>4,'maio'=>5,'junho'=>6,'julho'=>7,'agosto'=>8,'setembro'=>9,'outubro'=>10,'novembro'=>11,'dezembro'=>12];
        if(preg_match_all('/(?:ate|until)\s+(\d{1,2}) de ([a-z]+) de (\d{4})\b/',$text,$matches,PREG_SET_ORDER)){
            foreach($matches as $m){$month=$months[$m[2]]??0;if(checkdate($month,(int)$m[1],(int)$m[3]))$dates[]=sprintf('%04d-%02d-%02d',$m[3],$month,$m[1]);}
        }
        $englishMonths=['jan'=>1,'january'=>1,'feb'=>2,'february'=>2,'mar'=>3,'march'=>3,'apr'=>4,'april'=>4,'may'=>5,'jun'=>6,'june'=>6,'jul'=>7,'july'=>7,'aug'=>8,'august'=>8,'sep'=>9,'sept'=>9,'september'=>9,'oct'=>10,'october'=>10,'nov'=>11,'november'=>11,'dec'=>12,'december'=>12];
        if(preg_match_all('/(?:ate|until)\s+(?:(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)\s*,?\s*)?([a-z]+)\s+(\d{1,2})\s*,?\s*(\d{4})\b/',$text,$matches,PREG_SET_ORDER)){
            foreach($matches as $m){$month=$englishMonths[$m[1]]??0;if(checkdate($month,(int)$m[2],(int)$m[3]))$dates[]=sprintf('%04d-%02d-%02d',$m[3],$month,$m[2]);}
        }
        if(preg_match_all('/(?:ate|until)\s+(\d{1,2})\s+([a-z]+)\s*,?\s*(\d{4})\b/',$text,$matches,PREG_SET_ORDER)){
            foreach($matches as $m){$month=$englishMonths[$m[2]]??0;if(checkdate($month,(int)$m[1],(int)$m[3]))$dates[]=sprintf('%04d-%02d-%02d',$m[3],$month,$m[1]);}
        }
        $dates=array_values(array_unique($dates));
        if(count($dates)!==1)return null;
        return (new DateTimeImmutable($dates[0],new DateTimeZone('America/Sao_Paulo')))->modify('+1 day');
    }
}
