<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Config.php';
$errors=[];
function wpSame(mixed $want,mixed $got,string $why):void{global $errors;if($want!==$got)$errors[]=$why.' expected='.json_encode($want).' actual='.json_encode($got);}
$profile=__DIR__.'/../deploy/write-profile.json';
wpSame(true,is_file($profile),'versioned write profile must exist');
$cfg=new SvAmazonReturnsConfig([
 'AMAZON_RETURNS_ENABLED'=>'1','AMAZON_RETURNS_MODE'=>'production',
 'AMAZON_RETURNS_SAFE_T_WRITE'=>'0','AMAZON_RETURNS_APPEAL_WRITE'=>'0',
 'AMAZON_RETURNS_EMAIL_REVIEW_WRITE'=>'0','AMAZON_RETURNS_EMAIL_REPLY_WRITE'=>'0',
 'AMAZON_RETURNS_SUPPORT_WRITE'=>'0','AMAZON_RETURNS_WRITE_PROFILE_FILE'=>$profile,
]);
wpSame('safet-submit-appeal-email-review-v1',$cfg->writeProfileVersion(),'profile version');
wpSame(true,$cfg->externalWriteAllowed('SAFE_T_SUBMIT'),'profile enables future eligible openings');
wpSame(true,$cfg->externalWriteAllowed('SAFE_T_APPEAL'),'profile enables eligible appeals');
wpSame(true,$cfg->externalWriteAllowed('SAFE_T_EMAIL_REVIEW'),'email review enabled for deterministic post-appeal review');
wpSame(false,$cfg->externalWriteAllowed('SAFE_T_EMAIL_REPLY'),'email reply remains off');
wpSame(false,$cfg->externalWriteAllowed('SELLER_SUPPORT_OPEN'),'support remains off');
$kill=new SvAmazonReturnsConfig([
 'AMAZON_RETURNS_ENABLED'=>'1','AMAZON_RETURNS_MODE'=>'production',
 'AMAZON_RETURNS_WRITE_PROFILE_FILE'=>$profile,'AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'1',
]);
foreach(['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN'] as $action){
 wpSame(false,$kill->externalWriteAllowed($action),'kill switch disables '.$action);
}
$invalid=tempnam(sys_get_temp_dir(),'write-profile-');file_put_contents($invalid,'{"version":"broken","SAFE_T_SUBMIT":true}');
$bad=new SvAmazonReturnsConfig(['AMAZON_RETURNS_ENABLED'=>'1','AMAZON_RETURNS_MODE'=>'production','AMAZON_RETURNS_WRITE_PROFILE_FILE'=>$invalid]);
wpSame(null,$bad->writeProfileVersion(),'invalid profile fails closed');
wpSame(false,$bad->externalWriteAllowed('SAFE_T_SUBMIT'),'invalid profile cannot write');
unlink($invalid);
$dry=new SvAmazonReturnsConfig(['AMAZON_RETURNS_ENABLED'=>'1','AMAZON_RETURNS_MODE'=>'shadow','AMAZON_RETURNS_WRITE_PROFILE_FILE'=>$profile]);
wpSame(false,$dry->externalWriteAllowed('SAFE_T_SUBMIT'),'non-production never writes');
$checker=__DIR__.'/../scripts/write-profile-check.php';
wpSame(true,is_file($checker),'runtime verifier must use a profile checker');
if(is_file($checker)){
 $json=shell_exec('php '.escapeshellarg($checker));$checked=is_string($json)?json_decode($json,true):null;
 wpSame('safet-submit-appeal-email-review-v1',$checked['version']??null,'checker profile version');
 wpSame(true,$checked['flags']['SAFE_T_SUBMIT']??null,'checker sees submit enabled');
 wpSame(true,$checked['flags']['SAFE_T_APPEAL']??null,'checker sees appeal enabled');
 wpSame(true,$checked['flags']['SAFE_T_EMAIL_REVIEW']??null,'checker sees deterministic email review enabled');
}
$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
wpSame(true,str_contains($daemon,'write_profile_revision'),'profile change must force scheduler reevaluation');
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "write-profile-test: OK\n";
