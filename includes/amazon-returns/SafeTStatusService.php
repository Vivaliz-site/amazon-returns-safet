<?php
declare(strict_types=1);
require_once __DIR__.'/AmazonRequestedWait.php';

final class SvAmazonSafeTStatusService
{
    public static function readKey(int $caseId, string $safeTId, DateTimeInterface $now): string
    {
        if ($caseId < 1 || trim($safeTId) === '') throw new InvalidArgumentException('SAFE-T read key requires case and claim.');
        $ts = DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
        $bucket = intdiv($ts, 900) * 900;
        return hash('sha256', 'safe-t-read|' . $caseId . '|' . trim($safeTId) . '|' . gmdate('YmdHi', $bucket));
    }

    public static function observationKey(int $caseId, array $read, ?string $snapshotHash = null): string
    {
        $parts = [
            'safe-t-status',
            (string)$caseId,
            (string)($read['safe_t_id'] ?? ''),
            (string)($read['claim_status'] ?? 'UNKNOWN'),
            (string)($read['denied_at'] ?? ''),
            (string)($read['appeal_deadline_at'] ?? ''),
            (string)($read['decision_fingerprint'] ?? ''),
            !empty($read['appeal_submitted']) ? 'appeal-submitted' : 'no-appeal',
            !empty($read['appeal_denied']) ? 'appeal-denied' : 'appeal-not-denied',
        ];
        return hash('sha256', implode('|', $parts));
    }

    public static function nextState(string $currentState, string $claimStatus, bool $appealDenied = false): string
    {
        $claimStatus=strtoupper(trim($claimStatus));
        if(in_array($currentState,['RECOVERED','RECEIVED_OK'],true))return $currentState;
        if($claimStatus==='DENIED'){
            if(in_array($currentState,['EMAIL_REVIEW_SENT','EMAIL_REVIEW_RESPONSE_PENDING','SUPPORT_ESCALATION'],true))return $currentState;
            if(!$appealDenied && in_array($currentState,['APPEAL_SUBMITTED','APPEAL_DENIED_FINAL'],true))return $currentState;
        }
        if($claimStatus==='APPROVED' && in_array($currentState,['CREDIT_PENDING','APPEAL_APPROVED'],true))return $currentState;
        return match ($claimStatus) {
            'DENIED' => $appealDenied ? 'APPEAL_DENIED_FINAL' : 'SAFE_T_DENIED',
            'APPROVED' => $currentState === 'APPEAL_SUBMITTED' ? 'APPEAL_APPROVED' : 'SAFE_T_APPROVED',
            'INFO_REQUESTED' => 'SAFE_T_INFO_REQUESTED',
            'PENDING' => in_array($currentState, ['POLICY_REVIEW_REQUIRED','SAFE_T_ELIGIBLE','SAFE_T_READY','AWAITING_RETURN','REFUND_DETECTED'], true)
                ? 'SAFE_T_SUBMITTED' : $currentState,
            default => $currentState,
        };
    }

    public static function observationPlan(int $caseId,array $read,array $timeline): array
    {
        $observations=[];
        foreach($timeline as $event){
            if((int)($event['case_id']??0)!==$caseId || ($event['event_type']??'')!=='SAFE_T_STATUS_OBSERVED' || ($event['source']??'')!=='SELLER_CENTRAL')continue;
            $at=SvAmazonRequestedWait::timestamp($event['occurred_at']??null);
            if($at!==null && is_array($event['payload']??null))$observations[]=['rank'=>[$at->getTimestamp(),(int)($event['id']??0)],'event'=>$event];
        }
        usort($observations,static fn(array $a,array $b):int=>$b['rank']<=>$a['rank']);
        $latest=$observations[0]['event']??null;$base=self::observationKey($caseId,$read);
        if($latest!==null && self::observationKey($caseId,$latest['payload'])===$base){
            return ['append'=>false,'idempotency_key'=>(string)$latest['idempotency_key']];
        }
        return ['append'=>true,'idempotency_key'=>hash('sha256','status-observation-v2|'.$base.'|'.(int)($latest['id']??0))];
    }

    public static function projection(array $case,array $read,bool $isNew,DateTimeImmutable $now): array
    {
        $current=(string)($case['state']??'');$status=(string)($read['claim_status']??'UNKNOWN');
        $next=self::nextState($current,$status,(bool)($read['appeal_denied']??false));
        $fingerprint=trim((string)($read['decision_fingerprint']??''));$last=trim((string)($case['last_denial_fingerprint']??''));
        $repeat=(int)($case['repeated_denial_count']??0);
        if($status==='DENIED'){
            if($isNew)$repeat=self::repeatCount($last,$repeat,$fingerprint);
            if($fingerprint!=='')$last=$fingerprint;
        }
        $patch=['state'=>$next,'appeal_deadline_at'=>$status==='APPROVED'?null:($read['appeal_deadline_at']??$case['appeal_deadline_at']??null),
            'last_denial_fingerprint'=>$last!==''?$last:null,'repeated_denial_count'=>$repeat];
        if(in_array($status,['DENIED','INFO_REQUESTED'],true) && !in_array($current,['RECOVERED','RECEIVED_OK','EMAIL_REVIEW_SENT','EMAIL_REVIEW_RESPONSE_PENDING','SUPPORT_ESCALATION'],true)){
            $wait=$status==='DENIED'?SvAmazonRequestedWait::parse((string)($read['decision_text']??''),$read['denied_at']??null,$case):null;
            $patch['next_action_at']=$wait!==null?$wait['next_action_at']:$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        return $patch;
    }

    public static function repeatCount(?string $previousFingerprint, int $currentCount, ?string $newFingerprint): int
    {
        $previous = strtolower(trim((string)$previousFingerprint));
        $new = strtolower(trim((string)$newFingerprint));
        if ($previous === '' || $new === '') return max(0, $currentCount);
        return hash_equals($previous, $new) ? max(0, $currentCount) + 1 : max(0, $currentCount);
    }
}
