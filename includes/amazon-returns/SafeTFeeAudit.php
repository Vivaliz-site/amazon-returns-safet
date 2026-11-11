<?php
declare(strict_types=1);

/**
 * A READ-ONLY, source-backed SAFE-T financial fee audit.
 *
 * Amazon Brasil FBA Onsite policy: price paid by customer - customer shipping
 * - Selling on Amazon fees - Seller Flex fees - taxes on both sets of fees.
 * Source: https://sellercentral.amazon.com.br/help/hub/reference/external/GRFFG69BHA5M8EY9?locale=pt-BR
 *
 * The legacy expected amount is a *seller refund debit*, not the policy
 * entitlement. This report NEVER changes cases, credits, claims or review
 * gates. It keeps taxes/other unexplained deductions separate from fees proven
 * by the itemized Finances v0 shipment/refund events.
 */
final class SvAmazonSafeTFeeAudit
{
    /** @return array<string,mixed> */
    public static function analyze(array $case,array $events,string $actualLedgerCredit):array
    {
        $id=(int)($case['id']??0);
        $order=trim((string)($case['amazon_order_id']??''));
        $claim=trim((string)($case['safe_t_id']??''));
        $expected=self::money($case['expected_reimbursement_amount']??null);
        $credited=self::money($actualLedgerCredit);
        if($id<1||!preg_match('/^\\d{3}-\\d{7}-\\d{7}$/D',$order)
            ||$expected===null||$credited===null||$expected<0||$credited<0){
            return ['case_id'=>$id,'status'=>'INSUFFICIENT_CASE_EVIDENCE'];
        }
        $shipments=$events['ShipmentEventList']??[];
        $refunds=$events['RefundEventList']??[];
        $claims=$events['SAFETReimbursementEventList']??[];
        if(!is_array($shipments)||count($shipments)!==1||!is_array($refunds)||count($refunds)!==1
            ||!is_array($claims)||$claim===''){
            return ['case_id'=>$id,'status'=>'SOURCE_EVENT_SCOPE_UNVERIFIED'];
        }
        $shipItems=$shipments[0]['ShipmentItemList']??[];
        $refundItems=$refunds[0]['ShipmentItemAdjustmentList']??[];
        if(!is_array($shipItems)||count($shipItems)!==1
            ||!is_array($refundItems)||count($refundItems)!==1){
            return ['case_id'=>$id,'status'=>'MULTI_LINE_ALLOCATION_UNVERIFIED'];
        }
        $si=$shipItems[0];$ri=$refundItems[0];
        $sku=(string)($si['SellerSKU']??'');
        $sq=(int)($si['QuantityShipped']??0);
        $rq=(int)($ri['QuantityShipped']??0);
        if($sku===''||!hash_equals($sku,(string)($ri['SellerSKU']??''))
            ||$sq<=0||$rq<=0||$rq>$sq){
            return ['case_id'=>$id,'status'=>'REFUND_ITEM_IDENTITY_UNVERIFIED'];
        }
        try{
            $shipFees=self::feeTotals($si['ItemFeeList']??[],'FeeAmount','FeeType');
            $refundFees=self::feeTotals($ri['ItemFeeAdjustmentList']??[],'FeeAmount','FeeType');
            $refundCharges=self::feeTotals($ri['ItemChargeAdjustmentList']??[],'ChargeAmount','ChargeType');
            $saleCharges=self::feeTotals($si['ItemChargeList']??[],'ChargeAmount','ChargeType');
            $origFees=[];
            foreach($shipFees as $name=>$cents){
                $alloc=(int)round(abs($cents)*$rq/$sq,0,PHP_ROUND_HALF_UP);
                $origFees[$name]=$cents<0?-$alloc:$alloc;
            }
            $feeCents=-array_sum($origFees);
            $refundFeeCredits=array_sum($refundFees);
            $principal=-($refundCharges['Principal']??0);
            if($feeCents<0||$principal<=0||$refundFeeCredits<0)return ['case_id'=>$id,'status'=>'INCONSISTENT_SOURCE_FEES'];
            if(abs($principal-$refundFeeCredits-$expected)>1){
                return ['case_id'=>$id,'status'=>'SELLER_DEBIT_SOURCE_MISMATCH',
                    'debit_ledger'=>self::amount($expected),
                    'debit_calculated'=>self::amount($principal-$refundFeeCredits)];
            }
            $claimEvents=array_values(array_filter($claims,static fn($x):bool=>
                is_array($x)&&trim((string)($x['SAFETClaimId']??''))===$claim));
            if(count($claimEvents)!==1)return ['case_id'=>$id,'status'=>'SAFE_T_CLAIM_SOURCE_UNVERIFIED'];
            $v0Credit=self::money($claimEvents[0]['ReimbursedAmount']['CurrencyAmount']??null);
            if($v0Credit===null||abs($v0Credit-$credited)>1){
                return ['case_id'=>$id,'status'=>'SAFE_T_CREDIT_LEDGER_MISMATCH',
                    'credit_ledger'=>self::amount($credited),
                    'credit_source'=> $v0Credit!==null?self::amount($v0Credit):null];
            }
            $preTax=$principal-$feeCents;
            $feeRetention=$feeCents-$refundFeeCredits;
            $grossGap=max(0,$expected-$credited);
            $unitemized=$preTax-$credited;
            // Advisory only: apply the owner's existing strictly-under-15%
            // tolerance to the fee-adjusted reference, without mutating any
            // financial state or substituting it for tax evidence.
            $underFifteenAfterFees=$credited>0 && $preTax>0 && $unitemized>=0
                && $unitemized*100 < $preTax*15;
            return [
                'case_id'=>$id,
                'status'=>'FEE_COMPONENTS_RECONCILED_EXTRA_UNITEMIZED',
                'quantity_refunded'=>$rq,'quantity_shipped'=>$sq,
                'refund_principal'=>self::amount($principal),
                'customer_shipping_original'=>self::amount(max(0,$saleCharges['ShippingCharge']??0)),
                'seller_debit_expected'=>self::amount($expected),
                'seller_credit_released'=>self::amount($credited),
                'seller_refund_fee_credit_net'=>self::amount($refundFeeCredits),
                'sale_fees_allocated_to_refund'=>self::amount($feeCents),
                'refunded_fee_components'=>self::fmt($refundFees),
                'shipment_fee_components_allocated'=>self::fmt($origFees),
                'retained_fee_difference'=>self::amount($feeRetention),
                'safe_t_reference_before_unitemized_taxes'=>self::amount($preTax),
                'unitemized_taxes_or_other_adjustments'=>self::amount($unitemized),
                'unitemized_percentage_of_fee_adjusted_reference'=>$preTax>0
                    ? number_format(($unitemized/$preTax)*100,2,'.','') : null,
                'under_15_percent_after_proven_fees_advisory'=>$underFifteenAfterFees,
                'apparent_shortfall_vs_refund_debit'=>self::amount($grossGap),
                'fee_delta_plus_unitemized_equals_apparent'=>
                    ($feeRetention+$unitemized)===$expected-$credited,
                'caution'=>'Tax and other deduction types are not itemized in the v0 reimbursement charge. Do not auto-close/appeal from this audit.',
            ];
        }catch(Throwable $e){
            return ['case_id'=>$id,'status'=>'INVALID_SOURCE_FINANCIAL_DATA','error_class'=>$e::class];
        }
    }

