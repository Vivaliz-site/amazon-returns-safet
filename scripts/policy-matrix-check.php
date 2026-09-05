<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/PolicyMatrix.php';
try{
    if(($argv[1]??'')==='--sql'){
        $id=filter_var($argv[2]??null,FILTER_VALIDATE_INT);
        if($id===false || $id<1)throw new InvalidArgumentException('Invalid tenant ID.');
        echo SvAmazonReturnPolicyMatrix::sql($id),"\n";exit(0);
    }
    require_once __DIR__.'/../includes/Database.php';
    require_once __DIR__.'/../includes/amazon-returns/Config.php';
    require_once __DIR__.'/../includes/amazon-returns/TenantRegistry.php';
    require_once __DIR__.'/../includes/amazon-returns/TenantPersistence.php';
    $db=amazon_returns_require_pdo();$config=new SvAmazonReturnsConfig();
    $context=SvAmazonTenantRegistry::resolveCurrent($db,$config);
    $p=SvAmazonTenantPersistence::create($db,$context);
    $rows=$p->policies->allActive();$errors=SvAmazonReturnPolicyMatrix::violations($rows);
    $summary=['policy_matrix'=>'BR_45_60','tenant_id'=>$context->tenantId(),'active_policies'=>count($rows),'violations'=>$errors,'status'=>$errors===[]?'OK':'MISMATCH'];
    echo json_encode($summary,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),"\n";
    exit($errors===[]?0:1);
}catch(Throwable $e){fwrite(STDERR,'policy_matrix_check_failed='.$e::class."\n");exit(2);}
