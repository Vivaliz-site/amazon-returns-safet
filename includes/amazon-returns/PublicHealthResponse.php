<?php
declare(strict_types=1);

final class SvAmazonReturnsPublicHealthResponse
{
    /** @param array<string,mixed> $runtimeHealth @return array{service:string,status:string,blockers:list<string>} */
    public static function fromRuntime(array $runtimeHealth): array
    {
        $status = strtoupper(trim((string)($runtimeHealth['status'] ?? '')));
        if (!in_array($status,['OK','DEGRADED'],true)) $status = 'FAILED';

        $blockers=[];
        $raw=$runtimeHealth['health_blockers'] ?? $runtimeHealth['blockers'] ?? [];
        if(is_array($raw)){
            foreach($raw as $value){
                if(!is_string($value))continue;
                $value=strtoupper(trim($value));
                if($value==='' || preg_match('/^[A-Z0-9_]{1,128}$/D',$value)!==1)continue;
                $blockers[$value]=true;
            }
        }
        $blockers=array_keys($blockers);
        if($status==='OK' && $blockers!==[])$status='DEGRADED';

        return [
            'service'=>'amazon-returns-safet',
            'status'=>$status,
            'blockers'=>$blockers,
        ];
    }
}
