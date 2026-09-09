<?php
declare(strict_types=1);

final class SvAmazonBridgeLiveness
{
    public const MAX_AUTH_AGE_SECONDS = 108000;
    public const MAX_PROCESS_AGE_SECONDS = 108000;

    /**
     * @param array{value:string,metadata:array<string,mixed>,observed_at:?string}|null $authCursor
     * @param array{value:string,metadata:array<string,mixed>,observed_at:?string}|null $processCursor
     */
    public static function evaluate(?array $authCursor, DateTimeInterface $now, bool $required, ?array $processCursor=null, ?string $expectedWorkerId=null): array
    {
        if(!$required){
            return ['status'=>'NOT_REQUIRED','reason'=>null,'worker_id'=>null,'observed_at'=>null,'age_seconds'=>null];
        }
        if(!is_array($authCursor)){
            return ['status'=>'DEGRADED','reason'=>'NO_BROWSER_AUTH_OBSERVATION','worker_id'=>null,'observed_at'=>null,'age_seconds'=>null];
        }
        $worker=trim((string)($authCursor['value'] ?? ''));
        $expectedWorker=trim((string)$expectedWorkerId);
        if($expectedWorker!=='' && !hash_equals($expectedWorker,$worker)){
            return [
                'status'=>'DEGRADED','reason'=>'UNEXPECTED_BROWSER_WORKER',
                'worker_id'=>$worker?:null,'expected_worker_id'=>$expectedWorker,
            ];
        }
        $observed=trim((string)($authCursor['observed_at'] ?? ''));
        $authStatus=strtoupper(trim((string)($authCursor['metadata']['status'] ?? 'UNKNOWN')));
        if($observed===''){
            return ['status'=>'DEGRADED','reason'=>'NO_BROWSER_AUTH_OBSERVATION','worker_id'=>$worker?:null,'observed_at'=>null,'age_seconds'=>null];
        }
        try{
            $seen=(new DateTimeImmutable($observed,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
            $clock=DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('UTC'));
            $age=max(0,$clock->getTimestamp()-$seen->getTimestamp());
        }catch(Throwable){
            return ['status'=>'DEGRADED','reason'=>'INVALID_BROWSER_AUTH_OBSERVATION','worker_id'=>$worker?:null,'observed_at'=>$observed,'age_seconds'=>null];
        }
        if($age>self::MAX_AUTH_AGE_SECONDS){
            return ['status'=>'DEGRADED','reason'=>'STALE_BROWSER_AUTH','worker_id'=>$worker?:null,'observed_at'=>$seen->format(DATE_ATOM),'age_seconds'=>$age];
        }
        if($authStatus!=='AUTHENTICATED'){
            return ['status'=>'DEGRADED','reason'=>$authStatus!==''?$authStatus:'AUTH_REQUIRED','worker_id'=>$worker?:null,'observed_at'=>$seen->format(DATE_ATOM),'age_seconds'=>$age];
        }
        $base=[
            'worker_id'=>$worker?:null,
            'observed_at'=>$seen->format(DATE_ATOM),
            'age_seconds'=>$age,
        ];
        if(!is_array($processCursor)){
            return ['status'=>'DEGRADED','reason'=>'NO_READ_PROCESS_HEARTBEAT']+$base;
        }
        $processWorker=trim((string)($processCursor['value'] ?? ''));
        if($expectedWorker!=='' && !hash_equals($expectedWorker,$processWorker)){
            return [
                'status'=>'DEGRADED','reason'=>'UNEXPECTED_READ_PROCESS_WORKER',
                'process_worker_id'=>$processWorker?:null,'expected_worker_id'=>$expectedWorker,
            ]+$base;
        }
        $processObserved=trim((string)($processCursor['observed_at'] ?? ''));
        $processStatus=strtoupper(trim((string)($processCursor['metadata']['status'] ?? 'UNKNOWN')));
        if($processObserved===''){
            return ['status'=>'DEGRADED','reason'=>'NO_READ_PROCESS_HEARTBEAT','process_worker_id'=>$processWorker?:null]+$base;
        }
        try{
            $processSeen=(new DateTimeImmutable($processObserved,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
            $processAge=max(0,$clock->getTimestamp()-$processSeen->getTimestamp());
        }catch(Throwable){
            return ['status'=>'DEGRADED','reason'=>'INVALID_READ_PROCESS_HEARTBEAT','process_worker_id'=>$processWorker?:null]+$base;
        }
        $process=[
            'process_worker_id'=>$processWorker?:null,
            'process_observed_at'=>$processSeen->format(DATE_ATOM),
            'process_age_seconds'=>$processAge,
        ];
        if($processAge>self::MAX_PROCESS_AGE_SECONDS){
            return ['status'=>'DEGRADED','reason'=>'STALE_READ_PROCESS_HEARTBEAT']+$base+$process;
        }
        if($processStatus!=='ALIVE'){
            return ['status'=>'DEGRADED','reason'=>'READ_PROCESS_NOT_ALIVE']+$base+$process;
        }
        return ['status'=>'OK','reason'=>null]+$base+$process;
    }
}
