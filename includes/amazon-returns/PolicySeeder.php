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

    public static function ensure(SvAmazonReturnPolicyRepository $target): int
    {
        // New customers must approve their own policies; do not copy this override.
        if($target->tenantSlug()!=='shopvivaliz')return 0;
        return $target->activateOperationalPolicies(self::definitions(), 'RETURN_NOT_RECEIVED');
    }
}
