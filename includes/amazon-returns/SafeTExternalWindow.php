<?php
declare(strict_types=1);

/**
 * Independent external SAFE-T submission clock, separate from the tenant's
 * D+45 investigation trigger. Never infer order time from refund time.
 */
final class SvAmazonSafeTExternalWindow
{
    private const CUTOFF = '2026-04-21';

    /**
     * @param array<string,mixed> $case
     * @param list<array<string,mixed>> $events
     * @return array{allowed:bool,reason:string,not_before:?string,order_at:?string}
     */
    public static function inspect(array $case,array $events,DateTimeImmutable $now): array
    {
        $program=strtoupper(trim((string)($case['program']??'')));
        if(!in_array($program,['FBA_ONSITE','DELIVERY_BY_AMAZON'],true)){
            return ['allowed'=>$program!=='' && $program!=='UNKNOWN',
                'reason'=>$program==='' || $program==='UNKNOWN'?'PROGRAM_UNVERIFIED':'OTHER_PROGRAM',
                'not_before'=>null,'order_at'=>null];
        }

        $orderDates=[];
        foreach($events as $event){
            if(!is_array($event) || (string)($event['event_type']??'')!=='ORDER_SYNCED')continue;
            $payload=$event['payload']??null;
            if(!is_array($payload))continue;
            if(!array_key_exists('order_at',$payload))continue;
            $raw=$payload['order_at'];
            if($raw===null || $raw==='')continue;
            $orderAt=self::utcDate($raw);
            if($orderAt===null){
                return ['allowed'=>false,'reason'=>'ORDER_AT_INVALID',
                    'not_before'=>null,'order_at'=>null];
            }
            $orderDates[$orderAt->format('Y-m-d H:i:s')]=$orderAt;
        }
        if(count($orderDates)!==1){
            return ['allowed'=>false,'reason'=>'ORDER_AT_UNVERIFIED_OR_CONFLICTING',
                'not_before'=>null,'order_at'=>null];
        }
        $orderAt=array_values($orderDates)[0];
        $orderDate=$orderAt->format('Y-m-d H:i:s');
        $refundAt=self::utcDate($case['refund_at']??null);
        if($refundAt===null){
            return ['allowed'=>false,'reason'=>'REFUND_AT_UNVERIFIED',
                'not_before'=>null,'order_at'=>$orderDate];
        }
        $days=$orderAt->format('Y-m-d')>=self::CUTOFF?60:45;
        $notBefore=$refundAt->add(new DateInterval('P'.$days.'D'))
            ->format('Y-m-d H:i:s');
        $nowUtc=$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return [
            'allowed'=>$nowUtc>=$notBefore,
            'reason'=>$nowUtc>=$notBefore?'EXTERNAL_WINDOW_REACHED':"D{$days}_EXTERNAL_WINDOW_PENDING",
            'not_before'=>$notBefore,
            'order_at'=>$orderDate,
        ];
    }

    private static function utcDate(mixed $value): ?DateTimeImmutable
    {
        if(!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$value))
            return null;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
        $errors=DateTimeImmutable::getLastErrors();
        return $date instanceof DateTimeImmutable
            && ($errors===false || (($errors['warning_count']??1)===0 && ($errors['error_count']??1)===0))
            ? $date : null;
    }
}
