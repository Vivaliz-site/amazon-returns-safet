<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/amazon-returns/Config.php';
$envFile='';
foreach($argv??[] as $arg){
 if(str_starts_with((string)$arg,'--env-file='))$envFile=substr((string)$arg,11);
}
$override=[];
if($envFile!=='' && is_readable($envFile)){
 foreach(file($envFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
  if($line==='' || $line[0]==='#' || !str_contains($line,'='))continue;
  [$key,$value]=explode('=',$line,2);
  if(in_array($key,['AMAZON_RETURNS_ENABLED','AMAZON_RETURNS_MODE','AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH','AMAZON_RETURNS_WRITE_PROFILE_FILE'],true))$override[$key]=$value;
 }
}
if(!isset($override['AMAZON_RETURNS_ENABLED']))$override['AMAZON_RETURNS_ENABLED']='1';
if(!isset($override['AMAZON_RETURNS_MODE']))$override['AMAZON_RETURNS_MODE']='production';
$config=new SvAmazonReturnsConfig($override);
echo json_encode(['version'=>$config->writeProfileVersion(),'flags'=>$config->writeFlags()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
