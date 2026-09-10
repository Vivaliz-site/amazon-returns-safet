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

require_once __DIR__.'/../workers/amazon-returns/daemon.php';

$oldRoot=getenv('AMAZON_RETURNS_DEPLOY_ROOT');
$old=getenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE');
putenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE');
scWakeSame(false,SvAmazonSellerCentralWake::request('known-action',1,new DateTimeImmutable('2026-09-10T12:00:00Z')),'Wake must be disabled when no marker path is configured.');

$root=sys_get_temp_dir().'/amazon-returns-wake-test-'.bin2hex(random_bytes(5));
$dir=$root.'/shared/seller-central-wake';
putenv('AMAZON_RETURNS_DEPLOY_ROOT='.$root);
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

    foreach(['known action with spaces','702-1234567-7654321','case-42','token','cookie','otp','totp','evidence'] as $source){
        $thrown=false;
        try{SvAmazonSellerCentralWake::request($source,1);}catch(InvalidArgumentException){$thrown=true;}
        scWakeAssert($thrown,'Wake source must reject arbitrary business or secret text.');
        scWakeSame($raw,file_get_contents($path),'Rejected source must leave the marker unchanged.');
    }
    clearstatcache(true,$path);
    scWakeSame(0660,fileperms($path)&0777,'Published marker permissions must be restricted to owner/group.');
    $oldHandle=fopen($path,'rb');
    scWakeSame(true,SvAmazonSellerCentralWake::request('known-action',1,new DateTimeImmutable('2026-09-10T10:00:00-03:00')),'Refresh must succeed.');
    scWakeSame($raw,stream_get_contents($oldHandle),'Atomic refresh must replace the inode without modifying an existing reader.');
    fclose($oldHandle);
    scWakeSame('2026-09-10T13:00:00+00:00',json_decode(file_get_contents($path),true)['requested_at'],'Refreshed marker must be complete and UTC.');

    foreach([$root.'/seller-central-wake/wake.json',$dir.'/../seller-central-wake/wake.json'] as $unsafePath){
        putenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE='.$unsafePath);
        $thrown=false;
        try{SvAmazonSellerCentralWake::request();}catch(InvalidArgumentException){$thrown=true;}
        scWakeAssert($thrown,'Paths outside the canonical shared wake directory must fail closed.');
    }
    scWakeAssert(symlink($root,$root.'/alias'),'Create ancestor symlink fixture.');
    putenv('AMAZON_RETURNS_DEPLOY_ROOT='.$root.'/alias');
    putenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE='.$root.'/alias/shared/seller-central-wake/wake.json');
    $thrown=false;
    try{SvAmazonSellerCentralWake::request();}catch(InvalidArgumentException){$thrown=true;}
    scWakeAssert($thrown,'Symlink ancestors must not redirect the marker.');
    unlink($root.'/alias');
    putenv('AMAZON_RETURNS_DEPLOY_ROOT='.$root);
    putenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE='.$path);

    unlink($path);
    file_put_contents($root.'/target','fixture target stays unchanged');
    scWakeAssert(symlink($root.'/target',$path),'Create marker symlink fixture.');
    $thrown=false;
    try{SvAmazonSellerCentralWake::request();}catch(InvalidArgumentException){$thrown=true;}
    scWakeAssert($thrown,'A marker symlink must be rejected.');
    scWakeSame('fixture target stays unchanged',file_get_contents($root.'/target'),'Symlink target must remain unchanged.');
    unlink($path);
    unlink($root.'/target');

    putenv('AMAZON_RETURNS_DEPLOY_ROOT='.$root.'/missing');
    putenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE='.$root.'/missing/shared/seller-central-wake/wake.json');
    $thrown=false;
    try{SvAmazonSellerCentralWake::request();}catch(RuntimeException){$thrown=true;}
    scWakeAssert($thrown,'An unprovisioned directory must report an operational failure.');
    scWakeAssert(!file_exists($root.'/missing'),'Application must never create deployment directories.');
    putenv('AMAZON_RETURNS_DEPLOY_ROOT='.$root);
    putenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE='.$path);

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
    if($oldRoot===false)putenv('AMAZON_RETURNS_DEPLOY_ROOT');
    else putenv('AMAZON_RETURNS_DEPLOY_ROOT='.$oldRoot);
    @unlink($root.'/alias');
    @unlink($root.'/target');
    @unlink($path);
    @rmdir($dir);
    @rmdir($root.'/shared');
    @rmdir($root);
}

