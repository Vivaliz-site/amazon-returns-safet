<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/Config.php';

function cfgEnvSame(mixed $expected,mixed $actual,string $message): void {
    if($expected!==$actual)throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true));
}

$tmp=tempnam(sys_get_temp_dir(),'amazon-returns-env-');
if($tmp===false)throw new RuntimeException('Unable to create temporary env fixture.');
file_put_contents($tmp,implode(PHP_EOL,[
    '# runtime fixture',
    'AMAZON_LWA_CLIENT_ID=file-client',
    'AMAZON_LWA_CLIENT_SECRET="file secret with spaces"',
    "AMAZON_LWA_REFRESH_TOKEN='file-refresh'",
    'SAFE_NON_SHELL_VALUE=www.example.com (web) purchase',
]).PHP_EOL);

$previous=getenv('AMAZON_LWA_CLIENT_ID');
putenv('AMAZON_LWA_CLIENT_ID');

try{
    $config=new SvAmazonReturnsConfig(['AMAZON_RETURNS_ENV_FILE'=>$tmp]);
    cfgEnvSame('file-client',$config->get('AMAZON_LWA_CLIENT_ID'),'Runtime env file must backfill missing process variables.');
    cfgEnvSame('file secret with spaces',$config->get('AMAZON_LWA_CLIENT_SECRET'),'Quoted runtime values must be parsed without shell evaluation.');
    cfgEnvSame('file-refresh',$config->get('AMAZON_LWA_REFRESH_TOKEN'),'Single-quoted runtime values must be parsed safely.');
    cfgEnvSame('www.example.com (web) purchase',$config->get('SAFE_NON_SHELL_VALUE'),'Runtime env parser must preserve shell-significant characters as data.');

    putenv('AMAZON_LWA_CLIENT_ID=process-client');
    cfgEnvSame('process-client',$config->get('AMAZON_LWA_CLIENT_ID'),'Process environment must override runtime env file.');

    $override=new SvAmazonReturnsConfig([
        'AMAZON_RETURNS_ENV_FILE'=>$tmp,
        'AMAZON_LWA_CLIENT_ID'=>'override-client',
    ]);
    cfgEnvSame('override-client',$override->get('AMAZON_LWA_CLIENT_ID'),'Explicit constructor override must remain highest precedence.');
}finally{
    if($previous===false)putenv('AMAZON_LWA_CLIENT_ID');
    else putenv('AMAZON_LWA_CLIENT_ID='.$previous);
    @unlink($tmp);
}

echo "config-runtime-env-fallback-test: OK\n";
