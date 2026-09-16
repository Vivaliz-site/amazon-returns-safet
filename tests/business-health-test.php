<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/BusinessHealth.php';

function bhSame(mixed $want,mixed $got,string $why):void{
    if($want!==$got)throw new RuntimeException($why.' want='.var_export($want,true).' got='.var_export($got,true));
}
function bhAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}

$readiness=[
    'sp_api'=>['ready'=>true,'missing'=>[]],
    'gmail'=>['ready'=>true,'missing'=>[]],
    'seller_central_bridge'=>['ready'=>true,'missing'=>[]],
];
$flags=[
    'SAFE_T_SUBMIT'=>true,'SAFE_T_APPEAL'=>true,
    'SAFE_T_EMAIL_REVIEW'=>true,'SAFE_T_EMAIL_REPLY'=>true,
    'SELLER_SUPPORT_OPEN'=>true,'SELLER_SUPPORT_UPDATE'=>true,
    'ERP_SALES_RETURN_CREATE'=>false,
];
$browser=['status'=>'OK','reason'=>null];
$result=SvAmazonBusinessHealth::evaluate(true,'production',$readiness,$flags,$browser);
bhSame('DEGRADED',$result['status'],'Disabled mandatory ERP capability must degrade health.');
bhAssert(in_array('WRITE_GATE_ERP_SALES_RETURN_CREATE_DISABLED',$result['blockers'],true),'ERP blocker must be explicit.');
$flags['ERP_SALES_RETURN_CREATE']=true;
$healthy=SvAmazonBusinessHealth::evaluate(true,'production',$readiness,$flags,$browser);
bhSame('OK',$healthy['status'],'All mandatory capabilities available should be healthy.');
$readiness['gmail']=['ready'=>false,'missing'=>['GMAIL_OAUTH_REFRESH_TOKEN']];
$missing=SvAmazonBusinessHealth::evaluate(true,'production',$readiness,$flags,$browser);
bhSame('DEGRADED',$missing['status'],'Missing mandatory integration readiness must degrade health.');
bhAssert(in_array('READINESS_GMAIL',$missing['blockers'],true),'Missing Gmail readiness must be explicit.');

$disabled=SvAmazonBusinessHealth::evaluate(false,'production',$readiness,$flags,$browser);
bhSame('FAILED',$disabled['status'],'Disabled production service must fail health.');

echo "business-health-test: OK\n";
