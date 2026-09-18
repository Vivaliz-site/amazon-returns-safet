<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/Config.php';

$path=__DIR__.'/../includes/amazon-returns/ErpApiRateLimiter.php';
if(!is_file($path))throw new RuntimeException('ERP API rate limiter implementation is missing.');
require_once $path;
require_once __DIR__.'/../includes/amazon-returns/Runtime.php';

function erpRateSame(mixed $expected,mixed $actual,string $message): void {
    if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}

$now=100.0;$sleeps=[];
$clock=static function() use (&$now): float { return $now; };
$sleep=static function(int $microseconds) use (&$now,&$sleeps): void { $sleeps[]=$microseconds;$now+=($microseconds/1000000); };
$limiter=new SvAmazonErpApiRateLimiter(1000,$clock,$sleep);
$limiter->beforeRequest();
erpRateSame([], $sleeps, 'First ERP request must not sleep.');
$now=100.25;
$limiter->beforeRequest();
erpRateSame([750000],$sleeps,'Second ERP request must wait until the configured minimum interval.');

$config=new SvAmazonReturnsConfig(['AMAZON_RETURNS_ERP_READ_INTERVAL_MS'=>'900']);
$fromConfig=SvAmazonErpApiRateLimiter::fromConfig($config,$clock,$sleep);
erpRateSame(900,$fromConfig->minIntervalMs(),'ERP pacing must honor the configured interval.');

erpRateSame(true,is_subclass_of(SvAmazonErpRateLimitException::class,RuntimeException::class),'Rate-limit exception must be a runtime failure.');
erpRateSame(300,SvAmazonReturnsRuntime::erpRateLimitRetryDelaySeconds('erp_sales_returns',['rate_limited'=>true]),'ERP quota exhaustion must retry in five minutes.');
erpRateSame(null,SvAmazonReturnsRuntime::erpRateLimitRetryDelaySeconds('erp_sales_returns',['rate_limited'=>false]),'Successful ERP cycle keeps normal cadence.');
erpRateSame(null,SvAmazonReturnsRuntime::erpRateLimitRetryDelaySeconds('financial',['rate_limited'=>true]),'ERP retry override must not affect other tasks.');

echo "erp-api-rate-limit-test: OK\n";
