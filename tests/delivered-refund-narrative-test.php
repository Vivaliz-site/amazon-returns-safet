<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ExternalWritePayload.php';

$decision=['action'=>'SAFE_T_SUBMIT','reason'=>'DELIVERED_CUSTOMER_REFUNDED_UNPAID'];
$case=['amazon_order_id'=>'702-0707321-6872209','safe_t_id'=>null,'customer_delivery_confirmed'=>true,'customer_tracking_ids'=>['AMZB925901732tx'],'reconciled_credit_amount'=>'0.00','expected_reimbursement_amount'=>'112.50'];
$snapshot=SvAmazonExternalWritePayload::build($decision,$case,[])['write_snapshot']??[];
$text=(string)($snapshot['narrative']??'');
$fail=[];
foreach(['entrega ao cliente','AMZB925901732tx','ainda não recebeu','ressarcimento'] as $needle){if(mb_stripos($text,$needle,0,'UTF-8')===false)$fail[]='Narrativa deve explicar em português: '.$needle;}
if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}echo "delivered-refund-narrative-test: OK\n";
