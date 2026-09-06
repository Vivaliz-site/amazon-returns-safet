<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/Schema.php';
require_once __DIR__.'/../includes/amazon-returns/TenantPersistence.php';

function rmcAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rmcPdo(string $socket,string $db):PDO{
    return new PDO(
        'mysql:unix_socket='.$socket.';dbname='.$db,
        getenv('REVIEW_MEMORY_CONCURRENCY_USER') ?: 'root',
        getenv('REVIEW_MEMORY_CONCURRENCY_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
    );
}

$guard=getenv('REVIEW_MEMORY_CONCURRENCY_ALLOW_DESTRUCTIVE');
$socket=(string)(getenv('REVIEW_MEMORY_CONCURRENCY_SOCKET') ?: '');
$adminDsn=(string)(getenv('REVIEW_MEMORY_CONCURRENCY_ADMIN_DSN') ?: '');
if($guard!=='LOCAL_DISPOSABLE_MYSQL_ONLY' || $socket==='' || $adminDsn===''){
    echo "review-memory-concurrency-test: SKIP (explicit disposable MySQL guard/admin DSN/socket required)\n";
    return;
}
rmcAssert(str_starts_with($socket,'/tmp/'),'Concurrency test socket must be under /tmp.');
rmcAssert(str_contains($adminDsn,'unix_socket='.$socket) && str_contains($adminDsn,'dbname=mysql'),
    'Admin DSN must target the guarded /tmp socket and mysql control database.');
$dbName='review_memory_concurrency_'.bin2hex(random_bytes(6));
rmcAssert(preg_match('/^review_memory_concurrency_[a-f0-9]{12}$/',$dbName)===1,'Unsafe fixture DB name.');
$admin=new PDO($adminDsn,getenv('REVIEW_MEMORY_CONCURRENCY_USER') ?: 'root',
    getenv('REVIEW_MEMORY_CONCURRENCY_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$admin->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try{
    $a=rmcPdo($socket,$dbName);
    $b=rmcPdo($socket,$dbName);
    foreach([$a,$b] as $db){
        $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }
    SvAmazonReturnsSchema::ensure($a);
    $a->exec("INSERT INTO amazon_return_connections "
        ."(id,tenant_id,connection_key,label,region,endpoint,marketplace_id) "
        ."VALUES (10,1,'fixture','Fixture','NA','test','A2Q3Y263D00KWC')");
    $a->exec("INSERT INTO amazon_return_cases "
        ."(id,tenant_id,amazon_connection_id,amazon_order_id,amazon_order_item_id,marketplace_id,state) "
        ."VALUES (1,1,10,'702-1111111-2222222','item-1','A2Q3Y263D00KWC','HUMAN_REVIEW')");
    $pA=SvAmazonTenantPersistence::create($a,new SvAmazonTenantContext(1,10));
    $pB=SvAmazonTenantPersistence::create($b,new SvAmazonTenantContext(1,10));
    $hash=hash('sha256','concurrency-context');

    $a->beginTransaction();
    $pA->reviews->openQueue(); // pin an old consistent-read snapshot
    $opened=$pB->reviews->open(1,'AMBIGUOUS',$hash,['origin'=>'B']);
    $same=$pA->reviews->open(1,'AMBIGUOUS',$hash,['origin'=>'A']);
    rmcAssert((int)$same['id']===(int)$opened['id'],'Concurrent duplicate open did not reuse current episode.');
    $a->rollBack();
    $decision=[
        'decision_mode'=>'APPROVED','final_action'=>'WAIT','actor'=>'tester',
        'source_version'=>'concurrency-v1','parameters'=>[],'rationale'=>'fixture',
        'evidence_refs'=>[],'affected_case_preview'=>[1],
    ];
    $a->beginTransaction();
    $pA->reviews->find((int)$opened['id']); // pin OPEN in A snapshot
    $pB->reviews->decide((int)$opened['id'],1,$decision);
    $reopened=$pA->reviews->open(1,'AMBIGUOUS',$hash,['origin'=>'A-after-close']);
    rmcAssert((int)$reopened['id']!==(int)$opened['id'],'Reopen returned the already-decided stale episode.');
    $a->commit();
    $fresh=$pB->reviews->find((int)$reopened['id']);
    rmcAssert(($fresh['status']??null)==='OPEN','Reopened episode is not current/open.');

    $promotionReview=$pB->reviews->open(1,'PROMOTION_RETRY',hash('sha256','promotion'),[]);
    $pB->reviews->decide((int)$promotionReview['id'],1,$decision);
    $definition=[
        'rule_family_key'=>'CONCURRENT_PROMOTION','match'=>['reason'=>'PROMOTION_RETRY'],
        'effect'=>['action'=>'WAIT'],'source_review_id'=>(int)$promotionReview['id'],'specificity'=>1,
    ];
    $a->beginTransaction();
    $pA->learnedRules->list(); // pin learned-rule snapshot before B promotes
    $rule=$pB->learnedRules->promote($definition);
    $retried=$pA->learnedRules->promote($definition);
    rmcAssert((int)$retried['id']===(int)$rule['id'],'Promotion retry did not resolve the current linked rule.');
    $a->rollBack();
    $applicationKey=hash('sha256','concurrency-application');
    $appId=$pB->ruleApplications->record([
        'application_key'=>$applicationKey,'rule_id'=>(int)$rule['id'],'case_id'=>1,
        'signature_hash'=>$hash,'effect_hash'=>hash('sha256','WAIT'),
        'result'=>'MATCH','blockers'=>[],'action_ref'=>null,
    ]);
    $pB->ruleApplications->recordOutcome($appId,'RECOVERED',['financial_event_id'=>99]);
    $pending=$pB->ruleApplications->pendingOutcomes();
    rmcAssert(in_array($appId,array_map(static fn(array $r):int=>(int)$r['id'],$pending),true),
        'Uncounted terminal outcome is not recoverable after a crash window.');
    $pB->learnedRules->incrementOutcome((int)$rule['id'],'RECOVERED',$applicationKey);
    $pB->learnedRules->incrementOutcome((int)$rule['id'],'RECOVERED',$applicationKey);
    $after=$pB->ruleApplications->pendingOutcomes();
    rmcAssert(!in_array($appId,array_map(static fn(array $r):int=>(int)$r['id'],$after),true),
        'Counted terminal outcome remains in recovery queue.');
    $stored=$pB->learnedRules->list(['rule_family_key'=>'CONCURRENT_PROMOTION'])[0]??[];
    rmcAssert((int)($stored['outcome_counters']['RECOVERED']??0)===1,'Recovered counter inflated or was not restored exactly once.');
    echo "review-memory-concurrency-test: OK\n";
}finally{
    foreach([$a??null,$b??null] as $db){
        if($db instanceof PDO && $db->inTransaction())$db->rollBack();
    }
    $a=null;$b=null;
    $admin->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
}
