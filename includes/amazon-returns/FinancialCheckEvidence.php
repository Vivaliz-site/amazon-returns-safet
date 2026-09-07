<?php
declare(strict_types=1);

/** Read/verification receipts are not transactions and never count as money. */
final class SvAmazonFinancialCheckEvidence
{
    public static function refresh(int $caseId,string $orderId,DateTimeImmutable $now): array
    {
        return self::event($caseId,'FINANCIAL_REFRESH_CONFIRMED',[
            'order_id'=>$orderId,'refresh_complete'=>true,'financial_truth'=>false,
        ],$now,hash('sha256','finance-read|'.$caseId.'|'.$orderId.'|'.$now->format(DATE_ATOM)));
    }

    public static function reconciled(int $caseId,array $events,array $result,DateTimeImmutable $now): ?array
    {
        $latest=null;$rank=[0,0];
        foreach($events as $event){
            if(($event['source']??'')!=='SP_API_FINANCES' || (int)($event['case_id']??0)!==$caseId
                || ($event['event_type']??'')!=='FINANCIAL_REFRESH_CONFIRMED')continue;
            if(trim((string)($event['occurred_at']??''))==='')continue;
            try{$at=new DateTimeImmutable((string)($event['occurred_at']??''),new DateTimeZone('UTC'));}catch(Throwable){continue;}
            $r=[$at->getTimestamp(),(int)($event['id']??0)];
            if($r>$rank){$latest=$event;$rank=$r;}
        }
        if($latest===null || ($latest['payload']['refresh_complete']??false)!==true
            || $rank[0]<$now->getTimestamp()-7200 || $rank[0]>$now->getTimestamp())return null;
        foreach(['credit_amount','outstanding_amount'] as $field){
            if(!is_string($result[$field]??null) || preg_match('/^[0-9]+\.[0-9]{2}$/D',$result[$field])!==1)throw new UnexpectedValueException('Invalid financial verification amount.');
        }
        $tolerance=($result['residual_tolerance_applied']??false)===true;
        $tolerated=$tolerance?(string)($result['tolerated_residual_amount']??''):'0.00';
        if(preg_match('/^[0-9]+\.[0-9]{2}$/D',$tolerated)!==1)throw new UnexpectedValueException('Invalid tolerated residual amount.');
        $payload=[
            'refresh_complete'=>true,'financial_truth'=>false,
            'refresh_observed_at'=>$latest['occurred_at'],
            'credit_amount'=>$result['credit_amount'],'outstanding_amount'=>$result['outstanding_amount'],
            'residual_tolerance_applied'=>$tolerance,'tolerated_residual_amount'=>$tolerated,
            'unclassified_transactions'=>(int)($result['unclassified_transactions']??0),
        ];
        $key=hash('sha256','finance-check|'.$caseId.'|'.($latest['id']??0).'|'.json_encode($payload,JSON_THROW_ON_ERROR));
        return self::event($caseId,'FINANCIAL_RECONCILIATION_CHECKED',$payload,$now,$key);
    }

    private static function event(int $caseId,string $type,array $payload,DateTimeImmutable $now,string $key): array
    {
        if($caseId<1)throw new InvalidArgumentException('Positive financial receipt case ID required.');
        return ['case_id'=>$caseId,'event_type'=>$type,'source'=>'SP_API_FINANCES','source_event_id'=>null,
            'idempotency_key'=>$key,'occurred_at'=>$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'payload'=>$payload,'evidence_sha256'=>null];
    }
}
