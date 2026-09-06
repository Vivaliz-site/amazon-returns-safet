<?php
declare(strict_types=1);

final class SvAmazonCockpitTimeline
{
    private const WRITE_ACTIONS=[
        'SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY',
        'SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE',
    ];
    private const EVENT_FIELDS=[
        'action','status','submitted','external_id','reason','block_reason','next_allowed_at',
        'outbox_id','write_content_sha256','claim_status','safe_t_id','order_id','decision_text',
        'decision_fingerprint','financial_status','refund_amount','reimbursement_amount','outcome',
        'review_outcome','review_suggested_action','review_excerpt','promised_date','deadline_at',
        'appeal_deadline_at','state','physical_status','resume_scope','gmail_message_id','gmail_thread_id',
    ];
    private const SECRET_KEYS=[
        'access_token','refresh_token','client_secret','password','cookie','authorization','mfa','otp',
    ];

    /** @return list<array<string,mixed>> */
    public static function project(array $case,array $events,array $evidence,array $outbox,array $reviews,array $ruleApplications):array
    {
        $caseId=(int)($case['id']??0);
        if($caseId<1)throw new InvalidArgumentException('Cockpit timeline requires a positive case id.');
        $items=[];
        foreach($events as $event){
            if(!is_array($event) || !self::belongs($event,$caseId))continue;
            $payload=is_array($event['payload']??null)?$event['payload']:[];
            $category=self::eventCategory((string)($event['event_type']??''),(string)($event['source']??''));
            $items[]=self::item(
                'event:'.(string)($event['id']??self::fallbackId($event)),
                self::date($event['occurred_at']??$event['created_at']??null),
                $category,
                self::eventTitle((string)($event['event_type']??'')) ,
                strtoupper(trim((string)($event['source']??'SYSTEM'))),
                self::eventStatus($payload,(string)($event['event_type']??'')),
                self::safeSubset($payload,self::EVENT_FIELDS),
                self::evidenceRefs($event),
                null
            );
        }

        foreach($evidence as $row){
            if(!is_array($row) || !self::belongs($row,$caseId))continue;
            $content=[];
            foreach(['kind','external_id','content_sha256','storage_ref'] as $field){
                if(array_key_exists($field,$row))$content[$field]=self::safeValue($row[$field]);
            }
            if(is_array($row['metadata']??null))$content['metadata']=self::sanitize($row['metadata']);
            $items[]=self::item(
                'evidence:'.(string)($row['id']??self::fallbackId($row)),
                self::date($row['captured_at']??$row['created_at']??null),
                'OBSERVATION','Evidência registrada',strtoupper(trim((string)($row['source']??'SYSTEM'))),
                (string)($row['kind']??'CAPTURED'),$content,self::evidenceRefs($row),null
            );
        }
        foreach($outbox as $row){
            if(!is_array($row) || !self::belongs($row,$caseId))continue;
            $kind=strtoupper(trim((string)($row['kind']??'')));
            $status=strtoupper(trim((string)($row['status']??'')));
            $isWrite=in_array($kind,self::WRITE_ACTIONS,true);
            $category=$status==='DEAD_LETTER'?'ERROR':($isWrite?'EXTERNAL_WRITE':'OBSERVATION');
            $payload=is_array($row['payload']??null)?$row['payload']:[];
            $content=['action'=>$kind,'attempt_count'=>(int)($row['attempt_count']??0)];
            if(trim((string)($row['last_error']??''))!=='')$content['error']=self::safeText((string)$row['last_error']);
            if($isWrite){
                $snapshot=is_array($payload['write_snapshot']??null)?$payload['write_snapshot']:[];
                if((int)($snapshot['format_version']??0)===2){
                    $content['format_version']=2;
                    $content['channel']=self::safeText((string)($snapshot['channel']??''));
                    if(is_string($snapshot['narrative']??null))$content['narrative']=$snapshot['narrative'];
                    elseif(is_array($snapshot['message']??null))$content['message']=self::safeMessage($snapshot['message']);
                    else $content['narrative_status']='INVALID_WRITE_SNAPSHOT';
                    if(self::sha($snapshot['content_sha256']??null)!==null)$content['content_sha256']=strtolower((string)$snapshot['content_sha256']);
                }else{
                    $content['narrative_status']='MISSING_HISTORICAL_SNAPSHOT';
                }
            }
            $items[]=self::item(
                'outbox:'.(string)($row['id']??self::fallbackId($row)),
                self::date($row['created_at']??$row['available_at']??null),
                $category,self::outboxTitle($kind,$status),self::outboxSource($payload),
                $status,$content,[],null
            );
        }
        foreach($reviews as $row){
            if(!is_array($row) || !self::belongs($row,$caseId))continue;
            $content=[];
            foreach(['reason','version','decision_mode','actor','source_version','ai_provider','ai_model'] as $field){
                if(array_key_exists($field,$row))$content[$field]=self::safeValue($row[$field]);
            }
            foreach(['context','ai_suggestion','human_decision','evidence_refs','affected_case_preview'] as $field){
                if(is_array($row[$field]??null))$content[$field]=self::sanitize($row[$field]);
            }
            $status=strtoupper(trim((string)($row['status']??'OPEN')));
            $items[]=self::item(
                'review:'.(string)($row['id']??self::fallbackId($row)),
                self::date($row['decided_at']??$row['created_at']??null),
                'DECISION',$status==='OPEN'?'Revisão pendente':'Decisão humana registrada','SYSTEM',
                $status,$content,self::arrayRefs($row['evidence_refs']??[]),null
            );
        }

        foreach($ruleApplications as $row){
            if(!is_array($row) || !self::belongs($row,$caseId))continue;
            $content=[];
            foreach(['rule_id','rule_version','result','action_ref','outcome','signature_hash','effect_hash'] as $field){
                if(array_key_exists($field,$row))$content[$field]=self::safeValue($row[$field]);
            }
            if(is_array($row['blockers']??null))$content['blockers']=self::sanitize($row['blockers']);
            if(is_array($row['outcome_evidence_refs']??null))$content['outcome_evidence_refs']=self::sanitize($row['outcome_evidence_refs']);
            $ruleId=(int)($row['rule_id']??0);
            $ruleVersion=(int)($row['rule_version']??0);
            $items[]=self::item(
                'rule-application:'.(string)($row['id']??self::fallbackId($row)),
                self::date($row['outcome_at']??$row['created_at']??null),
                'RULE','Regra aprendida aplicada','RULE_ENGINE',
                strtoupper(trim((string)($row['outcome']??$row['result']??'APPLIED'))),$content,[],
                $ruleId>0?['rule_id'=>$ruleId,'version'=>$ruleVersion]:null
            );
        }
        usort($items,static function(array $a,array $b):int{
            $date=strcmp((string)$a['occurred_at'],(string)$b['occurred_at']);
            return $date!==0?$date:strcmp((string)$a['id'],(string)$b['id']);
        });
        return $items;
    }

