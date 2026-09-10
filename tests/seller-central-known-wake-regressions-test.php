<?php
declare(strict_types=1);
require_once __DIR__.'/../workers/amazon-returns/daemon.php';

function kwrAssert(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
}
function kwrSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.json_encode($expected).' actual='.json_encode($actual));
}

// I1: a known-date wake must survive only the bounded financial continuation.
kwrAssert(method_exists(SvAmazonReturnsRuntime::class,'knownActionWakeActive'),'Runtime must expose bounded known-action wake provenance.');
kwrAssert(method_exists(SvAmazonReturnsRuntime::class,'applyKnownActionWakeContinuation'),'Runtime must update bounded known-action wake provenance.');
$due=['known_action_wake','sp_api','financial','scheduler'];
$state=[];
kwrAssert(SvAmazonReturnsRuntime::knownActionWakeActive($state,$due),'Current known-date event must activate wake provenance.');
SvAmazonReturnsRuntime::applyKnownActionWakeContinuation($state,true,true,true);
kwrSame(true,$state['known_action_wake_continuation']??false,'Financial continuation must retain wake provenance.');
kwrAssert(SvAmazonReturnsRuntime::knownActionWakeActive($state,['sp_api','financial','scheduler']),'Next bounded finance cycle must inherit wake provenance.');
SvAmazonReturnsRuntime::applyKnownActionWakeContinuation($state,true,false,true);
kwrAssert(!array_key_exists('known_action_wake_continuation',$state),'Resolved continuation must clear wake provenance.');

final class KwrPdo extends PDO {
    public array $rows=[];
    public int $wakeLookups=0;
    private bool $transaction=false;
    public function __construct(
        public bool $poisonSecond=false,
        public bool $failPostEnqueueUpdate=false,
        public bool $crossDeadline=false
    ){}    public function prepare(string $query,array $options=[]):PDOStatement|false{
        return new KwrStatement($this,$query);
    }
    public function inTransaction():bool{return $this->transaction;}
    public function beginTransaction():bool{$this->transaction=true;return true;}
    public function commit():bool{$this->transaction=false;return true;}
    public function rollBack():bool{$this->transaction=false;return true;}
    public function exec(string $statement):int|false{return 0;}
    public function lastInsertId(?string $name=null):string|false{
        return $this->rows===[]?'0':(string)array_key_last($this->rows);
    }
    public function caseRow(int $id):array{
        $now=time();
        $deadline=$this->crossDeadline?$now-60:$now+86400;
        return [
            'id'=>$id,'tenant_id'=>1,'amazon_connection_id'=>10,
            'amazon_order_id'=>'702-0000000-000000'.$id,
            'marketplace_id'=>'A2Q3Y263D00KWC','state'=>'SAFE_T_INFO_REQUESTED',
            'safe_t_id'=>'12345-12345-'.str_pad((string)$id,7,'0',STR_PAD_LEFT),
            'appeal_deadline_at'=>gmdate('Y-m-d H:i:s',$deadline),
            'quantity_ordered'=>1,'quantity_refunded'=>1,'quantity_received'=>0,
            'physical_status'=>'NOT_RECEIVED','refund_initiator'=>'AMAZON_INITIATED',
            'closed_at'=>null,'next_action_at'=>gmdate('Y-m-d H:i:s',$now-120),
        ];
    }
}

