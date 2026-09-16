<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/BridgeService.php';
require_once __DIR__.'/../includes/amazon-returns/Runtime.php';

function gbgAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function gbgSame(mixed $e,mixed $a,string $m):void{if($e!==$a)throw new RuntimeException($m.' expected='.json_encode($e).' actual='.json_encode($a));}

final class GbgPdo extends PDO {
    public int $outboxQueries=0;
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false{
        if(str_contains($query,'amazon_return_outbox'))$this->outboxQueries++;
        return new GbgStmt($this,$query);
    }
}
final class GbgStmt extends PDOStatement {
    private array $params=[];
    public function __construct(private GbgPdo $db,private string $sql){}
    public function execute(?array $params=null):bool{$this->params=$params??[];return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{
        if(str_contains($this->sql,'amazon_return_source_cursors')){
            return ['cursor_value'=>'160','metadata_json'=>json_encode(['has_more'=>true]),'observed_at'=>'2026-09-16 12:00:00'];
        }
        return false;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return [];}
    public function fetchColumn(int $column=0):mixed{return 0;}
    public function rowCount():int{return 0;}
}

$db=new GbgPdo();
$p=SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(1,1));
$config=new SvAmazonReturnsConfig([
    'AMAZON_RETURNS_ENABLED'=>'1','AMAZON_RETURNS_MODE'=>'production',
    'SELLER_CENTRAL_BRIDGE_TOKEN'=>'test-bridge-token',
]);
$svc=new SvAmazonReturnsBridgeService($p,$config);
$result=$svc->pull();
gbgSame('NO_JOB',$result['status']??null,'Bridge must not claim Seller Central writes while Gmail catch-up is incomplete.');
gbgSame('GMAIL_CATCHUP_INCOMPLETE',$result['reason']??null,'Bridge gate reason must be explicit.');
gbgSame(0,$db->outboxQueries,'Bridge catch-up gate must run before touching outbox claim SQL.');

gbgAssert(method_exists(SvAmazonReturnsRuntime::class,'gmailCatchupPendingFromCursor'),'Runtime must expose shared persisted-cursor catch-up gate.');
echo "gmail-catchup-bridge-gate-test: OK\n";