    /** @return array<string,mixed> */
    private static function item(string $id,string $occurredAt,string $category,string $title,string $source,?string $status,array $content,array $evidenceRefs,?array $ruleRef):array
    {
        return [
            'id'=>$id,'occurred_at'=>$occurredAt,'category'=>$category,'title'=>$title,
            'source'=>$source,'status'=>$status,'content'=>$content,
            'evidence_refs'=>$evidenceRefs,'rule_ref'=>$ruleRef,
        ];
    }

    private static function belongs(array $row,int $caseId):bool
    {
        return !array_key_exists('case_id',$row) || (int)$row['case_id']===$caseId;
    }

    private static function eventCategory(string $type,string $source):string
    {
        $type=strtoupper($type);$source=strtoupper($source);
        if($type==='SELLER_CENTRAL_ACTION_RESULT' || str_contains($type,'EMAIL_REVIEW_RESPONSE'))return 'AMAZON_RESPONSE';
        if($source==='FINANCES' || str_contains($type,'FINANC') || str_contains($type,'REIMBURSE'))return 'FINANCIAL';
        if(str_contains($type,'RULE'))return 'RULE';
        if(str_contains($type,'DECISION') || str_contains($type,'HUMAN_REVIEW'))return 'DECISION';
        if(str_contains($type,'ERROR') || str_contains($type,'FAILED') || str_contains($type,'DEAD_LETTER'))return 'ERROR';
        return 'OBSERVATION';
    }
    private static function eventTitle(string $type):string
    {
        return match(strtoupper(trim($type))){
            'SELLER_CENTRAL_ACTION_RESULT'=>'Resposta Amazon / resultado de escrita',
            'SAFE_T_EMAIL_REVIEW_RESPONSE'=>'Resposta Amazon por e-mail',
            'SAFE_T_STATUS_OBSERVED'=>'Status SAFE-T observado',
            'FINANCIAL_RECONCILIATION'=>'Conciliação financeira',
            default=>self::humanize($type),
        };
    }

    private static function eventStatus(array $payload,string $type):?string
    {
        foreach(['status','claim_status','financial_status','review_outcome','outcome','state'] as $field){
            $value=trim((string)($payload[$field]??''));
            if($value!=='')return strtoupper($value);
        }
        $type=trim($type);
        return $type===''?null:strtoupper($type);
    }