final class KwrStatement extends PDOStatement {
    private array $params=[];
    private int $affected=1;
    public function __construct(private KwrPdo $db,private string $sql){}    public function execute(?array $params=null):bool{
        $this->params=$params??[];
        $this->affected=1;
        if(str_starts_with($this->sql,'INSERT INTO amazon_return_outbox')){
            $id=901+count($this->db->rows);
            $this->db->rows[$id]=[
                'id'=>$id,'tenant_id'=>1,'amazon_connection_id'=>10,
                'case_id'=>(int)$this->params[':case_id'],'kind'=>(string)$this->params[':kind'],
                'idempotency_key'=>(string)$this->params[':idempotency_key'],
                'status'=>'PENDING','attempt_count'=>0,
                'available_at'=>gmdate('Y-m-d H:i:s'),
                'payload_json'=>(string)$this->params[':payload_json'],
            ];
        }elseif(str_starts_with($this->sql,'UPDATE amazon_return_outbox')){
            $this->affected=0;
        }elseif(str_starts_with($this->sql,'UPDATE amazon_return_cases SET')
            && $this->db->failPostEnqueueUpdate
            && (int)($this->params[':id']??0)===1
            && array_key_exists(':patch_next_action_at',$this->params)){
            throw new PDOException('fixture post-enqueue update failure');
        }
        return true;
    }
    public function rowCount():int{return $this->affected;}
    public function fetchColumn(int $column=0):mixed{return 0;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{
        if(str_contains($this->sql,'FROM amazon_return_outbox WHERE id=:id')){
            $this->db->wakeLookups++;
            return $this->db->rows[(int)($this->params[':id']??0)]??false;
        }
        if(str_contains($this->sql,'FROM amazon_return_cases')){
            return $this->db->caseRow((int)($this->params[':id']??$this->params[':case_id']??1));
        }
        return false;
    }    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{
        if(str_contains($this->sql,'FROM amazon_return_cases WHERE closed_at IS NULL')){
            return [$this->db->caseRow(1),$this->db->caseRow(2)];
        }
        if(str_contains($this->sql,'SELECT DISTINCT case_id FROM amazon_return_outbox'))return [];
        if(str_contains($this->sql,'FROM amazon_return_events')){
            $caseId=(int)($this->params[':case_id']??0);
            if($this->db->poisonSecond && $caseId===2){
                return [[
                    'id'=>2,'tenant_id'=>1,'amazon_connection_id'=>10,'case_id'=>2,
                    'event_type'=>'CASE_CREATED','source'=>'SYSTEM','source_event_id'=>null,
                    'idempotency_key'=>hash('sha256','fixture-event'),
                    'occurred_at'=>'malformed-fixture-date','payload_json'=>'{}',
                    'evidence_sha256'=>null,'created_at'=>gmdate('Y-m-d H:i:s'),
                ]];
            }
            return [];
        }
        if(str_contains($this->sql,'FROM amazon_return_cases')){
            return [$this->db->caseRow((int)($this->params[':id']??1))];
        }
        return [];
    }
}

function kwrConfig(?string $profileFile=null):SvAmazonReturnsConfig{
    return new SvAmazonReturnsConfig([
        'AMAZON_RETURNS_ENABLED'=>'1','AMAZON_RETURNS_MODE'=>'production',
        'AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'0',
        'AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'=>'',
        'AMAZON_RETURNS_WRITE_PROFILE_FILE'=>$profileFile??dirname(__DIR__).'/deploy/write-profile.json',
        'SELLER_CENTRAL_BROWSER_BRIDGE_URL'=>'','SELLER_CENTRAL_BRIDGE_TOKEN'=>'fixture-token',
    ]);
}

function kwrSchedulerScenario(KwrPdo $db,DateTimeImmutable $cycleNow,?SvAmazonReturnsConfig $config=null):array{
    $root=sys_get_temp_dir().'/kwr-'.bin2hex(random_bytes(5));
    $dir=$root.'/shared/seller-central-wake';
    if(!mkdir($dir,0770,true))throw new RuntimeException('fixture directory failed');
    $path=$dir.'/wake.json';
    $oldRoot=getenv('AMAZON_RETURNS_DEPLOY_ROOT');
    $oldWake=getenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE');
    putenv('AMAZON_RETURNS_DEPLOY_ROOT='.$root);
    putenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE='.$path);
    $thrown=null;$result=null;
    try{
        $daemon=new SvAmazonReturnsDaemon($db,new SvAmazonTenantContext(1,10),$config??kwrConfig());
        $method=new ReflectionMethod($daemon,'runScheduler');
        try{$result=$method->invoke($daemon,$cycleNow,true);}catch(Throwable $e){$thrown=$e;}
        return [
            'result'=>$result,'thrown'=>$thrown,'marker'=>is_file($path),
            'marker_data'=>is_file($path)?json_decode((string)file_get_contents($path),true):null,
            'rows'=>$db->rows,'wake_lookups'=>$db->wakeLookups,
        ];
    }finally{
        if($oldRoot===false)putenv('AMAZON_RETURNS_DEPLOY_ROOT');else putenv('AMAZON_RETURNS_DEPLOY_ROOT='.$oldRoot);
        if($oldWake===false)putenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE');else putenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE='.$oldWake);
        @unlink($path);@rmdir($dir);@rmdir($root.'/shared');@rmdir($root);
    }
}

