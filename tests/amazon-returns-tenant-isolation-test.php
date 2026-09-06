<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/TenantPersistence.php';

function tiAssert(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}
function tiSame(mixed $expected,mixed $actual,string $message):void
{
    if($expected!==$actual){
        throw new RuntimeException($message.'\nExpected: '.var_export($expected,true).'\nActual: '.var_export($actual,true));
    }
}
function tiThrows(callable $operation,string $message):void
{
    try{$operation();}catch(InvalidArgumentException|RuntimeException){return;}
    throw new RuntimeException($message);
}
function tiDuplicate(string $message):PDOException
{
    $error=new PDOException($message,23000);
    $error->errorInfo=['23000',1062,$message];
    return $error;
}

final class TenantIsolationMemoryPdo extends PDO
{
    public array $tenants=[];
    public array $connections=[];
    public array $cases=[];
    public array $events=[];
    public array $evidence=[];
    public array $cursors=[];
    public array $outbox=[];
    public array $executed=[];
    public array $memory=[];
    public array $next=['case'=>1,'event'=>1,'evidence'=>1,'outbox'=>1];
    public int $lastId=0;
    private bool $transaction=false;

    public function __construct() {}
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        return new TenantIsolationMemoryStatement($this,$query);
    }
    public function lastInsertId(?string $name=null):string|false
    {
        return (string)$this->lastId;
    }
    public function beginTransaction():bool
    {
        if($this->transaction)throw new RuntimeException('Nested test transaction.');
        return $this->transaction=true;
    }
    public function commit():bool
    {
        if(!$this->transaction)return false;
        $this->transaction=false;
        return true;
    }
    public function rollBack():bool
    {
        if(!$this->transaction)return false;
        $this->transaction=false;
        return true;
    }
    public function inTransaction():bool{return $this->transaction;}
}

final class TenantIsolationMemoryStatement extends PDOStatement
{
    private array $rows=[];
    private int $affected=0;

    public function __construct(
        private TenantIsolationMemoryPdo $db,
        private string $query
    ) {}

