<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Runtime.php';
function frtSame(mixed $want,mixed $got,string $why):void{if($want!==$got){fwrite(STDERR,$why.' expected='.json_encode($want).' actual='.json_encode($got)."\n");exit(1);}}
if(!method_exists(SvAmazonReturnsRuntime::class,'financialRefreshContinuationRequired')){fwrite(STDERR,"runtime must expose the financial refresh continuation decision\n");exit(1);}
frtSame(true,SvAmazonReturnsRuntime::financialRefreshContinuationRequired(['scheduler'=>['financial_checks_requested'=>3]]),'A pending financial check with no SP-API refresh must schedule another refresh cycle');
frtSame(true,SvAmazonReturnsRuntime::financialRefreshContinuationRequired(['scheduler'=>['financial_checks_requested'=>3],'sp_api'=>['rotation_has_more'=>true]]),'A partial SP-API rotation must continue while financial checks remain pending');
frtSame(true,SvAmazonReturnsRuntime::financialRefreshContinuationRequired(['sp_api'=>['rotation_has_more'=>true],'financial'=>['status'=>'SKIPPED','reason'=>'FINANCIAL_REFRESH_NOT_ACCEPTED']]),'A continuation cycle must not forget an incomplete financial refresh after the scheduler leaves the due set');
frtSame(false,SvAmazonReturnsRuntime::financialRefreshContinuationRequired(['scheduler'=>['financial_checks_requested'=>3],'sp_api'=>['rotation_has_more'=>false]]),'A completed same-cycle SP-API rotation must remain throttled');
frtSame(false,SvAmazonReturnsRuntime::financialRefreshContinuationRequired(['scheduler'=>['financial_checks_requested'=>0]]),'No pending financial check must not force refresh');
$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
if(!str_contains($daemon,"'rotation_has_more'=>\$batch['has_more']")){fwrite(STDERR,"SP-API runtime result must expose whether its financial refresh rotation has more orders\n");exit(1);}
echo "financial-recheck-throttle-test: OK\n";
