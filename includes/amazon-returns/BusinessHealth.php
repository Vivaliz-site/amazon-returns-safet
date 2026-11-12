<?php
declare(strict_types=1);

final class SvAmazonBusinessHealth
{
    public static function evaluate(bool $enabled,string $mode,array $readiness,array $writeFlags,array $browserLiveness,array $operationalBlockers=[]): array
    {
        if(!$enabled)return ['status'=>'FAILED','blockers'=>['AMAZON_RETURNS_DISABLED']];
        $blockers=array_values(array_filter($operationalBlockers,static fn(mixed $value):bool=>is_string($value) && trim($value)!==''));
        if(strtolower(trim($mode))!=='production')$blockers[]='MODE_NOT_PRODUCTION';
        foreach(['sp_api','gmail','seller_central_bridge'] as $dependency){
            if(($readiness[$dependency]['ready']??false)!==true)$blockers[]='READINESS_'.strtoupper($dependency);
        }
        if(strtoupper((string)($browserLiveness['status']??''))==='DEGRADED'){
            $reason=strtoupper(trim((string)($browserLiveness['reason']??'UNKNOWN')));
            $blockers[]='SELLER_CENTRAL_BROWSER_'.$reason;
        }
        // Write capability flags are intentional policy controls, not operational failures.
        // Runtime::health continues to expose write_flags separately; this never enables a write.
        $blockers=array_values(array_unique($blockers));
        return ['status'=>$blockers===[]?'OK':'DEGRADED','blockers'=>$blockers];
    }
}