    public function execute(?array $params=null):bool
    {
        $params??=[];
        $sql=strtoupper(preg_replace('/\s+/',' ',trim($this->query)) ?? trim($this->query));
        $this->rows=[];
        $this->affected=0;
        $this->db->executed[]=['sql'=>$this->query,'params'=>$params];

        if (preg_match('/(?:FROM|UPDATE) (AMAZON_RETURN_(?:REVIEWS|LEARNED_RULES|RULE_APPLICATIONS))\b/', $sql, $match)) {
            tiAssert(str_contains($sql,'TENANT_ID=:TENANT_ID') && str_contains($sql,'AMAZON_CONNECTION_ID=:CONNECTION_ID'), 'Memory SQL lost scope predicates.');
            foreach ($this->db->memory[strtolower($match[1])]??[] as $row) {
                if (!$this->scopeMatches($row,$params)) continue;
                if (isset($params[':id']) && $row['id']!==(int)$params[':id']) continue;
                if (isset($params[':case_id']) && $row['case_id']!==(int)$params[':case_id']) continue;
                if (str_starts_with($sql,'UPDATE')) throw new LogicException('Cross-scope mutation reached an owned fixture.');
                $this->rows[]=$row;
            }
            if (str_contains($sql,'COUNT(*)')) $this->rows=[['count'=>count($this->rows)]];
            return true;
        }

        if(str_starts_with($sql,'INSERT INTO AMAZON_RETURN_CASES')){
            return $this->insertCase($params);
        }
        if(str_starts_with($sql,'SELECT COUNT(*) FROM AMAZON_RETURN_CASES')){
            $this->rows[]=['count'=>$this->countScoped($this->db->cases,$params)];
            return true;
        }
        if(str_contains($sql,'FROM AMAZON_RETURN_CASES')){
            foreach($this->db->cases as $row){
                if(!$this->scopeMatches($row,$params))continue;
                if(isset($params[':id']) && (int)$row['id']!==(int)$params[':id'])continue;
                if(isset($params[':case_id']) && (int)$row['id']!==(int)$params[':case_id'])continue;
                if(isset($params[':order_id']) && $row['amazon_order_id']!==$params[':order_id'])continue;
                if(isset($params[':item_id']) && $row['amazon_order_item_id']!==$params[':item_id'])continue;
                $this->rows[]=$row;
            }
            return true;
        }
        if(str_starts_with($sql,'UPDATE AMAZON_RETURN_CASES')){
            foreach($this->db->cases as $id=>$row){
                if(!$this->scopeMatches($row,$params) || $id!==(int)($params[':id']??0))continue;
                foreach($params as $key=>$value){
                    if(str_starts_with($key,':patch_')){
                        $this->db->cases[$id][substr($key,7)]=$value;
                    }
                }
                $this->affected=1;
            }
            return true;
        }

        if(str_starts_with($sql,'INSERT INTO AMAZON_RETURN_EVENTS')){
            return $this->insertEvent($params);
        }
        if(str_contains($sql,'FROM AMAZON_RETURN_EVENTS')){
            foreach($this->db->events as $row){
                if(!$this->scopeMatches($row,$params))continue;
                if(isset($params[':case_id']) && (int)$row['case_id']!==(int)$params[':case_id'])continue;
                $key=$params[':idempotency_key'] ?? $params[':key'] ?? null;
                if($key!==null && $row['idempotency_key']!==$key)continue;
                $this->rows[]=$row;
            }
            return true;
        }

        if(str_starts_with($sql,'INSERT INTO AMAZON_RETURN_EVIDENCE')){
            return $this->insertEvidence($params);
        }
        if(str_contains($sql,'FROM AMAZON_RETURN_EVIDENCE')){
            foreach($this->db->evidence as $row){
                if(!$this->scopeMatches($row,$params))continue;
                if(isset($params[':case_id']) && (int)$row['case_id']!==(int)$params[':case_id'])continue;
                if(isset($params[':kind']) && $row['kind']!==$params[':kind'])continue;
                if(isset($params[':content_sha256']) && $row['content_sha256']!==$params[':content_sha256'])continue;
                $this->rows[]=$row;
            }
            return true;
        }

        if(str_starts_with($sql,'INSERT INTO AMAZON_RETURN_SOURCE_CURSORS')){
            $key=$this->cursorKey($params);
            $this->db->cursors[$key]=[
                'tenant_id'=>(int)$params[':tenant_id'],
                'amazon_connection_id'=>(int)$params[':amazon_connection_id'],
                'source'=>(string)$params[':source'],
                'cursor_key'=>(string)$params[':cursor_key'],
                'cursor_value'=>(string)$params[':cursor_value'],
                'metadata_json'=>$params[':metadata_json'],
                'observed_at'=>'2026-09-04 20:00:00',
            ];
            $this->affected=1;
            return true;
        }
        if(str_starts_with($sql,'DELETE FROM AMAZON_RETURN_SOURCE_CURSORS')){
            $key=$this->cursorKey($params);
            if(isset($this->db->cursors[$key])){
                unset($this->db->cursors[$key]);
                $this->affected=1;
            }
            return true;
        }
        if(str_contains($sql,'FROM AMAZON_RETURN_SOURCE_CURSORS')){
            $row=$this->db->cursors[$this->cursorKey($params)] ?? null;
            if(is_array($row))$this->rows[]=$row;
            return true;
        }

        if(str_starts_with($sql,'INSERT INTO AMAZON_RETURN_OUTBOX')){
            return $this->insertOutbox($params);
        }
        if(str_contains($sql,'FROM AMAZON_RETURN_OUTBOX')){
            foreach($this->db->outbox as $row){
                if(!$this->scopeMatches($row,$params))continue;
                if(isset($params[':id']) && (int)$row['id']!==(int)$params[':id'])continue;
                if(isset($params[':case_id']) && (int)$row['case_id']!==(int)$params[':case_id'])continue;
                if(isset($params[':idempotency_key']) && $row['idempotency_key']!==$params[':idempotency_key'])continue;
                $kindMatch=true;
                foreach($params as $name=>$value){
                    if(str_starts_with($name,':kind_') && $row['kind']===$value){
                        $kindMatch=true;
                        break;
                    }
                    if(str_starts_with($name,':kind_'))$kindMatch=false;
                }
                if(!$kindMatch)continue;
                if(str_contains($sql,'FOR UPDATE SKIP LOCKED')
                    && !in_array($row['status'],['PENDING','PROCESSING'],true))continue;
                $this->rows[]=$row;
            }
            if(str_contains($sql,'LIMIT 1') && count($this->rows)>1){
                $this->rows=array_slice($this->rows,0,1);
            }
            return true;
        }
        if(str_starts_with($sql,"UPDATE AMAZON_RETURN_OUTBOX SET STATUS='PROCESSING'")){
            return $this->updateOutboxStatus($params,'PROCESSING',true);
        }
        if(str_starts_with($sql,"UPDATE AMAZON_RETURN_OUTBOX SET STATUS='SUCCEEDED'")){
            return $this->updateOutboxStatus($params,'SUCCEEDED',false,'PROCESSING');
        }
        if(str_starts_with($sql,"UPDATE AMAZON_RETURN_OUTBOX SET STATUS='PENDING'")){
            return $this->updateOutboxStatus($params,'PENDING',false,'PROCESSING');
        }

        throw new LogicException('Unexpected isolation SQL: '.$this->query);
    }

    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed
    {
        return array_shift($this->rows)??false;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array
    {
        return $this->rows;
    }
    public function fetchColumn(int $column=0):mixed
    {
        $row=array_shift($this->rows);
        return is_array($row)?(array_values($row)[$column]??false):false;
    }
    public function rowCount():int{return $this->affected;}

    private function insertCase(array $params):bool
    {
        foreach($this->db->cases as $id=>$row){
            if(!$this->scopeMatches($row,$params))continue;
            if($row['amazon_order_id']!==$params[':amazon_order_id'])continue;
            if($row['amazon_order_item_id']!==$params[':amazon_order_item_id'])continue;
            if(($params[':program'] ?? 'UNKNOWN')!=='UNKNOWN'){
                $this->db->cases[$id]['program']=$params[':program'];
            }
            $this->db->lastId=$id;
            $this->affected=1;
            return true;
        }
        $id=$this->db->next['case']++;
        $row=['id'=>$id];
        foreach($params as $key=>$value)$row[ltrim($key,':')]=$value;
        $this->db->cases[$id]=$row;
        $this->db->lastId=$id;
        $this->affected=1;
        return true;
    }

    private function insertEvent(array $params):bool
    {
        foreach($this->db->events as $row){
            if($this->scopeMatches($row,$params)
                && $row['idempotency_key']===$params[':idempotency_key']){
                throw tiDuplicate('Duplicate scoped event key');
            }
        }
        $id=$this->db->next['event']++;
        $this->db->events[$id]=[
            'id'=>$id,
            'tenant_id'=>(int)$params[':tenant_id'],
            'amazon_connection_id'=>(int)$params[':amazon_connection_id'],
            'case_id'=>(int)$params[':case_id'],
            'event_type'=>$params[':event_type'],
            'source'=>$params[':source'],
            'source_event_id'=>$params[':source_event_id'],
            'idempotency_key'=>$params[':idempotency_key'],
            'occurred_at'=>$params[':occurred_at'],
            'payload_json'=>$params[':payload_json'],
            'evidence_sha256'=>$params[':evidence_sha256'],
            'created_at'=>$params[':created_at'],
        ];
        $this->db->lastId=$id;
        $this->affected=1;
        return true;
    }

    private function insertEvidence(array $params):bool
    {
        foreach($this->db->evidence as $row){
            if($this->scopeMatches($row,$params)
                && (int)$row['case_id']===(int)$params[':case_id']
                && $row['kind']===$params[':kind']
                && $row['content_sha256']===$params[':content_sha256']){
                throw tiDuplicate('Duplicate scoped evidence key');
            }
        }
        $id=$this->db->next['evidence']++;
        $this->db->evidence[$id]=[
            'id'=>$id,
            'tenant_id'=>(int)$params[':tenant_id'],
            'amazon_connection_id'=>(int)$params[':amazon_connection_id'],
            'case_id'=>(int)$params[':case_id'],
            'kind'=>$params[':kind'],
            'source'=>$params[':source'],
            'external_id'=>$params[':external_id'],
            'content_sha256'=>$params[':content_sha256'],
            'storage_ref'=>$params[':storage_ref'],
            'metadata_json'=>$params[':metadata_json'],
            'captured_at'=>$params[':captured_at'],
            'created_at'=>'2026-09-04 20:00:00',
        ];
        $this->db->lastId=$id;
        $this->affected=1;
        return true;
    }

    private function insertOutbox(array $params):bool
    {
        foreach($this->db->outbox as $row){
            if($this->scopeMatches($row,$params)
                && $row['idempotency_key']===$params[':idempotency_key']){
                throw tiDuplicate('Duplicate scoped outbox key');
            }
        }
        $id=$this->db->next['outbox']++;
        $this->db->outbox[$id]=[
            'id'=>$id,
            'tenant_id'=>(int)$params[':tenant_id'],
            'amazon_connection_id'=>(int)$params[':amazon_connection_id'],
            'case_id'=>(int)$params[':case_id'],
            'kind'=>$params[':kind'],
            'idempotency_key'=>$params[':idempotency_key'],
            'payload_json'=>$params[':payload_json'],
            'status'=>'PENDING',
            'attempt_count'=>0,
            'available_at'=>'2026-09-04 19:00:00',
            'locked_at'=>null,
            'last_error'=>null,
            'created_at'=>'2026-09-04 19:00:00',
            'updated_at'=>'2026-09-04 19:00:00',
        ];
        $this->db->lastId=$id;
        $this->affected=1;
        return true;
    }

    private function updateOutboxStatus(
        array $params,
        string $newStatus,
        bool $increment,
        ?string $requiredStatus=null
    ):bool {
        $id=(int)($params[':id'] ?? 0);
        $row=$this->db->outbox[$id] ?? null;
        if(!is_array($row) || !$this->scopeMatches($row,$params))return true;
        if($requiredStatus!==null && $row['status']!==$requiredStatus)return true;
        $this->db->outbox[$id]['status']=$newStatus;
        if($increment)$this->db->outbox[$id]['attempt_count']++;
        if($newStatus==='PROCESSING'){
            $this->db->outbox[$id]['locked_at']='2026-09-04 20:00:00';
        }else{
            $this->db->outbox[$id]['locked_at']=null;
        }
        $this->affected=1;
        return true;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function countScoped(array $rows,array $params):int
    {
        return count(array_filter(
            $rows,fn(array $row):bool=>$this->scopeMatches($row,$params)
        ));
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $params */
    private function scopeMatches(array $row,array $params):bool
    {
        return (int)($row['tenant_id'] ?? 0)===(int)($params[':tenant_id'] ?? 0)
            && (int)($row['amazon_connection_id'] ?? 0)
                ===(int)($params[':amazon_connection_id'] ?? $params[':connection_id'] ?? 0);
    }

    /** @param array<string,mixed> $params */
    private function cursorKey(array $params):string
    {
        return implode('|',[
            (int)($params[':tenant_id'] ?? 0),
            (int)($params[':amazon_connection_id'] ?? 0),
            (string)($params[':source'] ?? ''),
            (string)($params[':cursor_key'] ?? ''),
        ]);
    }
}

$db=new TenantIsolationMemoryPdo();
$db->tenants=[
    1=>['id'=>1,'slug'=>'seller-one','status'=>'ACTIVE'],
    2=>['id'=>2,'slug'=>'seller-two','status'=>'ACTIVE'],
];
$db->connections=[
    10=>['id'=>10,'tenant_id'=>1,'connection_key'=>'amazon-primary','status'=>'ACTIVE'],
    20=>['id'=>20,'tenant_id'=>2,'connection_key'=>'amazon-primary','status'=>'ACTIVE'],
];
$p1=SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(1,10));
$p2=SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(2,20));
foreach (['reviews','learnedRules','ruleApplications'] as $property) {
    tiAssert(isset($p1->$property), 'Missing scoped persistence '.$property);
    tiAssert($p1->$property !== $p2->$property, 'Memory repositories must be bound per context.');
}
$caseInput=[
    'amazon_order_id'=>'702-1234567-7654321',
    'amazon_order_item_id'=>'item-shared',
    'marketplace_id'=>'A2Q3Y263D00KWC',
    'quantity_ordered'=>1,
    'program'=>'STANDARD',
    'refund_initiator'=>'AMAZON_AUTOMATIC',
    'physical_status'=>'NOT_RECEIVED',
    'state'=>'AWAITING_RETURN',
];
$case1=$p1->cases->upsertOrderItem($caseInput);
$case2=$p2->cases->upsertOrderItem($caseInput);
tiAssert($case1!==$case2,'Same external order must create distinct tenant case IDs.');
tiSame(1,$p1->cases->countAll(),'Tenant 1 must see one case.');
tiSame(1,$p2->cases->countAll(),'Tenant 2 must see one case.');
tiSame($case1,$p1->cases->findByOrderItem(
    '702-1234567-7654321','item-shared'
)['id'] ?? null,'Tenant 1 order lookup leaked.');
tiSame($case2,$p2->cases->findByOrderItem(
    '702-1234567-7654321','item-shared'
)['id'] ?? null,'Tenant 2 order lookup leaked.');
tiSame(null,$p1->cases->find($case2),'Tenant 1 read tenant 2 case by numeric ID.');
tiThrows(
    fn()=>$p1->cases->update($case2,['state'=>'SAFE_T_ELIGIBLE']),
    'Tenant 1 updated tenant 2 case.'
);

$sharedEventKey=hash('sha256','shared-event-idempotency');
$event=[
    'event_type'=>'REFUND_CONFIRMED',
    'source'=>'SP_API_FINANCES',
    'source_event_id'=>'txn-shared',
    'idempotency_key'=>$sharedEventKey,
    'occurred_at'=>'2026-09-04 20:00:00',
    'payload'=>['amount'=>'100.00','currency'=>'BRL'],
    'evidence_sha256'=>null,
];
$event1=$p1->events->append(['case_id'=>$case1]+$event);
$event2=$p2->events->append(['case_id'=>$case2]+$event);
tiAssert($event1!==$event2,'Same event digest must coexist across tenants.');
tiSame(1,count($p1->events->eventsForCase($case1)),'Tenant 1 event count.');
tiSame(1,count($p2->events->eventsForCase($case2)),'Tenant 2 event count.');
tiThrows(
    fn()=>$p1->events->eventsForCase($case2),
    'Tenant 1 read tenant 2 events.'
);
tiThrows(
    fn()=>$p1->events->append(['case_id'=>$case2]+$event),
    'Tenant 1 appended an event to tenant 2 case.'
);

$sharedEvidence=hash('sha256','shared-photo-bytes');
$evidence=[
    'kind'=>'WAREHOUSE_PHOTO',
    'source'=>'ADMIN_INTAKE',
    'external_id'=>'intake-shared',
    'content_sha256'=>$sharedEvidence,
    'storage_ref'=>'shared/photo.jpg',
    'metadata'=>['mime'=>'image/jpeg','size'=>100],
    'captured_at'=>'2026-09-04 20:01:00',
];
$evidence1=$p1->evidence->record($case1,$evidence);
$evidence2=$p2->evidence->record($case2,$evidence);
tiAssert($evidence1!==$evidence2,'Same evidence hash must coexist across tenants.');
tiSame(1,count($p1->evidence->forCase($case1)),'Tenant 1 evidence count.');
tiSame(1,count($p2->evidence->forCase($case2)),'Tenant 2 evidence count.');
tiThrows(
    fn()=>$p1->evidence->forCase($case2),
    'Tenant 1 read tenant 2 evidence.'
);
tiThrows(
    fn()=>$p1->evidence->record($case2,$evidence),
    'Tenant 1 attached evidence to tenant 2 case.'
);

$p1->cursors->save('GMAIL','history_id','111',['messages'=>1]);
$p2->cursors->save('GMAIL','history_id','222',['messages'=>2]);
tiSame('111',$p1->cursors->load('GMAIL','history_id')['value'] ?? null,'Tenant 1 cursor leaked.');
tiSame('222',$p2->cursors->load('GMAIL','history_id')['value'] ?? null,'Tenant 2 cursor leaked.');
$p1->cursors->clear('GMAIL','history_id');
tiSame(null,$p1->cursors->load('GMAIL','history_id'),'Tenant 1 cursor did not clear.');
tiSame('222',$p2->cursors->load('GMAIL','history_id')['value'] ?? null,'Tenant 1 cleared tenant 2 cursor.');

$sharedOutboxKey=hash('sha256','shared-outbox-idempotency');
$job1=$p1->outbox->enqueue(
    'SAFE_T_READ',$case1,['order_id'=>'702-1234567-7654321'],$sharedOutboxKey
);
$job2=$p2->outbox->enqueue(
    'SAFE_T_READ',$case2,['order_id'=>'702-1234567-7654321'],$sharedOutboxKey
);
tiAssert($job1!==$job2,'Same outbox digest must coexist across tenants.');
$claim1=$p1->outbox->claimBatch(10,['SAFE_T_READ']);
$claim2=$p2->outbox->claimBatch(10,['SAFE_T_READ']);
tiSame([$job1],array_column($claim1,'id'),'Tenant 1 claimed foreign jobs.');
tiSame([$job2],array_column($claim2,'id'),'Tenant 2 claimed foreign jobs.');
tiSame(1,$claim1[0]['tenant_id'] ?? null,'Claim 1 ownership missing.');
tiSame(2,$claim2[0]['tenant_id'] ?? null,'Claim 2 ownership missing.');
tiThrows(
    fn()=>$p1->outbox->markSucceeded($job2),
    'Tenant 1 acknowledged tenant 2 job.'
);
tiSame('PROCESSING',$db->outbox[$job2]['status'],'Cross-tenant acknowledgement changed job 2.');
$p1->outbox->markSucceeded($job1);
$p2->outbox->markSucceeded($job2);
tiSame('SUCCEEDED',$db->outbox[$job1]['status'],'Tenant 1 could not complete own job.');
tiSame('SUCCEEDED',$db->outbox[$job2]['status'],'Tenant 2 could not complete own job.');

// Always-on isolation coverage; real MySQL lifecycle checks live in the memory test.
$p3=SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(1,11));
foreach (['amazon_return_reviews','amazon_return_learned_rules','amazon_return_rule_applications'] as $table) {
    $db->memory[$table]=[['id'=>99,'case_id'=>$case1,'tenant_id'=>1,'amazon_connection_id'=>10,'status'=>'OPEN','version'=>1]];
}
tiSame(99,$p1->reviews->find(99)['id']??null,'Owned memory row is readable.');
$memoryBefore=$db->memory;
foreach ([$p2,$p3] as $foreign) {
    tiSame(null,$foreign->reviews->find(99),'Foreign review read.');
    tiSame([],$foreign->reviews->forCase($case1),'Foreign case reviews.');
    tiSame([],$foreign->reviews->openQueue(),'Foreign review queue.');
    tiSame([],$foreign->learnedRules->active(),'Foreign active rules.');
    tiSame([],$foreign->ruleApplications->forCase($case1),'Foreign applications.');
    tiSame(0,$foreign->ruleApplications->countAll(),'Foreign application count.');
    tiThrows(fn()=>$foreign->reviews->open($case1,'TEST',hash('sha256','context'),[]),'Foreign case review insert.');
    tiThrows(fn()=>$foreign->reviews->decide(99,1,['decision_mode'=>'WAIT','final_action'=>'WAIT','actor'=>'test','source_version'=>'v1']),'Foreign decision.');
    tiThrows(fn()=>$foreign->reviews->saveSuggestion(99,1,[],'test-model'),'Foreign suggestion.');
    tiThrows(fn()=>$foreign->reviews->recordAiFailure(99,1,'RuntimeException'),'Foreign AI failure.');
    tiThrows(fn()=>$foreign->learnedRules->setStatus(99,1,'DISABLED'),'Foreign rule disable.');
    tiThrows(fn()=>$foreign->learnedRules->incrementOutcome(99,'DENIED',hash('sha256','key')),'Foreign outcome counter.');
    tiThrows(fn()=>$foreign->ruleApplications->recordOutcome(99,'DENIED',['event_id'=>1]),'Foreign outcome.');
}
tiSame($memoryBefore,$db->memory,'Cross-scope memory mutations changed fixtures.');
foreach($db->executed as $execution){
    $sql=$execution['sql'];
    if(!preg_match('/amazon_return_(cases|events|evidence|source_cursors|outbox|reviews|learned_rules|rule_applications)/i',$sql))continue;
    tiAssert(str_contains($sql,'tenant_id'),'Integration query missing tenant_id.');
    tiAssert(str_contains($sql,'amazon_connection_id'),'Integration query missing connection scope.');
}
tiSame(2,count($db->tenants),'Integration fixture must contain two tenants.');
tiSame(2,count($db->connections),'Integration fixture must contain two connections.');

echo "amazon-returns-tenant-isolation-test: OK\n";
