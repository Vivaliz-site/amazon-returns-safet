<?php
declare(strict_types=1);
require_once __DIR__.'/AmazonRequestedWait.php';

/** Records observation freshness without manufacturing a financial transaction. */
final class SvAmazonFinancialRevalidation
{
    public static function sourceEvent(int $caseId, bool $complete, string $at): array
    {
        if($caseId<1 || SvAmazonRequestedWait::timestamp($at)===null)throw new InvalidArgumentException('Invalid financial refresh identity.');
        return ['case_id'=>$caseId,'event_type'=>'FINANCIAL_REFRESH_CONFIRMED','source'=>'SP_API_FINANCES','source_event_id'=>null,
            'idempotency_key'=>hash('sha256','financial-refresh|'.$caseId.'|'.$at.'|'.(int)$complete),
            'occurred_at'=>$at,'payload'=>['refresh_complete'=>$complete],'evidence_sha256'=>null];
    }

    public static function latestSource(array $events, int $caseId): ?array
    {
        $sources=[];
        foreach($events as $event){
            if(($event['event_type']??'')!=='FINANCIAL_REFRESH_CONFIRMED' || ($event['source']??'')!=='SP_API_FINANCES' || (int)($event['case_id']??0)!==$caseId)continue;
            $at=SvAmazonRequestedWait::timestamp($event['occurred_at']??null);
            if($at!==null)$sources[]=['rank'=>[$at->getTimestamp(),(int)($event['id']??0)],'event'=>$event];
        }
        usort($sources,static fn(array $a,array $b):int=>$b['rank']<=>$a['rank']);
        return $sources[0]['event']??null;
    }

    public static function confirmation(array $case,array $events,array $result,string $at): ?array
    {
        $caseId=(int)($case['id']??0);$source=self::latestSource($events,$caseId);
        if($source===null || ($source['payload']['refresh_complete']??false)!==true || (int)($source['id']??0)<1)return null;
        $refreshed=SvAmazonRequestedWait::timestamp($source['occurred_at']);$checked=SvAmazonRequestedWait::timestamp($at);
        if($checked===null || $refreshed===null || $checked<$refreshed || $checked->getTimestamp()-$refreshed->getTimestamp()>3600)return null;
        $credit=(string)($result['credit_amount']??'');$outstanding=(string)($result['outstanding_amount']??'');
        if(!preg_match('/^\d+\.\d{2}$/D',$credit) || !preg_match('/^\d+\.\d{2}$/D',$outstanding))return null;
        $tolerance=($result['residual_tolerance_applied']??false)===true;
        $tolerated=$tolerance?(string)($result['tolerated_residual_amount']??''):'0.00';
        if(!preg_match('/^\d+\.\d{2}$/D',$tolerated))return null;
        return ['case_id'=>$caseId,'event_type'=>'FINANCIAL_RECONCILIATION_CONFIRMED','source'=>'SP_API_FINANCES','source_event_id'=>null,
            'idempotency_key'=>hash('sha256','financial-recheck|'.$caseId.'|'.$source['id'].'|'.$credit.'|'.$outstanding.'|'.(int)$tolerance.'|'.$tolerated),
            'occurred_at'=>$at,'payload'=>['refresh_complete'=>true,'source_refreshed_at'=>$source['occurred_at'],
                'source_observation_id'=>(int)$source['id'],'credit_amount'=>$credit,'outstanding_amount'=>$outstanding,
                'residual_tolerance_applied'=>$tolerance,'tolerated_residual_amount'=>$tolerated],
            'evidence_sha256'=>null];
    }
}