// Execute the daemon's post-scheduling wake boundary with the real scoped outbox.
// The only double is PDO: no database, external API or production file is touched.
final class ScWakePdo extends PDO {
    public array $queries=[];
    public function __construct(public ?array $row){}
    public function prepare(string $query,array $options=[]):PDOStatement|false{
        return new ScWakeStatement($this,$query);
    }
}
final class ScWakeStatement extends PDOStatement {
    private array $params=[];
    public function __construct(private ScWakePdo $db,private string $sql){}
    public function execute(?array $params=null):bool{
        $this->params=$params??[];
        $this->db->queries[]=['sql'=>$this->sql,'params'=>$this->params];
        scWakeAssert(str_contains($this->sql,'tenant_id=:tenant_id') && str_contains($this->sql,'amazon_connection_id=:amazon_connection_id'),'Outbox lookup must scope both tenant and connection.');
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{
        $row=$this->db->row;
        if($row===null)return false;
        foreach(['id','tenant_id','amazon_connection_id'] as $key){
            if(($row[$key]??null)!==($this->params[':'.$key]??null))return false;
        }
        return $row;
    }
}
$root=sys_get_temp_dir().'/amazon-returns-wake-test-'.bin2hex(random_bytes(5));
$dir=$root.'/shared/seller-central-wake';
scWakeAssert(mkdir($dir,0770,true),'Create isolated daemon wake fixture.');
$path=$dir.'/wake.json';
putenv('AMAZON_RETURNS_DEPLOY_ROOT='.$root);
putenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE='.$path);
$now=new DateTimeImmutable('2026-09-10T12:00:00Z');
$base=['id'=>901,'tenant_id'=>1,'amazon_connection_id'=>10,'kind'=>'SAFE_T_SUBMIT',
    'status'=>'PENDING','available_at'=>'2026-09-10 12:00:00',
    'payload_json'=>'{"order_id":"fixture-order","message":"fixture-message","evidence":"fixture-evidence"}'];
$checks=[];
foreach(['SAFE_T_SUBMIT','SAFE_T_APPEAL','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'] as $kind){
    $checks[]=[$kind,true,'polling',array_replace($base,['kind'=>$kind]),true];
}
foreach(['SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SAFE_T_READ','WAIT'] as $kind){
    $checks[]=[$kind,true,'polling',array_replace($base,['kind'=>$kind]),false];
}
foreach(['SUCCEEDED','SUPERSEDED','PROCESSING','DEAD_LETTER'] as $status){
    $checks[]=[$status,true,'polling',array_replace($base,['status'=>$status]),false];
}
foreach(['2026-09-10 12:00:01','invalid','yesterday','2026-02-30 00:00:00','',null] as $date){
    $checks[]=['deferred/invalid',true,'polling',array_replace($base,['available_at'=>$date]),false];
}
$checks[]=['routine',false,'polling',$base,false];
$checks[]=['direct',true,'direct',$base,false];
$checks[]=['unavailable',true,'unavailable',$base,false];
$checks[]=['missing row',true,'polling',null,false];
$checks[]=['foreign tenant',true,'polling',array_replace($base,['tenant_id'=>2]),false];
$checks[]=['foreign connection',true,'polling',array_replace($base,['amazon_connection_id'=>20]),false];
$checks[]=['different kind',true,'polling',array_replace($base,['kind'=>'SAFE_T_EMAIL_REPLY']),false];
try {
    $method=new ReflectionMethod(SvAmazonReturnsDaemon::class,'requestSellerCentralWake');
    foreach($checks as [$label,$known,$mode,$row,$expected]){
        if(is_file($path))unlink($path);
        $db=new ScWakePdo($row);
        $config=new SvAmazonReturnsConfig([
            'SELLER_CENTRAL_BRIDGE_TOKEN'=>$mode==='polling'?'fixture':'',
            'SELLER_CENTRAL_BROWSER_BRIDGE_URL'=>$mode==='direct'?'https://example.invalid':'',
        ]);
        $daemon=new SvAmazonReturnsDaemon($db,new SvAmazonTenantContext(1,10),$config);
        $action=in_array($label,['SAFE_T_SUBMIT','SAFE_T_APPEAL','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SAFE_T_READ','WAIT'],true)?$label:'SAFE_T_SUBMIT';
        scWakeSame($expected,$method->invoke($daemon,901,$action,$known,$now),$label.' wake result.');
        scWakeSame($expected,is_file($path),$label.' persisted marker.');
        if($expected){
            $marker=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
            scWakeSame(['version','requested_at','source','attempt'],array_keys($marker),'Daemon marker has exactly the operational schema.');
            scWakeSame('known-action',$marker['source'],'Daemon cannot copy business payload into source.');
            scWakeSame(1,$marker['attempt'],'A new action starts at attempt one.');
            scWakeAssert(!str_contains(file_get_contents($path),'fixture-'),'Outbox business data must never reach the marker.');
        }
    }
} finally {
    if($old===false)putenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE');
    else putenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE='.$old);
    if($oldRoot===false)putenv('AMAZON_RETURNS_DEPLOY_ROOT');
    else putenv('AMAZON_RETURNS_DEPLOY_ROOT='.$oldRoot);
    @unlink($path);
    @rmdir($dir);
    @rmdir($root.'/shared');
    @rmdir($root);
}

echo "seller-central-event-wake-test: OK\n";
