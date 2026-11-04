<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Config.php';

function assertClaimKinds(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException('SELLER_CENTRAL_CLAIM_KINDS_FAIL: '.$message);
}
$types=SvAmazonReturnsConfig::sellerCentralWriteKinds([
    'SAFE_T_SUBMIT'=>true,'SAFE_T_APPEAL'=>false,
    'SELLER_SUPPORT_OPEN'=>false,'SELLER_SUPPORT_UPDATE'=>false,
    'SAFE_T_EMAIL_REVIEW'=>true,'ERP_SALES_RETURN_CREATE'=>true,
]);
assertClaimKinds($types===['SAFE_T_SUBMIT'],'disabled kinds must not enter claimBatch');
assertClaimKinds(SvAmazonReturnsConfig::sellerCentralWriteKinds([])===[],'missing flags fail closed');
assertClaimKinds(SvAmazonReturnsConfig::sellerCentralWriteKinds([
    'SAFE_T_SUBMIT'=>1,'SAFE_T_APPEAL'=>'1','SELLER_SUPPORT_OPEN'=>true,
    'SELLER_SUPPORT_UPDATE'=>false,
])===['SELLER_SUPPORT_OPEN'],'only literal booleans grant a kind');
assertClaimKinds(SvAmazonReturnsConfig::sellerCentralWriteKinds([
    'SAFE_T_SUBMIT'=>true,'SAFE_T_APPEAL'=>true,
    'SELLER_SUPPORT_OPEN'=>true,'SELLER_SUPPORT_UPDATE'=>true,
])===['SAFE_T_SUBMIT','SAFE_T_APPEAL','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'],'all four kinds preserved');
$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
$bridge=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/BridgeService.php');
assertClaimKinds(str_contains($daemon,'claimBatch(') &&
    preg_match('/claimBatch\(\s*10\s*,\s*\$enabledKinds\s*,\s*\$this->config->scopedWriteCaseIds\(\)/',$daemon)===1,
    'direct daemon must claim only enabled kinds at SELECT FOR UPDATE');
assertClaimKinds(str_contains($bridge,'SvAmazonReturnsConfig::sellerCentralWriteKinds($flags)'),
    'polling bridge must use the same trusted filter');
echo "seller-central-claim-enabled-kinds-test: OK (offline no provider write)\n";
