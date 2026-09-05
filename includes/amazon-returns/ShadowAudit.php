<?php
declare(strict_types=1);

final class SvAmazonReturnsShadowAudit
{
    private const CASE_FIELDS = [
        'amazon_order_id','amazon_order_item_id','marketplace_id','sku','asin',
        'quantity_ordered','quantity_refunded','quantity_received','program',
        'refund_initiator','refund_at','seller_debit_at','refund_amount',
        'expected_reimbursement_amount','reconciled_credit_amount',
        'physical_status','state','policy_version_id','eligibility_at',
        'safe_t_id','support_case_id','repeated_denial_count',
        'last_denial_fingerprint','appeal_deadline_at','terminal_reason','closed_at',
    ];

    /** @return array<string,array{source:mixed,target:mixed}> */
    public static function caseDiff(array $source,array $target): array
    {
        $diff=[];
        foreach(self::CASE_FIELDS as $field){
            $a=self::normalize($source[$field] ?? null);
            $b=self::normalize($target[$field] ?? null);
            if($a!==$b)$diff[$field]=['source'=>$a,'target'=>$b];
        }
        return $diff;
    }

    /** @return array<string,array{source:mixed,target:mixed}> */
    public static function migrationCaseDiff(array $source,array $target,array $targetEvents=[]): array
    {
        $diff=self::caseDiff($source,$target);
        if(self::hasLegacyLifecycleRefundNormalization($source,$target)){
            unset($diff['refund_amount']);
        }
        if(self::hasAuthoritativeCreditAdvancement($source,$target,$targetEvents)){
            unset($diff['reconciled_credit_amount']);
        }
        return $diff;
    }

    public static function hasLegacyLifecycleRefundNormalization(array $source,array $target): bool
    {
        $sourceExpected=self::cents($source['expected_reimbursement_amount']??null);
        $targetExpected=self::cents($target['expected_reimbursement_amount']??null);
        $sourceRefund=self::cents($source['refund_amount']??null);
        $targetRefund=self::cents($target['refund_amount']??null);
        return $sourceExpected!==null
            && $sourceExpected>0
            && $sourceExpected===$targetExpected
            && $sourceRefund===$sourceExpected*2
            && $targetRefund===$targetExpected;
    }

    public static function hasAuthoritativeCreditAdvancement(
        array $source,array $target,array $targetEvents
    ): bool {
        $sourceExpected=self::cents($source['expected_reimbursement_amount']??null);
        $targetExpected=self::cents($target['expected_reimbursement_amount']??null);
        $sourceCredit=self::cents($source['reconciled_credit_amount']??null);
        $targetCredit=self::cents($target['reconciled_credit_amount']??null);
        if($sourceExpected===null||$sourceExpected<=0||$sourceExpected!==$targetExpected
            ||$sourceCredit===null||$targetCredit===null||$targetCredit<=$sourceCredit){
            return false;
        }
        $observed=0;
        foreach($targetEvents as $event){
            if(!is_array($event)||($event['event_type']??'')!=='SAFE_T_REIMBURSEMENT_OBSERVED')continue;
            $payload=is_array($event['payload']??null)?$event['payload']:[];
            $money=is_array($payload['reimbursed_amount']??null)?$payload['reimbursed_amount']:[];
            $amount=self::cents($money['amount']??null);
            $currency=strtoupper(trim((string)($money['currency']??'')));
            if($amount===null||$amount<=0||preg_match('/^[A-Z]{3}$/',$currency)!==1)continue;
            $observed+=$amount;
        }
        return $observed>0&&$targetCredit===$sourceCredit+$observed;
    }

    /** @return array<string,array{source:mixed,target:mixed}> */
    public static function decisionDiff(array $source,array $target): array
    {
        $diff=[];
        foreach(['action','reason'] as $field){
            $a=self::normalize($source[$field] ?? null);
            $b=self::normalize($target[$field] ?? null);
            if($a!==$b)$diff[$field]=['source'=>$a,'target'=>$b];
        }
        return $diff;
    }

    private static function normalize(mixed $value): mixed
    {
        if(is_string($value)){
            $trim=trim($value);
            if($trim==='')return null;
            if(preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/',$trim)===1){
                if(str_contains($trim,'.'))return number_format((float)$trim,2,'.','');
                return (string)(int)$trim;
            }
            return $trim;
        }
        if(is_int($value)||is_float($value))return (string)$value;
        return $value;
    }

    private static function cents(mixed $value): ?int
    {
        if((!is_string($value)&&!is_int($value)&&!is_float($value))||!is_numeric($value)){
            return null;
        }
        return (int)round((float)$value*100);
    }
}