    private static function outboxTitle(string $kind,string $status):string
    {
        $base=match($kind){
            'SAFE_T_SUBMIT'=>'Abertura SAFE-T',
            'SAFE_T_APPEAL'=>'Recurso SAFE-T',
            'SAFE_T_EMAIL_REVIEW'=>'Revisão SAFE-T por e-mail',
            'SAFE_T_EMAIL_REPLY'=>'Resposta à revisão SAFE-T',
            'SELLER_SUPPORT_OPEN'=>'Abertura no Seller Support',
            'SELLER_SUPPORT_UPDATE'=>'Atualização no Seller Support',
            default=>self::humanize($kind),
        };
        return $status==='DEAD_LETTER'?$base.' — falha definitiva':$base;
    }

    private static function outboxSource(array $payload):string
    {
        $snapshot=is_array($payload['write_snapshot']??null)?$payload['write_snapshot']:[];
        return match(strtolower(trim((string)($snapshot['channel']??'')))){
            'gmail'=>'GMAIL','seller_central_bridge'=>'SELLER_CENTRAL',default=>'SYSTEM',
        };
    }
    /** @param list<string> $allowed @return array<string,mixed> */
    private static function safeSubset(array $payload,array $allowed):array
    {
        $result=[];
        foreach($allowed as $field){
            if(array_key_exists($field,$payload))$result[$field]=self::safeValue($payload[$field]);
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private static function safeMessage(array $message):array
    {
        $allowed=['to','subject','body','thread_id','in_reply_to'];
        $result=[];
        foreach($allowed as $field){
            if(array_key_exists($field,$message))$result[$field]=self::safeValue($message[$field]);
        }
        return $result;
    }

    private static function safeValue(mixed $value):mixed
    {
        if(is_array($value))return self::sanitize($value);
        if(is_bool($value) || is_int($value) || is_float($value) || $value===null)return $value;
        if(is_scalar($value))return self::safeText((string)$value);
        return null;
    }

    /** @return array<mixed> */
    private static function sanitize(array $values):array
    {
        $result=[];
        foreach($values as $key=>$value){
            if(self::secretKey((string)$key))continue;
            $result[$key]=self::safeValue($value);
        }
        return $result;
    }
    private static function secretKey(string $key):bool
    {
        $withBreaks=preg_replace('/([a-z0-9])([A-Z])/','$1_$2',$key)??$key;
        $normalized=strtolower(preg_replace('/[^a-z0-9]+/i','_',$withBreaks)??'');
        foreach(self::SECRET_KEYS as $secret){
            if($normalized===$secret || str_ends_with($normalized,'_'.$secret))return true;
        }
        return false;
    }

    private static function safeText(string $text):string
    {
        $text=preg_replace('/\bBearer\s+[^\s,;]+/i','Bearer [REDACTED]',$text)??$text;
        $text=preg_replace('/\b(access_token|refresh_token|client_secret|password|cookie|authorization|mfa|otp)\b\s*[:=]\s*[^\s,;]+/i','$1=[REDACTED]',$text)??$text;
        return function_exists('mb_substr')?mb_substr($text,0,12000,'UTF-8'):substr($text,0,12000);
    }

    /** @return list<string> */
    private static function evidenceRefs(array $row):array
    {
        $refs=[];
        foreach(['evidence_sha256','content_sha256'] as $field){
            $sha=self::sha($row[$field]??null);
            if($sha!==null)$refs[]=$sha;
        }
        return array_values(array_unique($refs));
    }

    /** @return list<mixed> */
    private static function arrayRefs(mixed $refs):array
    {
        if(!is_array($refs))return [];
        return array_values(self::sanitize($refs));
    }
    private static function sha(mixed $value):?string
    {
        if(!is_string($value))return null;
        $value=strtolower(trim($value));
        return preg_match('/^[a-f0-9]{64}$/D',$value)===1?$value:null;
    }

    private static function date(mixed $value):string
    {
        if(!is_scalar($value) || trim((string)$value)==='')return '1970-01-01 00:00:00';
        try{
            return (new DateTimeImmutable((string)$value,new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }catch(Throwable){
            return '1970-01-01 00:00:00';
        }
    }

    private static function fallbackId(array $row):string
    {
        return substr(hash('sha256',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'row'),0,16);
    }

    private static function humanize(string $value):string
    {
        $value=trim(str_replace(['_','-'],' ',$value));
        if($value==='')return 'Evento';
        return mb_convert_case(mb_strtolower($value,'UTF-8'),MB_CASE_TITLE,'UTF-8');
    }
}
