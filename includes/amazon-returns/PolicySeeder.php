<?php
declare(strict_types=1);
require_once __DIR__ . '/PolicyRepository.php';

/** ShopVivaliz's operational opening policy, not Amazon's eligibility promise. */
final class SvAmazonReturnPolicySeeder
{
    public const OPERATIONAL_DAYS = 45;
    public const OPERATIONAL_KEY = 'RETURN_NOT_RECEIVED_D45_V1';

    /** @return list<array<string,mixed>> */
    public static function definitions(): array
    {
        $source='https://github.com/Vivaliz-site/amazon-returns-safet/blob/main/docs/runbooks/shopvivaliz-d45-operational-policy.md';
        $rows=[];
        foreach(['STANDARD'=>'2020-01-01','FBA_ONSITE'=>'2026-04-21','DELIVERY_BY_AMAZON'=>'2026-04-21'] as $program=>$effective){
            $row=[
                'policy_key'=>self::OPERATIONAL_KEY,
                'marketplace_id'=>'A2Q3Y263D00KWC',
                'program'=>$program,'effective_from'=>$effective,'effective_to'=>null,
                'eligibility_days'=>self::OPERATIONAL_DAYS,'basis'=>'SELLER_DEBIT_AT',
                'source_url'=>$source,
                'source_hash'=>hash('sha256','shopvivaliz-operational-d45-v1|2026-09-05|'.$program.'|SELLER_DEBIT_AT'),
                'status'=>'ACTIVE',
            ];
            $rows[]=$row;
        }
        return $rows;
    }

    /** Verify actual active rows, including missing/duplicate/wrong versions. */
    public static function auditDefinitions(array $rows): array
    {
        $expected=[];
        foreach(self::definitions() as $row)$expected[$row['program']]=$row;
        $issues=0;$observed=[];
        foreach($rows as $row){
            if(($row['status']??'')!=='ACTIVE')continue;
            if(!str_starts_with((string)($row['policy_key']??''),'RETURN_NOT_RECEIVED'))continue;
            $program=(string)($row['program']??'');
            if(!isset($expected[$program])){$issues++;continue;}
            $definition=$expected[$program];unset($expected[$program]);
            foreach($definition as $key=>$value){
                if((string)($row[$key]??'')!==(string)($value??'')){$issues++;break;}
            }
            $observed[]=['program'=>$program,'days'=>(int)($row['eligibility_days']??0),'policy_key'=>(string)($row['policy_key']??'')];
        }
        $issues+=count($expected);
        return ['valid'=>$issues===0,'invalid_definitions'=>$issues,'operational_opening_days'=>self::OPERATIONAL_DAYS,'policy_key'=>self::OPERATIONAL_KEY,'observed'=>$observed];
    }

    public static function ensure(SvAmazonReturnPolicyRepository $target): int
    {
        // New customers must approve their own policies; do not copy this override.
        if($target->tenantSlug()!=='shopvivaliz')return 0;
        return $target->activateOperationalPolicies(self::definitions(), 'RETURN_NOT_RECEIVED');
    }
}
