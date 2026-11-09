<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SpApiEventSink.php';
require_once __DIR__.'/../includes/amazon-returns/Projector.php';

$fail=[];
function cdeSame(mixed $want,mixed $got,string $why):void{global $fail;if($want!==$got)$fail[]=$why.' expected='.json_encode($want).' actual='.json_encode($got);}
$order=['packages'=>[['packageStatus'=>['status'=>'DELIVERED'],'carrier'=>'AMZBR','trackingNumber'=>'AMZB925901732tx','packageItems'=>[['orderItemId'=>'156301061858921']]]]];
$item=['orderItemId'=>'156301061858921'];
$proof=SvAmazonSpApiEventSink::customerDeliveryObservation($order,$item,true);
cdeSame(true,$proof['confirmed']??null,'DELIVERED package for the same item must become trusted customer-delivery proof.');
cdeSame(['AMZB925901732tx'],$proof['tracking_ids']??null,'Tracking must stay attached to the delivery proof.');
cdeSame(['AMZBR'],$proof['carriers']??null,'Carrier must stay attached to the delivery proof.');
$wrong=SvAmazonSpApiEventSink::customerDeliveryObservation($order,['orderItemId'=>'other'],false);
cdeSame(false,$wrong['confirmed']??null,'A package for another item must not prove delivery.');
$pending=$order;$pending['packages'][0]['packageStatus']['status']='IN_TRANSIT';
cdeSame(false,SvAmazonSpApiEventSink::customerDeliveryObservation($pending,$item,true)['confirmed']??null,'Non-delivered package must not prove delivery.');
$pendingProof=SvAmazonSpApiEventSink::customerDeliveryObservation($pending,$item,true);
cdeSame('IN_TRANSIT',$pendingProof['delivery_status']??null,'Pending shipment must retain the official shipping state.');
cdeSame(['AMZB925901732tx'],$pendingProof['tracking_ids']??null,'Tracking must remain available before customer delivery.');
$partial=$order;
$partial['packages'][]=[
    'packageStatus'=>['status'=>'IN_TRANSIT'],'carrier'=>'AMZBR','trackingNumber'=>'AMZB925901733tx',
    'packageItems'=>[['orderItemId'=>'156301061858921']],
];
$partialProof=SvAmazonSpApiEventSink::customerDeliveryObservation($partial,$item,true);
cdeSame(false,$partialProof['confirmed']??null,'Mixed package statuses must not claim entire item delivered.');
cdeSame('PARTIALLY_DELIVERED',$partialProof['delivery_status']??null,'Partial delivery must be exposed explicitly.');
cdeSame(2,count($partialProof['tracking_ids']??[]),'All relevant shipping codes must be retained.');
$case=['id'=>501,'quantity_ordered'=>1,'state'=>'POLICY_REVIEW_REQUIRED','physical_status'=>'NOT_RECEIVED'];
$event=['case_id'=>501,'event_type'=>'ORDER_SYNCED','source'=>'SP_API_ORDERS','occurred_at'=>'2026-03-22 09:59:33','payload'=>['quantity_ordered'=>1,'program'=>'DELIVERY_BY_AMAZON','customer_delivery_confirmed'=>true,'customer_tracking_ids'=>['AMZB925901732tx'],'customer_delivery_carriers'=>['AMZBR']]];
$projected=SvAmazonReturnProjector::projectFrom($case,[$event]);
cdeSame(true,$projected['customer_delivery_confirmed']??null,'Projector must expose trusted delivery proof to the policy engine.');
cdeSame(['AMZB925901732tx'],$projected['customer_tracking_ids']??null,'Projector must preserve tracking evidence.');
$pendingEvent=$event;
$pendingEvent['payload']['customer_delivery_confirmed']=false;
$pendingEvent['payload']['customer_delivery_status']='IN_TRANSIT';
$pendingProjection=SvAmazonReturnProjector::projectFrom($case,[$pendingEvent]);
cdeSame(false,$pendingProjection['customer_delivery_confirmed']??null,'Tracking without delivery cannot become confirmed receipt.');
cdeSame('IN_TRANSIT',$pendingProjection['customer_delivery_status']??null,'Projector must retain shipping state.');
cdeSame(['AMZB925901732tx'],$pendingProjection['customer_tracking_ids']??null,'Projector must retain in-transit tracking.');
if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}echo "customer-delivery-evidence-test: OK\n";
