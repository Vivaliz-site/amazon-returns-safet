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
 'AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED'=>'1',
]);
wpSame('safet-full-recovery-v2',$cfg->writeProfileVersion(),'profile version');
foreach([
 'SAFE_T_SUBMIT'=>'profile enables future eligible openings',
 'SAFE_T_APPEAL'=>'profile enables eligible appeals',
 'SAFE_T_EMAIL_REVIEW'=>'profile enables deterministic post-appeal email review',
 'SAFE_T_EMAIL_REPLY'=>'profile enables deterministic replies in the existing Amazon thread',
 'SELLER_SUPPORT_OPEN'=>'profile enables deterministic Seller Support escalation',
 'SELLER_SUPPORT_UPDATE'=>'profile enables deterministic Seller Support follow-up',
] as $action=>$why)wpSame(true,$cfg->externalWriteAllowed($action),$why);
wpSame(true,$cfg->externalWriteAllowed('ERP_SALES_RETURN_CREATE'),'ERP sales-return profile must ship enabled after proven production canary');
wpSame(true,method_exists($cfg,'erpSalesReturnCreateEnabled'),'config must expose the dedicated ERP sales-return gate');
if(method_exists($cfg,'erpSalesReturnCreateEnabled'))wpSame(true,$cfg->erpSalesReturnCreateEnabled(),'both production ERP gates must enable sales-return creation');
$kill=new SvAmazonReturnsConfig([
 'AMAZON_RETURNS_ENABLED'=>'1','AMAZON_RETURNS_MODE'=>'production',
 'AMAZON_RETURNS_WRITE_PROFILE_FILE'=>$profile,'AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'1',
 'AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED'=>'1',
]);
foreach(['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE','ERP_SALES_RETURN_CREATE'] as $action){
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
 $fixtureEnv=tempnam(sys_get_temp_dir(),'amazon-wp-fixture-');
 if($fixtureEnv===false)throw new RuntimeException('Synthetic env fixture unavailable.');
 try{
  file_put_contents($fixtureEnv,"AMAZON_RETURNS_ENABLED=1\nAMAZON_RETURNS_MODE=production\nAMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH=0\nAMAZON_RETURNS_WRITE_PROFILE_FILE=".$profile."\nAMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED=0\n");
  $json=shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($checker).' '.escapeshellarg('--env-file='.$fixtureEnv));
 }finally{unlink($fixtureEnv);}
 $checked=is_string($json)?json_decode($json,true):null;
 wpSame('safet-full-recovery-v2',$checked['version']??null,'checker profile version');
 foreach(['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'] as $action){
  wpSame(true,$checked['flags'][$action]??null,'checker sees '.$action.' enabled');
 }
 wpSame(false,$checked['flags']['ERP_SALES_RETURN_CREATE']??null,'checker remains fail-closed without the dedicated environment gate');
}
if(is_file($checker)){
 // Missing environment identity must never emit permissive production defaults.
 $lines=[];$exit=-1;
 exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($checker).' 2>&1',$lines,$exit);
 $output=implode("\n",$lines);
 wpSame(2,$exit,'missing env file must fail closed');
 wpSame(true,str_contains($output,'reason=ENV_FILE_REQUIRED'),'missing env file reason is sanitized');
 wpSame(false,str_contains($output,'"flags"'),'missing env file cannot print production write flags');
 // All fixtures are synthetic. No production environment file is read.
 $missing=sys_get_temp_dir().'/amazon-returns-absent-'.bin2hex(random_bytes(8));
 foreach(['--env-file=','--env-file='.sys_get_temp_dir(),'--env-file='.$missing] as $arg){
  $lines=[];$exit=-1;
  exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($checker).' '.escapeshellarg($arg).' 2>&1',$lines,$exit);
  $output=implode("\n",$lines);
  wpSame(2,$exit,'explicit invalid env file must fail closed');
  wpSame(true,str_contains($output,'reason=ENV_FILE_UNREADABLE'),'sanitized missing env reason');
  wpSame(false,str_contains($output,'"flags"'),'unreadable env must not print write flags');
  wpSame(false,str_contains($output,$missing),'unreadable env must not leak path');
 }
 $env=tempnam(sys_get_temp_dir(),'amazon-wp-');
 if($env===false){$errors[]='cannot create synthetic checker fixture';}
 else {
  try{
   file_put_contents($env,"AMAZON_RETURNS_ENABLED=1\nAMAZON_RETURNS_MODE=production\nAMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH=1\n");
   $lines=[];$exit=-1;
   exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($checker).' '.escapeshellarg('--env-file='.$env).' 2>&1',$lines,$exit);
   $json=json_decode(implode("\n",$lines),true);
   wpSame(0,$exit,'readable synthetic env should work');
   wpSame(true,$json['kill_switch_active']??null,'checker exposes only sanitized effective kill switch state');
   wpSame(false,$json['flags']['SAFE_T_SUBMIT']??null,'effective kill switch applied from readable env');
  } finally {unlink($env);}
 }
}
$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
wpSame(true,str_contains($daemon,'write_profile_revision'),'profile change must force scheduler reevaluation');
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "write-profile-test: OK\n";