    /** @return array<string,int> */
    private static function feeTotals(mixed $values,string $amountKey,string $typeKey):array
    {
        if(!is_array($values))throw new UnexpectedValueException('fee_list_not_array');
        $out=[];
        foreach($values as $v){
            if(!is_array($v))throw new UnexpectedValueException('fee_not_object');
            $type=trim((string)($v[$typeKey]??''));
            $money=$v[$amountKey]??[];
            if($type===''||!is_array($money))throw new UnexpectedValueException('fee_type_unknown');
            $currency=strtoupper(trim((string)($money['CurrencyCode']??'')));
            if($currency!==''&&$currency!=='BRL')throw new UnexpectedValueException('fee_currency_mismatch');
            $cents=self::money($money['CurrencyAmount']??null);
            if($cents===null)throw new UnexpectedValueException('fee_amount_missing');
            $out[$type]=($out[$type]??0)+$cents;
        }
        ksort($out);
        return $out;
    }

    private static function money(mixed $v):?int
    {
        if(!is_numeric($v))return null;
        $s=trim((string)$v);
        if(!preg_match('/^-?\\d+(?:\\.\\d{1,2})?$/D',$s))return null;
        $neg=str_starts_with($s,'-');$parts=explode('.',ltrim($s,'-'),2);
        return ($neg?-1:1)*((int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0'));
    }

    private static function amount(int $cents):string{return number_format($cents/100,2,'.','');}

    private static function fmt(array $m):array
    {
        return array_map(static fn(int $cents):string=>self::amount($cents),$m);
    }
}
