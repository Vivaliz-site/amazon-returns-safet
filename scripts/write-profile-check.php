<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/amazon-returns/Config.php';
$envFile='';
$envFileSpecified=false;
foreach($argv??[] as $arg){
 if(str_starts_with((string)$arg,'--env-file=')){
  $envFileSpecified=true;
  $envFile=substr((string)$arg,11);
 }
}
$override=[];
if($envFileSpecified){
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
  if(in_array($key,['AMAZON_RETURNS_ENABLED','AMAZON_RETURNS_MODE','AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH','AMAZON_RETURNS_WRITE_PROFILE_FILE','AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED'],true))$override[$key]=$value;
 }
}
if(!isset($override['AMAZON_RETURNS_ENABLED']))$override['AMAZON_RETURNS_ENABLED']='1';
if(!isset($override['AMAZON_RETURNS_MODE']))$override['AMAZON_RETURNS_MODE']='production';
$config=new SvAmazonReturnsConfig($override);
$killSwitchActive=in_array(strtolower($config->get('AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH','0')),['1','true','yes','on'],true);
echo json_encode(['version'=>$config->writeProfileVersion(),'kill_switch_active'=>$killSwitchActive,'flags'=>$config->writeFlags()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
