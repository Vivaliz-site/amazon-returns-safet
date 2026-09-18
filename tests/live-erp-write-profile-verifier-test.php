<?php
declare(strict_types=1);

$errors=[];
function liveErpAssert(bool $ok,string $message):void{global $errors;if(!$ok)$errors[]=$message;}

$env=tempnam(sys_get_temp_dir(),'live-erp-env-');
if($env===false)throw new RuntimeException('Unable to create temporary env file.');
$profile=realpath(__DIR__.'/../deploy/write-profile.json');
if(!is_string($profile)||$profile==='')throw new RuntimeException('Write profile missing.');
file_put_contents($env,implode("\n",[
    'AMAZON_RETURNS_ENABLED=1',
    'AMAZON_RETURNS_MODE=production',
    'AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH=0',
    'AMAZON_RETURNS_WRITE_PROFILE_FILE='.$profile,
    'AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED=1',
])."\n");

$checker=realpath(__DIR__.'/../scripts/write-profile-check.php');
if(!is_string($checker)||$checker==='')throw new RuntimeException('Write profile checker missing.');
$json=shell_exec('php '.escapeshellarg($checker).' --env-file='.escapeshellarg($env));
unlink($env);
$checked=is_string($json)?json_decode($json,true):null;
liveErpAssert(
    ($checked['flags']['ERP_SALES_RETURN_CREATE']??null)===true,
    'Live write-profile checker must honor the dedicated ERP env gate from --env-file.'
);

$verifier=(string)file_get_contents(__DIR__.'/../scripts/verify-live-tenant-foundation.sh');
liveErpAssert(
    str_contains($verifier,'ERP_SALES_RETURN_CREATE:1'),
    'Live tenant verifier must require the ERP sales-return write gate.'
);
liveErpAssert(
    str_contains($verifier,'erp_sales_return_write_enabled=$(profile_value ERP_SALES_RETURN_CREATE)'),
    'Live tenant verifier must emit the effective ERP sales-return gate.'
);

if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}
echo "live-erp-write-profile-verifier-test: OK\n";
