<?php

declare(strict_types=1);

require_once __DIR__ . '/PolicyRepository.php';

final class SvAmazonReturnPolicySeeder
{
    /** @return list<array<string,mixed>> */
    public static function definitions(): array
    {
        $standardSource='https://sellercentral.amazon.com.br/seller-forums/discussions/t/21dbbb44-5916-4a1c-8dfa-87aef8c5aab3';
        $sixtySource='https://sellercentral.amazon.com.br/seller-forums/discussions/t/374d2692-2b56-411e-b497-b37d7cb1229b';
        return [
            self::row('RETURN_NOT_RECEIVED','A2Q3Y263D00KWC','STANDARD','2020-01-01',75,'SELLER_DEBIT_AT',$standardSource,'Amazon BR: Retornando ao Vendedor >75 dias com comprador reembolsado habilita tentativa SAFE-T.'),
            self::row('RETURN_NOT_RECEIVED','A2Q3Y263D00KWC','FBA_ONSITE','2026-04-21',75,'SELLER_DEBIT_AT',$sixtySource,'Amazon BR: pedidos FBA Onsite a partir de 21/04/2026 aguardam 75 dias após reembolso.'),
            self::row('RETURN_NOT_RECEIVED','A2Q3Y263D00KWC','DELIVERY_BY_AMAZON','2026-04-21',75,'SELLER_DEBIT_AT',$sixtySource,'Amazon BR: pedidos Delivery by Amazon a partir de 21/04/2026 aguardam 75 dias após reembolso.'),
        ];
    }

    public static function ensure(SvAmazonReturnPolicyRepository $target): int
    {
        return $target->seed(self::definitions());
    }

    /** @return array<string,mixed> */
    private static function row(
        string $key,
        string $market,
        string $program,
        string $effective,
        int $days,
        string $basis,
        string $url,
        string $snapshot
    ): array {
        return [
            'policy_key'=>$key,
            'marketplace_id'=>$market,
            'program'=>$program,
            'effective_from'=>$effective,
            'effective_to'=>null,
            'eligibility_days'=>$days,
            'basis'=>$basis,
            'source_url'=>$url,
            'source_hash'=>hash('sha256',$url.'|'.$snapshot),
            'status'=>'ACTIVE',
        ];
    }
}
