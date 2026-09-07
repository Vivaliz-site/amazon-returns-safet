<?php
declare(strict_types=1);

/** An Amazon response date, not an alternative first-opening policy. */
final class SvAmazonRequestedWait
{
    public static function parse(string $text, ?string $responseAt = null, array $context = []): ?array
    {
        $text = preg_split('/(?m)^\s*(?:>|On .+wrote:|Em .+escreveu:|De: |From: |-----Original Message)/u', $text, 2)[0];
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text) ?? $text;
        $normalized = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text);
        $normalized = preg_replace('/[ \t]+/', ' ', $normalized) ?? $normalized;
        $intent = preg_match('/\b(?:aguard(?:e|ar)|espere|retorne|volte|reabr(?:a|ir))\b|\b(?:reembolsad[oa]|ressarcid[oa]).{0,100}(?:proativ|automatic)|\b(?:reembolso|ressarcimento).{0,100}\bate\b/s', $normalized) === 1;
        if (!$intent || preg_match('/nao (?:precisa|deve|e necessario).{0,25}\b(?:aguardar|esperar)\b/', $normalized)) return null;
        $result = ['next_action_at'=>null, 'instruction_hash'=>hash('sha256', trim($normalized)), 'date_precision'=>'unknown'];
        if (str_contains($normalized, 'nao responderemos a outras comunicacoes') || str_contains($normalized, 'nao sera reconsiderada')) return $result;
        $weekday='(?:(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)\s*,?\s*)?';
        $englishMonth='(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)';
        $date = '(?:[0-9]{4}-[0-9]{2}-[0-9]{2}|[0-9]{1,2}[\/.][0-9]{1,2}[\/.][0-9]{4}|[0-9]{1,2}\s+de\s+[a-z]+\s+de\s+[0-9]{4}|[0-9]{1,2}\s+'.$englishMonth.'\s+[0-9]{4}|'.$weekday.$englishMonth.'\s+[0-9]{1,2}\s*,?\s*[0-9]{4})';
        $waitVerb='(?:aguarde|aguardar|espere|retorne|volte|reabra|reabrir)';
        $futureMoney='(?:(?:sera|serao)\s+(?:reembolsad[oa]|ressarcid[oa])|(?:reembolso|ressarcimento)\s+(?:sera|serao|previst[oa]))';
        $pattern = '/\b(?:'.$waitVerb.'|'.$futureMoney.')\b(?:(?!\b(?:foi|foram|ocorreu|ocorreram|ja|e|mas|porem)\b)[^.,;!?\n]){0,160}?\b(ate|a partir de|apos|depois de|em|para)\s+(?:o dia\s+|dia\s+)?('.$date.')\b/';
        preg_match_all($pattern, $normalized, $matches, PREG_SET_ORDER);
        $dates = [];
        foreach ($matches as $match) {
            $day = self::calendarDate($match[2]);
            if ($day === null) return $result;
            if (in_array($match[1], ['apos','depois de'], true)) $day = $day->modify('+1 day');
            $dates[$day->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')] = true;
        }
        if ($dates === [] && preg_match('/\b(?:aguarde|aguardar|espere)\s+([0-9]{1,3})\s+dias(?!\s+uteis)/', $normalized, $relative)) {
            $anchor=$responseAt;
            if(preg_match('/(?:apos|desde|a partir d[oa])\s+(?:o\s+)?(reembolso|debito)/',$normalized,$basis)){
                $anchor=$basis[1]==='debito' ? ($context['seller_debit_at']??null) : ($context['refund_at']??null);
            }
            $start = self::timestamp($anchor);
            $days = (int)$relative[1];
            if ($start !== null && $days > 0 && $days <= 365) $dates[$start->modify('+'.$days.' days')->format('Y-m-d H:i:s')] = true;
        }
        if (count($dates) === 1) { $result['next_action_at'] = array_key_first($dates); $result['date_precision'] = 'explicit'; }
        return $result;
    }

    private static function calendarDate(string $raw): ?DateTimeImmutable
    {
        $monthNames = ['janeiro'=>1,'fevereiro'=>2,'marco'=>3,'abril'=>4,'maio'=>5,'junho'=>6,'julho'=>7,'agosto'=>8,'setembro'=>9,'outubro'=>10,'novembro'=>11,'dezembro'=>12];
        $englishMonths = ['jan'=>1,'january'=>1,'feb'=>2,'february'=>2,'mar'=>3,'march'=>3,'apr'=>4,'april'=>4,'may'=>5,'jun'=>6,'june'=>6,'jul'=>7,'july'=>7,'aug'=>8,'august'=>8,'sep'=>9,'sept'=>9,'september'=>9,'oct'=>10,'october'=>10,'nov'=>11,'november'=>11,'dec'=>12,'december'=>12];
        $raw = preg_replace('/^(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)\s*,?\s*/', '', $raw) ?? $raw;
        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $raw, $m)) { $year=(int)$m[1];$month=(int)$m[2];$day=(int)$m[3]; }
        elseif (preg_match('/^([0-9]{1,2})[\/.]([0-9]{1,2})[\/.]([0-9]{4})$/D', $raw, $m)) { $year=(int)$m[3];$month=(int)$m[2];$day=(int)$m[1]; }
        elseif (preg_match('/^([0-9]{1,2})\s+de\s+([a-z]+)\s+de\s+([0-9]{4})$/D', $raw, $m)) { $year=(int)$m[3];$month=$monthNames[$m[2]]??0;$day=(int)$m[1]; }
        elseif (preg_match('/^([0-9]{1,2})\s+([a-z]+)\s+([0-9]{4})$/D', $raw, $m)) { $year=(int)$m[3];$month=$englishMonths[$m[2]]??0;$day=(int)$m[1]; }
        elseif (preg_match('/^([a-z]+)\s+([0-9]{1,2})\s*,?\s*([0-9]{4})$/D', $raw, $m)) { $year=(int)$m[3];$month=$englishMonths[$m[1]]??0;$day=(int)$m[2]; }
        else return null;
        if ($year < 2000 || $year > 2100 || !checkdate($month, $day, $year)) return null;
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00',$year,$month,$day), new DateTimeZone('America/Sao_Paulo'));
    }

    public static function timestamp(mixed $raw): ?DateTimeImmutable
    {
        if (!is_string($raw) || preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/D', $raw, $parts)!==1) return null;
        if (!checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1]) || (int)$parts[4]>23 || (int)$parts[5]>59 || (int)$parts[6]>59) return null;
        try { return (new DateTimeImmutable($raw,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC')); } catch (Throwable) { return null; }
    }

    public static function latest(array $case, array $timeline): ?array
    {
        $responses=[];
        foreach ($timeline as $event) {
            if (!is_array($event) || (isset($event['case_id']) && (int)$event['case_id'] !== (int)($case['id']??0))) continue;
            $type=(string)($event['event_type']??'');$source=(string)($event['source']??'');$p=$event['payload']??[];
            if (!is_array($p)) continue;
            if ($type==='SAFE_T_STATUS_OBSERVED' && $source==='SELLER_CENTRAL') {
                $text=(string)($p['decision_text']??'');$anchor=$p['denied_at']??null;
                if (in_array($p['claim_status']??'', ['APPROVED','INFO_REQUESTED','PENDING'],true)) $text='';
            } elseif ($type==='SAFE_T_EMAIL_REVIEW_RESPONSE' && $source==='GMAIL') {
                $text=(string)($p['review_excerpt']??'');$anchor=$event['occurred_at']??null;
                if (in_array($p['review_outcome']??'', ['APPROVED','INFO_REQUESTED'],true)) $text='';
            } elseif ($type==='SELLER_CENTRAL_ACTION_RESULT' && $source==='SELLER_CENTRAL' && ($p['status']??'')==='BLOCKED_UNTIL') {
                $text=(string)($p['block_reason']??$p['reason']??'');$anchor=null;
            } else continue;
            $at=self::timestamp($event['occurred_at']??null);
            if ($at===null) continue;
            $wait=self::parse($text,is_string($anchor)?$anchor:null,$case);
            if($type==='SELLER_CENTRAL_ACTION_RESULT' && $wait===null)$wait=['next_action_at'=>null,'instruction_hash'=>hash('sha256',$text),'date_precision'=>'unknown'];
            $explicit=$p['review_next_action_at']??$p['next_allowed_at']??null;
            if ($explicit!==null && self::timestamp($explicit)!==null) $wait=['next_action_at'=>self::timestamp($explicit)->format('Y-m-d H:i:s'),'instruction_hash'=>hash('sha256',$text.'|'.$explicit),'date_precision'=>'explicit'];
            $responses[]=['rank'=>[$at->getTimestamp(),(int)($event['id']??0)],'wait'=>$wait,'type'=>$type,'payload'=>$p];
        }
        usort($responses,static fn(array $a,array $b):int=>$b['rank']<=>$a['rank']);
        return $responses[0]??null;
    }

    public static function decision(array $case, array $timeline, DateTimeImmutable $now): ?array
    {
        $latest=self::latest($case,$timeline);$wait=$latest['wait']??null;
        if (!is_array($wait)) return null;
        $base=['case_id'=>(int)($case['id']??0),'next_action_at'=>$wait['next_action_at'],'wait_source_hash'=>$wait['instruction_hash']];
        $due=self::timestamp($wait['next_action_at']);
        if ($due===null) return $base+['action'=>'HUMAN_REVIEW','reason'=>'AMAZON_WAIT_DATE_UNRESOLVED'];
        $claim=trim((string)($case['safe_t_id']??''));
        if($claim!=='' && ($case['state']??'')==='APPEAL_SUBMITTED')return null;
        $scope=hash('sha256',$claim.'|'.$wait['instruction_hash'].'|'.$due->format(DATE_ATOM));
        if($claim!=='' && self::internalAppealPending($case) && $now<$due){
            if(self::alreadyResumed($case,$timeline,$scope,$now))return $base+['action'=>'WAIT','reason'=>'AMAZON_DATED_RESUMPTION_ALREADY_SENT','resume_scope'=>$scope];
            $deadline=self::timestamp($case['appeal_deadline_at']??null);
            if($deadline===null)return $base+['action'=>'HUMAN_REVIEW','reason'=>'OFFICIAL_APPEAL_DEADLINE_UNRESOLVED_DURING_AMAZON_WAIT','resume_scope'=>$scope];
            if($now>$deadline){
                if(($latest['payload']['appeal_submitted']??null)===false)return $base+['action'=>'SAFE_T_APPEAL','reason'=>'MISSED_APPEAL_WINDOW_RECOVERY_ATTEMPT','resume_scope'=>$scope,'review_scope'=>$scope,
                    'idempotency_key'=>hash('sha256','missed-appeal-recovery|'.$claim.'|'.$deadline->format(DATE_ATOM))];
                return $base+['action'=>'HUMAN_REVIEW','reason'=>'OFFICIAL_APPEAL_WINDOW_EXPIRED_DURING_AMAZON_WAIT','resume_scope'=>$scope];
            }
            if($due>=$deadline)return $base+[
                'action'=>'SAFE_T_APPEAL','reason'=>'APPEAL_DEADLINE_PREEMPTS_AMAZON_WAIT','resume_scope'=>$scope,'review_scope'=>$scope,
                'idempotency_key'=>hash('sha256','dated-resume|'.$scope),
            ];
        }
        if ($now < $due) return $base+['action'=>'WAIT','reason'=>'AMAZON_REQUESTED_WAIT'];
        if(self::alreadyResumed($case,$timeline,$scope,$now))return $base+['action'=>'WAIT','reason'=>'AMAZON_DATED_RESUMPTION_ALREADY_SENT','resume_scope'=>$scope];
        if (!self::financiallyRechecked($case,$timeline,$due,$now)) return $base+['action'=>'CHECK_FINANCES','reason'=>'AMAZON_WAIT_DATE_REACHED_RECHECK_CREDIT'];
        if ((float)($case['expected_reimbursement_amount']??0)<=0) return $base+['action'=>'HUMAN_REVIEW','reason'=>'REIMBURSEMENT_AMOUNT_UNRESOLVED'];
        if ($claim==='') return null; // Original submit path retains its original idempotency key and policy gates.
        $support=trim((string)($case['support_case_id']??''));
        $thread=trim((string)($latest['payload']['gmail_thread_id']??''));
        if ($support!=='') $action='SELLER_SUPPORT_UPDATE';
        elseif ($latest['type']==='SAFE_T_EMAIL_REVIEW_RESPONSE' && $thread!=='') $action='SAFE_T_EMAIL_REPLY';
        elseif (($case['state']??'')==='APPEAL_DENIED_FINAL' || !empty($latest['payload']['appeal_denied'])) $action='SAFE_T_EMAIL_REVIEW';
        else {
            $deadline=self::timestamp($case['appeal_deadline_at']??null);
            if ($deadline===null) return $base+['action'=>'HUMAN_REVIEW','reason'=>'RESUMPTION_APPEAL_WINDOW_UNRESOLVED','resume_scope'=>$scope];
            if ($now>$deadline) {
                if(($latest['payload']['appeal_submitted']??null)===false)return $base+['action'=>'SAFE_T_APPEAL','reason'=>'MISSED_APPEAL_WINDOW_RECOVERY_ATTEMPT','resume_scope'=>$scope,'review_scope'=>$scope,
                    'idempotency_key'=>hash('sha256','missed-appeal-recovery|'.$claim.'|'.$deadline->format(DATE_ATOM))];
                return $base+['action'=>'HUMAN_REVIEW','reason'=>'INTERNAL_APPEAL_WINDOW_MISSED_REQUIRES_REVIEW','resume_scope'=>$scope];
            }
            $action='SAFE_T_APPEAL';
        }
        $scope=hash('sha256',$claim.'|'.$wait['instruction_hash'].'|'.$due->format(DATE_ATOM));
        return $base+['action'=>$action,'reason'=>'AMAZON_REQUESTED_DATE_REACHED_UNRECOVERED','resume_scope'=>$scope,'review_scope'=>$scope,
            'support_case_id'=>$support!==''?$support:null,'idempotency_key'=>hash('sha256','dated-resume|'.$scope)];
    }

    private static function internalAppealPending(array $case): bool
    {
        return in_array((string)($case['state']??''),['SAFE_T_DENIED','APPEAL_REQUIRED','SAFE_T_INFO_REQUESTED'],true);
    }

    private static function alreadyResumed(array $case,array $timeline,string $scope,DateTimeImmutable $now): bool
    {
        foreach($timeline as $event){
            if((int)($event['case_id']??0)!==(int)($case['id']??0))continue;
            $at=self::timestamp($event['occurred_at']??null);
            if($at===null || $at>$now)continue;
            $p=$event['payload']??[];
            if(($p['resume_scope']??'')!==$scope)continue;
            $type=$event['event_type']??'';$source=$event['source']??'';
            if($type==='SELLER_CENTRAL_ACTION_RESULT' && $source==='SELLER_CENTRAL' && in_array($p['status']??'',['ACCEPTED','ALREADY_EXISTS'],true))return true;
            if(in_array($type,['SAFE_T_EMAIL_REPLY_SENT','SAFE_T_EMAIL_REVIEW_SENT'],true) && $source==='GMAIL')return true;
        }
        return false;
    }

    public static function jobResumeScope(array $row): ?string
    {
        $scope=$row['payload']['decision']['resume_scope']??null;
        return is_string($scope) && preg_match('/^[a-f0-9]{64}$/D',$scope)===1 ? $scope : null;
    }

    public static function financiallyRechecked(array $case, array $timeline, DateTimeImmutable $due, DateTimeImmutable $now): bool
    {
        require_once __DIR__.'/FinancialRevalidation.php';
        $source=SvAmazonFinancialRevalidation::latestSource($timeline,(int)($case['id']??0));
        if($source===null || ($source['payload']['refresh_complete']??false)!==true)return false;
        foreach (array_reverse($timeline) as $event) {
            if (($event['event_type']??'')!=='FINANCIAL_RECONCILIATION_CONFIRMED' || ($event['source']??'')!=='SP_API_FINANCES') continue;
            if ((int)($event['case_id']??0)!==(int)($case['id']??0)) continue;
            $p=$event['payload']??[];
            if((int)($p['source_observation_id']??0)!==(int)($source['id']??-1) || ($p['source_refreshed_at']??null)!==$source['occurred_at'])continue;
            $checked=self::timestamp($event['occurred_at']??null);
            $refreshed=self::timestamp($p['source_refreshed_at']??null);
            if (($p['refresh_complete']??false)!==true || $checked===null || $refreshed===null) continue;
            if ($refreshed < $due || $checked < $refreshed || $checked > $now || $now->getTimestamp()-$refreshed->getTimestamp()>3600) continue;
            if ((string)($p['credit_amount']??'')!==(string)($case['reconciled_credit_amount']??'')) continue;
            return true;
        }
        return false;
    }
}
