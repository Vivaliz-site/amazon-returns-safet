<?php
declare(strict_types=1);

require_once __DIR__.'/Enums.php';
require_once __DIR__.'/AmazonRequestedWait.php';

final class SvAmazonReviewContext
{
    /** @return array<string,mixed> */
    public static function build(array $case,array $timeline,array $policy,array $baseDecision): array
    {
        $wait=self::waitInfo($case,$timeline);
        $financial=self::financialPosture($case);
        $conflict=self::materialConflict($case,$timeline,$financial);
        $signature=[
            'review_reason'=>self::token($baseDecision['reason']??null,128),
            'marketplace'=>self::marketplace($case['marketplace_id']??null),
            'program'=>self::program($case['program']??null),
            'lifecycle'=>self::lifecycle($case),
            'physical'=>self::physical($case['physical_status']??null),
            'refund_initiator'=>self::refundInitiator($case['refund_initiator']??null),
            'financial'=>$financial,
            'evidence_pattern'=>self::evidencePattern($timeline,$baseDecision),
            'wait_condition'=>$wait['category'],
            'appeal_window'=>self::appealPosture($case,$baseDecision),
            'damaged_manual_opening'=>self::damagedManualOpening($case),
            'material_conflict'=>$conflict,
        ];
        $signature=self::canonical($signature);
        $outstanding=self::outstandingAmount($case);
        $facts=[
            'case_id'=>(int)($case['id']??0),
            'order_id'=>self::nullableScalar($case['amazon_order_id']??null),
            'safe_t_id'=>self::nullableScalar($case['safe_t_id']??null),
            'state'=>self::nullableScalar($case['state']??null),
            'marketplace_id'=>self::nullableScalar($case['marketplace_id']??null),
            'program'=>self::nullableScalar($case['program']??null),
            'physical_status'=>self::nullableScalar($case['physical_status']??null),
            'refund_initiator'=>self::nullableScalar($case['refund_initiator']??null),
            'refund_at'=>self::dateValue($case['refund_at']??null),
            'eligibility_at'=>self::dateValue($case['eligibility_at']??($policy['eligibility_at']??null)),
            'appeal_deadline_at'=>self::dateValue($case['appeal_deadline_at']??null),
            'promised_date'=>$wait['promised_date'],
            'expected_reimbursement_amount'=>self::moneyValue($case['expected_reimbursement_amount']??null),
            'reconciled_credit_amount'=>self::moneyValue($case['reconciled_credit_amount']??null),
            'outstanding_amount'=>$outstanding,
            'policy_eligible'=>(bool)($policy['eligible']??false),
            'policy_state'=>self::nullableScalar($policy['state']??null),
            'policy_version_id'=>isset($policy['policy_version_id'])?(int)$policy['policy_version_id']:null,
            'material_conflict'=>$conflict,
            'has_unknown_material_fact'=>self::hasUnknown($signature),
        ];
        $variables=[
            'ORDER_ID'=>$facts['order_id'],'SAFE_T_ID'=>$facts['safe_t_id'],
            'PROMISED_DATE'=>$wait['promised_date'],'APPEAL_DEADLINE'=>$facts['appeal_deadline_at'],
            'OUTSTANDING_AMOUNT'=>$outstanding,'REFUND_AT'=>$facts['refund_at'],
        ];
        return [
            'facts'=>$facts,
            'signature'=>$signature,
            'signature_hash'=>hash('sha256',json_encode($signature,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
            'variables'=>$variables,
            'evidence_refs'=>self::evidenceRefs($timeline),
            'review_reason'=>$signature['review_reason'],
        ];
    }

    private static function lifecycle(array $case): string
    {
        if(trim((string)($case['safe_t_id']??''))==='')return 'NONE';
        $state=strtoupper(trim((string)($case['state']??'')));
        return match($state){
            SvAmazonReturnStates::SAFE_T_SUBMITTED=>'SUBMITTED',
            SvAmazonReturnStates::SAFE_T_DENIED,SvAmazonReturnStates::SAFE_T_INFO_REQUESTED,
            SvAmazonReturnStates::APPEAL_REQUIRED,SvAmazonReturnStates::APPEAL_DENIED_FINAL,
            SvAmazonReturnStates::EMAIL_REVIEW_SENT,SvAmazonReturnStates::EMAIL_REVIEW_RESPONSE_PENDING,
            SvAmazonReturnStates::SUPPORT_ESCALATION=>'DENIED',
            SvAmazonReturnStates::APPEAL_SUBMITTED=>'APPEAL_SUBMITTED',
            SvAmazonReturnStates::SAFE_T_APPROVED,SvAmazonReturnStates::APPEAL_APPROVED=>'APPROVED',
            SvAmazonReturnStates::CREDIT_PENDING=>'CREDIT_PENDING',
            SvAmazonReturnStates::RECOVERED,SvAmazonReturnStates::CLOSED_LOSS=>'TERMINAL',
            default=>'UNKNOWN',
        };
    }
    private static function program(mixed $value): string
    {
        $value=strtoupper(trim(is_scalar($value)?(string)$value:''));
        return in_array($value,SvAmazonReturnPrograms::all(),true)?$value:SvAmazonReturnPrograms::UNKNOWN;
    }

    private static function physical(mixed $value): string
    {
        $value=strtoupper(trim(is_scalar($value)?(string)$value:''));
        return in_array($value,SvAmazonReturnPhysicalStatuses::all(),true)?$value:'UNKNOWN';
    }

    private static function refundInitiator(mixed $value): string
    {
        $value=strtoupper(trim(is_scalar($value)?(string)$value:''));
        return SvAmazonRefundInitiators::isValid($value)?$value:SvAmazonRefundInitiators::UNKNOWN;
    }

    private static function marketplace(mixed $value): string
    {
        $value=strtoupper(trim(is_scalar($value)?(string)$value:''));
        return $value!=='' && strlen($value)<=32 && preg_match('/^[A-Z0-9_-]+$/D',$value)===1?$value:'UNKNOWN';
    }

    private static function financialPosture(array $case): string
    {
        $expected=$case['expected_reimbursement_amount']??null;
        $credited=$case['reconciled_credit_amount']??null;
        if(!is_numeric($expected) || !is_numeric($credited) || (float)$credited<0)return 'UNKNOWN';
        $expected=(float)$expected;$credited=(float)$credited;
        if($expected<=0)return 'AMOUNT_UNRESOLVED';
        if($credited<=0.00001)return 'NO_CREDIT';
        return $credited+0.00001 >= $expected?'FULL_CREDIT':'PARTIAL_CREDIT';
    }
    private static function evidencePattern(array $timeline,array $baseDecision): string
    {
        $candidates=[];
        foreach($timeline as $event){
            if(!is_array($event))continue;
            $pattern=self::classifyEvent($event);
            if($pattern===null)continue;
            $candidates[]=['rank'=>self::rank($event),'pattern'=>$pattern,
                'type'=>strtoupper((string)($event['event_type']??''))];
        }
        if($candidates===[])return 'NONE';
        $preferred=self::preferredEvidenceTypes(self::token($baseDecision['reason']??null,128));
        $pool=$preferred===[]?$candidates:array_values(array_filter(
            $candidates,static fn(array $row):bool=>in_array($row['type'],$preferred,true)
        ));
        if($pool===[])$pool=$candidates;
        usort($pool,static fn(array $a,array $b):int=>$a['rank']<=>$b['rank']);
        return $pool[count($pool)-1]['pattern'];
    }

    /** @return list<string> */
    private static function preferredEvidenceTypes(string $reason): array
    {
        foreach(['FINANC','CREDIT','REIMBURSEMENT_AMOUNT'] as $word){
            if(str_contains($reason,$word))return ['FINANCIAL_RECONCILIATION_CHECKED','FINANCIAL_RECONCILIATION_CONFIRMED'];
        }
        foreach(['PHYSICAL','CARRIER','RETURN_TRANSPORT','DAMAG','DELIVERY','RECEIPT'] as $word){
            if(str_contains($reason,$word))return ['PHYSICAL_RECEIVED','RETURN_REPORT_OBSERVED','RETURN_TRANSPORT_OBSERVED'];
        }
        foreach(['PROMIS','WAIT','APPEAL','DENIAL','SAFE_T','EMAIL','AMAZON_'] as $word){
            if(str_contains($reason,$word))return ['SAFE_T_STATUS_OBSERVED','SAFE_T_EMAIL_REVIEW_RESPONSE','SELLER_CENTRAL_ACTION_RESULT'];
        }
        return [];
    }

    private static function classifyEvent(array $event): ?string
    {
        $type=strtoupper(trim((string)($event['event_type']??'')));
        $payload=is_array($event['payload']??null)?$event['payload']:[];
        if($type==='SAFE_T_STATUS_OBSERVED'){
            $status=strtoupper(trim((string)($payload['claim_status']??'')));
            return in_array($status,['APPROVED','DENIED','INFO_REQUESTED','PENDING'],true)
                ? 'SAFE_T_'.$status : 'SAFE_T_STATUS_UNKNOWN';
        }
        if($type==='SAFE_T_EMAIL_REVIEW_RESPONSE'){
            $outcome=self::token($payload['review_outcome']??null,48);
            return $outcome==='UNKNOWN'?'EMAIL_REVIEW_UNKNOWN':'EMAIL_REVIEW_'.$outcome;
        }
        if($type==='PHYSICAL_RECEIVED')return 'WAREHOUSE_RECEIPT';
        if(in_array($type,['RETURN_REPORT_OBSERVED','RETURN_TRANSPORT_OBSERVED'],true))return 'RETURN_TRANSPORT';
        if(in_array($type,['FINANCIAL_RECONCILIATION_CHECKED','FINANCIAL_RECONCILIATION_CONFIRMED'],true))return 'FINANCIAL_RECONCILIATION';
        if($type==='SELLER_CENTRAL_ACTION_RESULT' && strtoupper((string)($payload['status']??''))==='BLOCKED_UNTIL')return 'SELLER_CENTRAL_BLOCK';
        return null;
    }
    /** @return array{category:string,promised_date:?string} */
    private static function waitInfo(array $case,array $timeline): array
    {
        $latest=SvAmazonRequestedWait::latest($case,$timeline);
        $wait=is_array($latest['wait']??null)?$latest['wait']:null;
        if($wait===null)return ['category'=>'NONE','promised_date'=>null];
        $date=self::dateValue($wait['next_action_at']??null);
        return $date===null
            ? ['category'=>'UNRESOLVED_DATE','promised_date'=>null]
            : ['category'=>'EXPLICIT_DATE','promised_date'=>$date];
    }

    private static function appealPosture(array $case,array $baseDecision): string
    {
        if(trim((string)($case['safe_t_id']??''))==='')return 'NOT_APPLICABLE';
        $reason=strtoupper(trim((string)($baseDecision['reason']??'')));
        if(str_contains($reason,'APPEAL_WINDOW_EXPIRED') || str_contains($reason,'OFFICIAL_APPEAL_WINDOW_EXPIRED'))return 'EXPIRED';
        return self::dateValue($case['appeal_deadline_at']??null)!==null?'DEADLINE_KNOWN':'UNRESOLVED';
    }

    private static function damagedManualOpening(array $case): string
    {
        if(self::physical($case['physical_status']??null)!==SvAmazonReturnPhysicalStatuses::RECEIVED_DISCREPANT)return 'NOT_APPLICABLE';
        return trim((string)($case['safe_t_id']??''))===''?'REQUIRED_NOT_OPENED':'OPENED';
    }

    private static function materialConflict(array $case,array $timeline,string $financial): bool
    {
        if(($case['state']??'')===SvAmazonReturnStates::RECOVERED && $financial!=='FULL_CREDIT')return true;
        $casePhysical=self::physical($case['physical_status']??null);
        $observedPhysical=self::latestPhysicalObservation($timeline);
        if($casePhysical!=='UNKNOWN' && $observedPhysical!==null && $casePhysical!==$observedPhysical)return true;
        return self::safeTIdConflict($case,$timeline) || self::refundInitiatorConflict($case,$timeline);
    }
    private static function latestPhysicalObservation(array $timeline): ?string
    {
        $latest=null;
        foreach($timeline as $event){
            if(!is_array($event) || strtoupper((string)($event['event_type']??''))!=='PHYSICAL_RECEIVED'
                || strtoupper((string)($event['source']??''))!=='WAREHOUSE')continue;
            $payload=is_array($event['payload']??null)?$event['payload']:[];
            $physical=self::physical($payload['physical_status']??null);
            if($physical==='UNKNOWN'){
                $condition=strtoupper(trim((string)($payload['condition']??'')));
                if($condition==='OK')$physical=SvAmazonReturnPhysicalStatuses::RECEIVED_OK;
                elseif(in_array($condition,['DAMAGED','USED','WRONG_ITEM','INCOMPLETE','EMPTY_PACKAGE'],true))$physical=SvAmazonReturnPhysicalStatuses::RECEIVED_DISCREPANT;
                else continue;
            }
            $candidate=['rank'=>self::rank($event),'physical'=>$physical];
            if($latest===null || $candidate['rank']>$latest['rank'])$latest=$candidate;
        }
        return $latest['physical']??null;
    }

    private static function safeTIdConflict(array $case,array $timeline): bool
    {
        $expected=trim((string)($case['safe_t_id']??''));
        if($expected==='')return false;
        $latest=null;
        foreach($timeline as $event){
            if(!is_array($event) || ($event['event_type']??'')!=='SAFE_T_STATUS_OBSERVED')continue;
            $payload=is_array($event['payload']??null)?$event['payload']:[];
            $id=trim((string)($payload['safe_t_id']??''));
            if($id==='')continue;
            $candidate=['rank'=>self::rank($event),'id'=>$id];
            if($latest===null || $candidate['rank']>$latest['rank'])$latest=$candidate;
        }
        return $latest!==null && $latest['id']!==$expected;
    }
    private static function refundInitiatorConflict(array $case,array $timeline): bool
    {
        $expected=self::refundInitiator($case['refund_initiator']??null);
        if($expected===SvAmazonRefundInitiators::UNKNOWN)return false;
        $latest=null;
        foreach($timeline as $event){
            if(!is_array($event))continue;
            $source=strtoupper((string)($event['source']??''));
            if(!in_array($source,['SP_API_ORDERS','SP_API_FINANCES','SELLER_CENTRAL'],true))continue;
            $payload=is_array($event['payload']??null)?$event['payload']:[];
            $initiator=self::refundInitiator($payload['refund_initiator']??null);
            if($initiator===SvAmazonRefundInitiators::UNKNOWN)continue;
            $candidate=['rank'=>self::rank($event),'initiator'=>$initiator];
            if($latest===null || $candidate['rank']>$latest['rank'])$latest=$candidate;
        }
        return $latest!==null && $latest['initiator']!==$expected;
    }

    /** @return list<array<string,mixed>> */
    private static function evidenceRefs(array $timeline): array
    {
        $refs=[];
        foreach($timeline as $event){
            if(!is_array($event) || self::classifyEvent($event)===null)continue;
            $id=(int)($event['id']??0);
            if($id<1)continue;
            $ref=['event_id'=>$id,'event_type'=>(string)($event['event_type']??'')];
            $sha=strtolower(trim((string)($event['evidence_sha256']??'')));
            if(preg_match('/^[a-f0-9]{64}$/D',$sha)===1)$ref['evidence_sha256']=$sha;
            $refs[]=['rank'=>self::rank($event),'ref'=>$ref];
        }
        usort($refs,static fn(array $a,array $b):int=>$a['rank']<=>$b['rank']);
        return array_values(array_map(static fn(array $row):array=>$row['ref'],array_slice($refs,-50)));
    }
    /** @return array{0:int,1:int} */
    private static function rank(array $event): array
    {
        $date=SvAmazonRequestedWait::timestamp($event['occurred_at']??null);
        return [$date?->getTimestamp()??0,(int)($event['id']??0)];
    }

    private static function outstandingAmount(array $case): ?string
    {
        $expected=$case['expected_reimbursement_amount']??null;
        $credited=$case['reconciled_credit_amount']??null;
        if(!is_numeric($expected) || !is_numeric($credited) || (float)$expected<=0 || (float)$credited<0)return null;
        return number_format(max(0.0,(float)$expected-(float)$credited),2,'.','');
    }

    private static function moneyValue(mixed $value): ?string
    {
        return is_numeric($value) && (float)$value>=0?number_format((float)$value,2,'.',''):null;
    }

    private static function dateValue(mixed $value): ?string
    {
        if($value instanceof DateTimeInterface){
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $date=SvAmazonRequestedWait::timestamp($value);
        return $date?->format('Y-m-d H:i:s');
    }

    private static function nullableScalar(mixed $value): ?string
    {
        if(!is_scalar($value))return null;
        $value=trim((string)$value);
        return $value!=='' && strlen($value)<=512?$value:null;
    }
    private static function token(mixed $value,int $max=128): string
    {
        if(!is_scalar($value))return 'UNKNOWN';
        $value=strtoupper(trim((string)$value));
        return $value!=='' && strlen($value)<=$max && preg_match('/^[A-Z0-9_:-]+$/D',$value)===1?$value:'UNKNOWN';
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private static function canonical(array $value): array
    {
        foreach($value as &$item)if(is_array($item))$item=self::canonical($item);
        unset($item);
        if(!array_is_list($value))ksort($value,SORT_STRING);
        return $value;
    }

    private static function hasUnknown(array $signature): bool
    {
        foreach($signature as $value){
            if(!is_string($value))continue;
            if($value==='UNKNOWN' || str_contains($value,'UNRESOLVED') || str_ends_with($value,'_UNKNOWN'))return true;
        }
        return false;
    }
}
