<?php
declare(strict_types=1);

function scWakeAssert(bool $condition,string $message): void {
    if(!$condition) throw new RuntimeException($message);
}
function scWakeSame(mixed $expected,mixed $actual,string $message): void {
    if($expected!==$actual) throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true));
}

$helper=__DIR__.'/../includes/amazon-returns/SellerCentralWake.php';
scWakeAssert(is_file($helper),'Seller Central wake helper must exist.');
require_once $helper;

$old=getenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE');
putenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE');
scWakeSame(false,SvAmazonSellerCentralWake::request('known-action',1,new DateTimeImmutable('2026-09-10T12:00:00Z')),'Wake must be disabled when no marker path is configured.');

$root=sys_get_temp_dir().'/amazon-returns-wake-test-'.bin2hex(random_bytes(5));
$dir=$root.'/seller-central-wake';
scWakeAssert(mkdir($dir,0770,true),'Unable to create wake test directory.');
$path=$dir.'/wake.json';
putenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE='.$path);

try {
    scWakeSame(true,SvAmazonSellerCentralWake::request('known-action',1,new DateTimeImmutable('2026-09-10T12:00:00Z')),'Configured wake request must publish an atomic marker.');
    scWakeAssert(is_file($path),'Wake marker was not published.');
    $raw=(string)file_get_contents($path);
    $marker=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    scWakeSame(['version','requested_at','source','attempt'],array_keys($marker),'Wake marker must contain only non-business operational metadata.');
    scWakeSame(1,$marker['version'],'Wake marker schema version.');
    scWakeSame('2026-09-10T12:00:00+00:00',$marker['requested_at'],'Wake timestamp must be normalized to UTC.');
    scWakeSame('known-action',$marker['source'],'Wake source must be explicit.');
    scWakeSame(1,$marker['attempt'],'Initial event attempt must be one.');
    foreach(['order','case','customer','message','evidence','token','cookie','password','otp','totp'] as $forbidden){
        scWakeAssert(stripos($raw,$forbidden)===false,'Wake marker must not contain '.$forbidden.' data.');
    }
    $leftovers=glob($dir.'/.wake.*.tmp');
    scWakeSame([],$leftovers===false?[]:$leftovers,'Atomic publish must not leave temporary marker files.');

    $thrown=false;
    try{SvAmazonSellerCentralWake::request('known action with spaces',1);}catch(InvalidArgumentException){$thrown=true;}
    scWakeAssert($thrown,'Wake source must reject unsafe values.');
    $thrown=false;
    try{SvAmazonSellerCentralWake::request('retry',4);}catch(InvalidArgumentException){$thrown=true;}
    scWakeAssert($thrown,'Event attempt must be limited to the approved three-attempt window.');

    putenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE='.$root.'/wrong.json');
    $thrown=false;
    try{SvAmazonSellerCentralWake::request('known-action',1);}catch(InvalidArgumentException){$thrown=true;}
    scWakeAssert($thrown,'Wake marker path must be constrained to seller-central-wake/wake.json.');
} finally {
    if($old===false) putenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE');
    else putenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE='.$old);
    @unlink($path);
    @rmdir($dir);
    @rmdir($root);
}

$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
foreach([
    "SellerCentralWake.php",
    "knownActionWake",
    "SvAmazonSellerCentralWake::request",
    "findOwned",
    "SAFE_T_SUBMIT",
    "SAFE_T_APPEAL",
    "SELLER_SUPPORT_OPEN",
    "SELLER_SUPPORT_UPDATE",
] as $needle){
    scWakeAssert(str_contains($daemon,$needle),'Known-date scheduler must integrate Seller Central wake contract: '.$needle);
}
scWakeAssert(str_contains($daemon,"status'] ?? '')==='PENDING'") || str_contains($daemon,"status']??'')==='PENDING'"),'Wake integration must verify the outbox row is still pending.');
scWakeAssert(str_contains($daemon,"sellerCentralBridgeMode()==='polling'") || str_contains($daemon,"sellerCentralBridgeMode() === 'polling'"),'Wake integration must only run for polling bridge mode.');

echo "seller-central-event-wake-test: OK\n";
