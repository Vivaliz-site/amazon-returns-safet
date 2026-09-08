<?php
declare(strict_types=1);

final class SvAmazonBridgeLiveness
{
    public const MAX_AUTH_AGE_SECONDS = 108000;

    /** @param array{value:string,metadata:array<string,mixed>,observed_at:?string}|null $authCursor */
    public static function evaluate(?array $authCursor, DateTimeInterface $now, bool $required): array
    {
        if(!$required){
            return ['status'=>'NOT_REQUIRED','reason'=>null,'worker_id'=>null,'observed_at'=>null,'age_seconds'=>null];
        }
        if(!is_array($authCursor)){
            return ['status'=>'DEGRADED','reason'=>'NO_BROWSER_AUTH_OBSERVATION','worker_id'=>null,'observed_at'=>null,'age_seconds'=>null];
        }
        $worker=trim((string)($authCursor['value'] ?? ''));
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
        return ['status'=>'OK','reason'=>null,'worker_id'=>$worker?:null,'observed_at'=>$seen->format(DATE_ATOM),'age_seconds'=>$age];
    }
}
