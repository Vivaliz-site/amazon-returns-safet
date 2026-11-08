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
    /** A linked external claim means this is no longer a FIRST opening. */
    public static function openingAlreadyClaimed(array $case): bool
    {
        return trim((string)($case['safe_t_id'] ?? '')) !== '';
    }

    /**
     * @param array<string,mixed> $case
     * @param list<array<string,mixed>> $events
     * @return array{allowed:bool,reason:string,not_before:?string,order_at:?string}
     */
    public static function inspect(array $case,array $events,DateTimeImmutable $now): array
    {
        // D+90 closes every FIRST-OPENING attempt, even when the program or
        // order timestamp remains unverified. Otherwise those blockers take
        // precedence over expiry and outbox defer() retries forever.
        // This is terminal bookkeeping only; it never authorizes a claim.
        $expiryRefundAt=self::utcDate($case['refund_at']??null);
        if($expiryRefundAt!==null){
            $nowUtc=$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            if($nowUtc >= $expiryRefundAt->add(new DateInterval('P90D'))->format('Y-m-d H:i:s')){
                return [
                    'allowed'=>false,
                    'reason'=>'D90_OPENING_EXPIRED',
                    'not_before'=>$expiryRefundAt->add(new DateInterval('P45D'))->format('Y-m-d H:i:s'),
                    'order_at'=>null,
                ];
            }
        }
        $program=strtoupper(trim((string)($case['program']??'')));
        if(!in_array($program,['FBA_ONSITE','DELIVERY_BY_AMAZON'],true)){
            // Do not bypass the owner D+45..D+89 limit for unrecognized
            // fulfillment programs. A standard/MFN/FBA-classic claim needs
            // separate program eligibility review, never automatic opening.
            return ['allowed'=>false,
                'reason'=>$program==='' || $program==='UNKNOWN'?'PROGRAM_UNVERIFIED':'PROGRAM_UNSUPPORTED',
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
        // D+90 is the exclusive end of the owner's D+45..D+89 opening window.
        // The external provider's narrower deadline can still deny an opening.
        $expiresAt=$refundAt->add(new DateInterval('P90D'))->format('Y-m-d H:i:s');
        $withinWindow=$nowUtc>=$notBefore && $nowUtc<$expiresAt;
        return [
            'allowed'=>$withinWindow,
            'reason'=>$nowUtc>=$expiresAt?'D90_OPENING_EXPIRED':($withinWindow?'D45_OPENING_REACHED':'D45_OPENING_PENDING'),
            'not_before'=>$notBefore,
            'order_at'=>$orderDate,
        ];
    }

    /** A D+90+ first-opening job must not be re-queued indefinitely. */
    public static function openingPermanentlyExpired(array $gate): bool
    {
        return ($gate['allowed'] ?? null) === false
            && ($gate['reason'] ?? null) === 'D90_OPENING_EXPIRED';
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
