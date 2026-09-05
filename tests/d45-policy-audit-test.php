<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/PolicySeeder.php';
if(!method_exists(SvAmazonReturnPolicySeeder::class,'auditDefinitions')){fwrite(STDERR,"Missing exact operational policy acceptance audit\n");exit(1);}
function dpaSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why);}
$defs=SvAmazonReturnPolicySeeder::definitions();
dpaSame('RETURN_NOT_RECEIVED_D45_REFUND_V2',SvAmazonReturnPolicySeeder::OPERATIONAL_KEY,'refund-based D45 must use a new immutable policy version');
dpaSame(true,SvAmazonReturnPolicySeeder::auditDefinitions($defs)['valid'],'approved tenant operational definitions must validate');
dpaSame(false,SvAmazonReturnPolicySeeder::auditDefinitions([])['valid'],'zero policies cannot pass as no invalid policies');
$bad=$defs;$bad[1]['eligibility_days']=60;dpaSame(false,SvAmazonReturnPolicySeeder::auditDefinitions($bad)['valid'],'published60 must not replace operational45');
$bad=$defs;$bad[2]['policy_key']='RETURN_NOT_RECEIVED';dpaSame(false,SvAmazonReturnPolicySeeder::auditDefinitions($bad)['valid'],'active legacy version must fail');
$bad=$defs;$bad[]=$defs[0];dpaSame(false,SvAmazonReturnPolicySeeder::auditDefinitions($bad)['valid'],'duplicate active policy must fail');
$bad=$defs;$bad[0]['basis']='SELLER_DEBIT_AT';dpaSame(false,SvAmazonReturnPolicySeeder::auditDefinitions($bad)['valid'],'unexpected seller-debit basis must fail; operational D45 starts at Amazon refund');
$history=$defs[0];$history['policy_key']='RETURN_NOT_RECEIVED';$history['eligibility_days']=75;$history['status']='SUPERSEDED';
dpaSame(true,SvAmazonReturnPolicySeeder::auditDefinitions([...$defs,$history])['valid'],'historical bad rule may be preserved but not active');
echo "d45-policy-audit-test: OK\n";