$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));// I2a: a later unrelated case failure cannot suppress an already-enqueued wake.
$laterFailure=kwrSchedulerScenario(new KwrPdo(poisonSecond:true),$now);
kwrAssert($laterFailure['thrown'] instanceof Throwable,'Later poisoned case must reproduce a scheduler exception.');
kwrSame(1,count($laterFailure['rows']),'First case must already be enqueued before the later failure.');
kwrAssert($laterFailure['marker']===true,'Already-enqueued work must still publish its known-date wake from the exceptional path.');

// I2b: post-enqueue next_action_at failure must not lose the wake candidate.
$updateFailure=kwrSchedulerScenario(new KwrPdo(failPostEnqueueUpdate:true),$now);
kwrAssert($updateFailure['thrown'] instanceof Throwable,'Post-enqueue case update must reproduce an exception.');
kwrSame(1,count($updateFailure['rows']),'Outbox row must exist before the post-enqueue update fails.');
kwrAssert($updateFailure['marker']===true,'Post-enqueue case update failure must not suppress the wake.');

// I3: scheduleDecision may normalize the channel after the cycle timestamp.
$cross=kwrSchedulerScenario(new KwrPdo(crossDeadline:true),$now->modify('-120 seconds'));
kwrSame(null,$cross['thrown'],'Deadline-crossing scenario must complete scheduler processing.');
kwrAssert(count($cross['rows'])>=1,'Deadline-crossing scenario must enqueue a write.');
$firstRow=array_values($cross['rows'])[0];
kwrSame('SELLER_SUPPORT_OPEN',$firstRow['kind']??null,'Fresh schedule normalization must route expired appeal to Seller Support.');
kwrAssert($cross['marker']===true,'Wake candidate must use the actual normalized queued action.');
kwrSame('known-action',$cross['marker_data']['source']??null,'Normalized action wake must retain known-date provenance.');

// The final normalized channel must pass its own write gate before any pending row is created.
$blockedProfile=tempnam(sys_get_temp_dir(),'kwr-profile-');
file_put_contents($blockedProfile,json_encode([
    'version'=>'kwr-gate-v1','SAFE_T_SUBMIT'=>true,'SAFE_T_APPEAL'=>true,
    'SAFE_T_EMAIL_REVIEW'=>true,'SAFE_T_EMAIL_REPLY'=>true,
    'SELLER_SUPPORT_OPEN'=>false,'SELLER_SUPPORT_UPDATE'=>true,
],JSON_THROW_ON_ERROR));
try{
    $blocked=kwrSchedulerScenario(new KwrPdo(crossDeadline:true),$now->modify('-120 seconds'),kwrConfig($blockedProfile));
    kwrSame(null,$blocked['thrown'],'Final-channel gate scenario must be handled without an exception.');
    $pendingSupport=array_filter($blocked['rows'],static fn(array $row):bool=>($row['kind']??'')==='SELLER_SUPPORT_OPEN' && ($row['status']??'')==='PENDING');
    kwrSame(0,count($pendingSupport),'Disabled final Seller Support gate must prevent a pending support write.');
    kwrAssert($blocked['marker']===false,'Disabled final channel must not publish a browser wake.');
}finally{@unlink($blockedProfile);}

// Control: a healthy known-date batch still coalesces to one marker.
$healthy=kwrSchedulerScenario(new KwrPdo(),$now);
kwrSame(null,$healthy['thrown'],'Healthy scheduler control must not throw.');
kwrAssert($healthy['marker']===true,'Healthy known-date batch must publish a marker.');
kwrSame(1,$healthy['result']['seller_central_wake_requested']??null,'Batch must coalesce successful wake publication.');

echo "seller-central-known-wake-regressions-test: OK\n";
