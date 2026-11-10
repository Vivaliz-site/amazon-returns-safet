<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/amazon-returns/Config.php';
$envFile=null;
foreach(array_slice($argv??[],1) as $arg){
 if(!str_starts_with((string)$arg,'--env-file=') || $envFile!==null){
  fwrite(STDERR,"WRITE_PROFILE_CHECK=FAIL reason=ARGUMENT_INVALID\n");exit(2);
 }
 $envFile=substr((string)$arg,11);
}
if($envFile===null){
 // Running without the service's explicit env file used to report unsafe
 // production defaults instead of the effective daemon write gates.
 fwrite(STDERR,"WRITE_PROFILE_CHECK=FAIL reason=ENV_FILE_REQUIRED\n");exit(2);
}
$override=[];
{
 // Never silently use production write defaults when an explicit env file is unreadable.
 if($envFile==='' || !is_file($envFile) || !is_readable($envFile)){
  fwrite(STDERR,"WRITE_PROFILE_CHECK=FAIL reason=ENV_FILE_UNREADABLE\n");exit(2);
 }
 $lines=@file($envFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
 if($lines===false){
  fwrite(STDERR,"WRITE_PROFILE_CHECK=FAIL reason=ENV_FILE_UNREADABLE\n");exit(2);
 }
 foreach($lines as $line){
  if($line==='' || $line[0]==='#' || !str_contains($line,'='))continue;
  [$key,$value]=explode('=',$line,2);
  if(in_array($key,['AMAZON_RETURNS_ENABLED','AMAZON_RETURNS_MODE','AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH','AMAZON_RETURNS_WRITE_PROFILE_FILE','AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED','AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'],true))$override[$key]=$value;
 }
}
// An explicit env-file must be the sole source of the scoped case canary.
// Never borrow a caller process variable when that file omits the grant.
if(!array_key_exists('AMAZON_RETURNS_WRITE_CANARY_CASE_IDS',$override))$override['AMAZON_RETURNS_WRITE_CANARY_CASE_IDS']='';
if(!isset($override['AMAZON_RETURNS_ENABLED']))$override['AMAZON_RETURNS_ENABLED']='1';
if(!isset($override['AMAZON_RETURNS_MODE']))$override['AMAZON_RETURNS_MODE']='production';
$config=new SvAmazonReturnsConfig($override);
// Match Config::externalWriteAllowed: a missing, blank or malformed value
// keeps the effective kill switch active. Only an explicit recognized OFF
// value permits the narrower profile/tenant/provider checks to proceed.
$killSwitchActive=!in_array(strtolower($config->get('AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH','1')),['0','false','no','off'],true);
// Report dormant capabilities separately: an active kill switch must not
// mask an unrestricted profile that could become effective if switched OFF.
// Read-only diagnostics; no grant, case, permission or configuration mutation.
$unlockedOverride=$override;
$unlockedOverride['AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH']='0';
$unlockedConfig=new SvAmazonReturnsConfig($unlockedOverride);
$actionsIfOff=array_keys(array_filter(
 $unlockedConfig->writeFlags(),static fn(bool $enabled):bool=>$enabled
));
$unscopedWriteRisk=$actionsIfOff!==[] && $unlockedConfig->scopedWriteCaseIds()===null;
echo json_encode([
 'version'=>$config->writeProfileVersion(),
 'kill_switch_active'=>$killSwitchActive,
 'flags'=>$config->writeFlags(),
 'actions_if_kill_switch_off'=>$actionsIfOff,
 'unscoped_write_profile_risk'=>$unscopedWriteRisk,
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
