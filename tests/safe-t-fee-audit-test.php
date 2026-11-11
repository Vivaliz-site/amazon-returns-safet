<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTFeeAudit.php';
function stfaEq(mixed $want,mixed $got,string $label):void{if($want!==$got)throw new RuntimeException($label.' expected='.json_encode($want).' got='.json_encode($got));}
function stfaFee(string $type,string $amount):array{return ['FeeType'=>$type,'FeeAmount'=>['CurrencyAmount'=>$amount,'CurrencyCode'=>'BRL']];}
function stfaCharge(string $type,string $amount):array{return ['ChargeType'=>$type,'ChargeAmount'=>['CurrencyAmount'=>$amount,'CurrencyCode'=>'BRL']];}
function stfaEvents(int $shipped,int $refunded,array $fees,array $refundedFees,string $principal,string $payout):array{
 return [
 'ShipmentEventList'=>[['ShipmentItemList'=>[[
   'SellerSKU'=>'reference-sku','QuantityShipped'=>$shipped,
   'ItemFeeList'=>$fees,'ItemChargeList'=>[stfaCharge('Principal',(string)($shipped*100))],
 ]]]],
 'RefundEventList'=>[['ShipmentItemAdjustmentList'=>[[
   'SellerSKU'=>'reference-sku','QuantityShipped'=>$refunded,
   'ItemFeeAdjustmentList'=>$refundedFees,
   'ItemChargeAdjustmentList'=>[stfaCharge('Principal','-'.$principal)],
 ]]]],
 'SAFETReimbursementEventList'=>[[
   'SAFETClaimId'=>'10064-30094-1469107',
   'ReimbursedAmount'=>['CurrencyAmount'=>$payout,'CurrencyCode'=>'BRL'],
 ]],
 ];
}
$c=['id'=>13211,'amazon_order_id'=>'701-0000000-0000000',
 'safe_t_id'=>'10064-30094-1469107','expected_reimbursement_amount'=>'137.12'];
$e=stfaEvents(2,2,[
 stfaFee('Commission','-23.70'),stfaFee('AmazonForAllFee','-2.37'),
 stfaFee('FBAPerUnitFulfillmentFee','-13.50')
],[
 stfaFee('Commission','23.70'),stfaFee('AmazonForAllFee','2.37'),
 stfaFee('RefundCommission','-5.21')
],'157.98','114.75');
$a=SvAmazonSafeTFeeAudit::analyze($c,$e,'114.75');
foreach([
 'status'=>'FEE_COMPONENTS_RECONCILED_EXTRA_UNITEMIZED',
 'refund_principal'=>'157.98','seller_debit_expected'=>'137.12',
 'seller_credit_released'=>'114.75','seller_refund_fee_credit_net'=>'20.86',
 'sale_fees_allocated_to_refund'=>'39.57','retained_fee_difference'=>'18.71',
 'safe_t_reference_before_unitemized_taxes'=>'118.41',
 'unitemized_taxes_or_other_adjustments'=>'3.66',
 'apparent_shortfall_vs_refund_debit'=>'22.37',
 'fee_delta_plus_unitemized_equals_apparent'=>true,
 'under_15_percent_after_proven_fees_advisory'=>true,
 'unitemized_percentage_of_fee_adjusted_reference'=>'3.09',
] as $key=>$v)stfaEq($v,$a[$key]??null,'case13211_'.$key);

$multi=$c;$multi['id']=13216;$multi['expected_reimbursement_amount']='68.29';
$me=stfaEvents(2,1,[
 stfaFee('Commission','-16.70'),stfaFee('AmazonForAllFee','-2.28'),
 stfaFee('FBAPerUnitFulfillmentFee','-13.50')
],[
 stfaFee('Commission','8.35'),stfaFee('AmazonForAllFee','1.14'),
 stfaFee('RefundCommission','-1.90')
],'75.88','53.07');
$m=SvAmazonSafeTFeeAudit::analyze($multi,$me,'53.07');
stfaEq('8.65',$m['retained_fee_difference'],'partial_order_allocated_fees');
stfaEq('6.57',$m['unitemized_taxes_or_other_adjustments'],'partial_order_remaining');
stfaEq('59.64',$m['safe_t_reference_before_unitemized_taxes'],'partial_order_pre_tax');
stfaEq(true,$m['under_15_percent_after_proven_fees_advisory'],'partial_order_under_15_percent_advisory');
$high=$c;$high['id']=13206;$high['expected_reimbursement_amount']='20.46';
$he=stfaEvents(1,1,[
 stfaFee('Commission','-2.47'),stfaFee('FBAPerUnitFulfillmentFee','-6.25')
],[stfaFee('Commission','2.47'),stfaFee('RefundCommission','-0.49')],
 '22.44','5.31');
$h=SvAmazonSafeTFeeAudit::analyze($high,$he,'5.31');
stfaEq('6.74',$h['retained_fee_difference'],'large_remaining_known_fees');
stfaEq('8.41',$h['unitemized_taxes_or_other_adjustments'],'large_remaining_unitemized');
stfaEq(false,$h['under_15_percent_after_proven_fees_advisory'],'large_remaining_remains_above_15');

$bad=$e;$bad['RefundEventList'][0]['ShipmentItemAdjustmentList'][0]['SellerSKU']='different-item';
stfaEq('REFUND_ITEM_IDENTITY_UNVERIFIED',
 SvAmazonSafeTFeeAudit::analyze($c,$bad,'114.75')['status'],'different_item_is_not_proof');
$bad=$e;$bad['ShipmentEventList'][0]['ShipmentItemList'][]=$bad['ShipmentEventList'][0]['ShipmentItemList'][0];
stfaEq('MULTI_LINE_ALLOCATION_UNVERIFIED',
 SvAmazonSafeTFeeAudit::analyze($c,$bad,'114.75')['status'],'multiple_item_rows_unverified');
$bad=$e;$bad['SAFETReimbursementEventList'][0]['SAFETClaimId']='other-claim';
stfaEq('SAFE_T_CLAIM_SOURCE_UNVERIFIED',
 SvAmazonSafeTFeeAudit::analyze($c,$bad,'114.75')['status'],'different_safet_claim_unverified');
stfaEq('SAFE_T_CREDIT_LEDGER_MISMATCH',
 SvAmazonSafeTFeeAudit::analyze($c,$e,'115.00')['status'],'source_vs_ledger_mismatch');
$bad=$e;$bad['RefundEventList'][0]['ShipmentItemAdjustmentList'][0]['ItemFeeAdjustmentList'][0]['FeeAmount']['CurrencyCode']='USD';
stfaEq('INVALID_SOURCE_FINANCIAL_DATA',
 SvAmazonSafeTFeeAudit::analyze($c,$bad,'114.75')['status'],'currency_mismatch');
$bad=$e;$bad['RefundEventList'][0]['ShipmentItemAdjustmentList'][0]['ItemFeeAdjustmentList'][0]['FeeAmount']['CurrencyAmount']='999.00';
stfaEq('SELLER_DEBIT_SOURCE_MISMATCH',
 SvAmazonSafeTFeeAudit::analyze($c,$bad,'114.75')['status'],'debit_reconstruction_mismatch');
echo "safe-t-fee-audit-test: OK\n";
