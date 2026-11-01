<?php
declare(strict_types=1);

/**
 * Owner's D+45 first-opening preflight, measured from the customer refund.
 *
 * This gate does NOT certify Amazon's external claim eligibility. The live
 * Seller Central eligibility check, write flags, readback and idempotency
 * rules must still pass before a claim can be submitted.
 */
final class SvAmazonSafeTExternalWindow
{
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

        // Order timestamp is useful for provenance but is NOT an extra delay.
        // An absent timestamp must not silently postpone an owner-approved D+45
        // opening; conflicting or malformed timestamps still fail closed.
        $orderDates=[];
        foreach($events as $event){
            if(!is_array($event) || (string)($event['event_type']??'')!=='ORDER_SYNCED')continue;
            $payload=$event['payload']??null;
            if(!is_array($payload) || !array_key_exists('order_at',$payload))continue;
            $raw=$payload['order_at'];
            if($raw===null || $raw==='')continue;
            $orderAt=self::utcDate($raw);
            if($orderAt===null){
                return ['allowed'=>false,'reason'=>'ORDER_AT_INVALID',
                    'not_before'=>null,'order_at'=>null];
            }
            $orderDates[$orderAt->format('Y-m-d H:i:s')]=$orderAt;
        }
        if(count($orderDates)>1){
            return ['allowed'=>false,'reason'=>'ORDER_AT_CONFLICTING',
                'not_before'=>null,'order_at'=>null];
        }
        $orderDate=$orderDates===[]?null:array_values($orderDates)[0]->format('Y-m-d H:i:s');
        $refundAt=self::utcDate($case['refund_at']??null);
        if($refundAt===null){
            return ['allowed'=>false,'reason'=>'REFUND_AT_UNVERIFIED',
                'not_before'=>null,'order_at'=>$orderDate];
        }
        $notBefore=$refundAt->add(new DateInterval('P45D'))->format('Y-m-d H:i:s');
        $nowUtc=$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return [
            'allowed'=>$nowUtc>=$notBefore,
            'reason'=>$nowUtc>=$notBefore?'D45_OPENING_REACHED':'D45_OPENING_PENDING',
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
