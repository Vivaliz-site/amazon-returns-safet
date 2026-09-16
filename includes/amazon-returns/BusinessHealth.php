<?php
declare(strict_types=1);

final class SvAmazonBusinessHealth
{
    public static function evaluate(bool $enabled,string $mode,array $readiness,array $writeFlags,array $browserLiveness): array
    {
        if(!$enabled)return ['status'=>'FAILED','blockers'=>['AMAZON_RETURNS_DISABLED']];
        $blockers=[];
        if(strtolower(trim($mode))!=='production')$blockers[]='MODE_NOT_PRODUCTION';
        foreach(['sp_api','gmail','seller_central_bridge'] as $dependency){
            if(($readiness[$dependency]['ready']??false)!==true)$blockers[]='READINESS_'.strtoupper($dependency);
        }
        if(strtoupper((string)($browserLiveness['status']??''))==='DEGRADED'){
            $reason=strtoupper(trim((string)($browserLiveness['reason']??'UNKNOWN')));
            $blockers[]='SELLER_CENTRAL_BROWSER_'.$reason;
        }
        foreach(['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE','ERP_SALES_RETURN_CREATE'] as $action){
            if(($writeFlags[$action]??false)!==true)$blockers[]='WRITE_GATE_'.$action.'_DISABLED';
        }
        $blockers=array_values(array_unique($blockers));
        return ['status'=>$blockers===[]?'OK':'DEGRADED','blockers'=>$blockers];
    }
}