<?php
declare(strict_types=1);

final class SvAmazonOperationalHealth
{
    public static function evaluate(array $cadences,array $observations,DateTimeInterface $now,int $deadLetters): array
    {
        $blockers=[];
        if($deadLetters>0)$blockers[]='DEAD_LETTERS_PRESENT';
        $clock=DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('UTC'));
        foreach($cadences as $task=>$seconds){
            if(in_array($task,['health','policy_monitor'],true))continue;
            $cursor=$observations[$task]??null;
            $prefix='TASK_'.strtoupper($task).'_';
            if(!is_array($cursor)){$blockers[]=$prefix.'NOT_OBSERVED';continue;}
            $status=strtoupper(trim((string)($cursor['metadata']['status']??'UNKNOWN')));
            if(in_array($status,['FAILED','PARTIAL','DEGRADED','BLOCKED_CREDENTIALS','SKIPPED_DISABLED','SKIPPED_NOT_CONFIGURED'],true))$blockers[]=$prefix.$status;
            $observedAt=trim((string)($cursor['observed_at']??''));
            if($observedAt===''){$blockers[]=$prefix.'NOT_OBSERVED';continue;}
            try{$seen=(new DateTimeImmutable($observedAt,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));}
            catch(Throwable){$blockers[]=$prefix.'INVALID_OBSERVATION';continue;}
            $freshness=max(1800,(int)ceil(max(1,(int)$seconds)*1.5));
            if(max(0,$clock->getTimestamp()-$seen->getTimestamp())>$freshness)$blockers[]=$prefix.'STALE';
        }
        return ['blockers'=>array_values(array_unique($blockers))];
    }
}