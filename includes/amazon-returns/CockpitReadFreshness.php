<?php
declare(strict_types=1);

/**
 * Newest proof that the system re-read a case from its external source.
 * A successful read job counts even when the status did not change, because
 * an unchanged status emits no new observation event.
 */
final class SvAmazonCockpitReadFreshness
{
    private const EVENT_TYPES=[
        'SAFE_T_STATUS_OBSERVED','SELLER_CENTRAL_ACTION_RESULT','SAFE_T_EMAIL_REVIEW_RESPONSE',
        'FINANCIAL_TRANSACTION_OBSERVED','SAFE_T_REIMBURSEMENT_OBSERVED',
    ];
    private const READ_JOBS=['SAFE_T_READ','SAFE_T_DISCOVERY','SELLER_SUPPORT_READ','ORDER_DELIVERY_READ'];

    /**
     * @param list<array<string,mixed>> $events
     * @param list<array<string,mixed>> $outbox
     * @return array{id:int,event_type:string,occurred_at:?string,source:?string}|null
     */
    public static function latest(array $events,array $outbox): ?array
    {
        $best=null;$bestAt=null;
        foreach($events as $event){
            if(!is_array($event)||!in_array((string)($event['event_type']??''),self::EVENT_TYPES,true))continue;
            $at=self::utc($event['occurred_at']??null);
            if($at===null||($bestAt!==null&&$at<=$bestAt))continue;
            $bestAt=$at;
            $best=['id'=>(int)($event['id']??0),'event_type'=>(string)$event['event_type'],
                'occurred_at'=>(string)$event['occurred_at'],'source'=>isset($event['source'])?(string)$event['source']:null];
        }
        foreach($outbox as $row){
            if(!is_array($row))continue;
            $kind=strtoupper(trim((string)($row['kind']??'')));
            if(!in_array($kind,self::READ_JOBS,true)||strtoupper(trim((string)($row['status']??'')))!=='SUCCEEDED')continue;
            $raw=$row['updated_at']??($row['created_at']??null);
            $at=self::utc($raw);
            if($at===null||($bestAt!==null&&$at<=$bestAt))continue;
            $bestAt=$at;
            $best=['id'=>(int)($row['id']??0),'event_type'=>$kind,'occurred_at'=>(string)$raw,'source'=>'OUTBOX_READ'];
        }
        return $best;
    }

    private static function utc(mixed $value): ?int
    {
        $text=trim((string)$value);
        if($text===''||preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/D',$text)!==1)return null;
        $normalized=str_replace('T',' ',$text);
        $parsed=DateTimeImmutable::createFromFormat('Y-m-d H:i:s',$normalized,new DateTimeZone('UTC'));
        // createFromFormat() silently rolls over impossible dates (month 13, hour 99); reject them.
        return $parsed instanceof DateTimeImmutable&&$parsed->format('Y-m-d H:i:s')===$normalized?$parsed->getTimestamp():null;
    }
}
