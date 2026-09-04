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
}
