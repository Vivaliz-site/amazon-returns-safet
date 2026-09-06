<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/Schema.php';
require_once __DIR__.'/../includes/amazon-returns/TenantPersistence.php';
function rmAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rmCanonical(array $value): array {
    foreach ($value as &$item) if (is_array($item)) $item=rmCanonical($item);
    unset($item);
    if (!array_is_list($value)) ksort($value);
    return $value;
}
function rmThrows(callable $f): void {
    try { $f(); } catch (RuntimeException|InvalidArgumentException|JsonException $e) { return; }
    throw new RuntimeException('Expected rejected mutation');
}
$ddl=implode("\n", SvAmazonReturnsSchema::statements());
foreach (['amazon_return_reviews','amazon_return_learned_rules','amazon_return_rule_applications'] as $table) {
    rmAssert(str_contains($ddl,"CREATE TABLE IF NOT EXISTS `{$table}`"),"missing {$table}");
}
foreach (['uq_review_open_key','uq_learned_rule_version','uq_rule_application_key'] as $key) {
    rmAssert(str_contains($ddl, 'UNIQUE KEY `'.$key.'` (`tenant_id`, `amazon_connection_id`,'), 'Unscoped unique key '.$key);
}
foreach (['scripts/verify-migration.sh','scripts/verify-live-tenant-foundation.sh'] as $path) {
    $source=file_get_contents(__DIR__.'/../'.$path);
    foreach (['amazon_return_reviews','amazon_return_learned_rules','amazon_return_rule_applications','source_review_id','resulting_rule_id'] as $needle) rmAssert(str_contains($source,$needle),'Ownership verifier missing '.$path.':'.$needle);
}
foreach ([SvAmazonReviewRepository::class=>['open','lock','decide','saveSuggestion','recordAiFailure','find','forCase','openQueue','countOpen','countOpenForCase','countOpenByReason','countAiFailures'],
    SvAmazonLearnedRuleRepository::class=>['active','promote','revision','list','setStatus','incrementOutcome'],
    SvAmazonRuleApplicationRepository::class=>['record','forCase','countAll','pendingOutcomes','recordOutcome']] as $class=>$methods) {
    foreach ($methods as $method) rmAssert(method_exists($class,$method), 'Missing '.$class.'::'.$method);
}

// Opt-in real MySQL integration. Only session-local TEMPORARY tables are created.
$dsn=getenv('REVIEW_MEMORY_TEST_DSN');
if (!$dsn) { echo "review-memory-persistence-test: schema/interfaces OK; MySQL integration SKIP (REVIEW_MEMORY_TEST_DSN unset)\n"; return; }
$db=new PDO($dsn, getenv('REVIEW_MEMORY_TEST_USER') ?: 'root', getenv('REVIEW_MEMORY_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
foreach (SvAmazonReturnsSchema::statements() as $sql) $db->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE',$sql));
$db->exec("INSERT INTO amazon_return_connections (id,tenant_id,connection_key,label,region,endpoint,marketplace_id) VALUES (10,1,'a','a','NA','test','test'),(11,1,'b','b','NA','test','test'),(20,2,'a','a','NA','test','test')");
$db->exec("INSERT INTO amazon_return_cases (id,tenant_id,amazon_connection_id,amazon_order_id,amazon_order_item_id,marketplace_id,state) VALUES (1,1,10,'a','a','test','HUMAN_REVIEW'),(2,1,11,'b','b','test','HUMAN_REVIEW'),(3,2,20,'c','c','test','HUMAN_REVIEW')");
$p=SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(1,10));
$others=[SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(1,11)),SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(2,20))];
$hash=hash('sha256','context');
$review=$p->reviews->open(1,'AMBIGUOUS',$hash,['facts'=>['wait'=>true]]);
$id=(int)$review['id'];
rmAssert($p->reviews->open(1,'AMBIGUOUS',$hash,[])['id']===$review['id'],'Duplicate open episode');
rmAssert($review['open_key']===hash('sha256','1|'.$hash),'Wrong open key');
foreach ($others as $other) {
    rmAssert($other->reviews->find($id)===null,'Review read leaked');
    rmThrows(fn()=>$other->reviews->open(1,'AMBIGUOUS',$hash,[]));
    rmThrows(fn()=>$other->reviews->decide($id,1,[]));
    rmThrows(fn()=>$other->reviews->saveSuggestion($id,1,[],'test'));
    rmThrows(fn()=>$other->reviews->recordAiFailure($id,1,'RuntimeException'));
    rmAssert($other->reviews->countOpen()===0,'Queue leaked');
}
rmThrows(fn()=>$p->reviews->lock($id));
$db->beginTransaction();
rmAssert($p->reviews->lock($id)['id']===$review['id'],'Transactional lock');
$db->rollBack();
$suggestion=['action'=>'WAIT','rationale'=>'test'];
$review=$p->reviews->saveSuggestion($id,1,$suggestion,'test-model');
rmAssert((int)$review['version']===2 && $review['ai_suggestion']===$suggestion,'Suggestion not preserved');
rmThrows(fn()=>$p->reviews->saveSuggestion($id,1,[],'stale'));
$review=$p->reviews->recordAiFailure($id,2,'RuntimeException');
rmAssert($p->reviews->countAiFailures()===1 && (int)$review['version']===3,'Failure telemetry');
rmAssert($p->reviews->countOpenForCase(1)===1 && $p->reviews->countOpenByReason('AMBIGUOUS')===1,'Queue counts');
$decision=['decision_mode'=>'WAIT','final_action'=>'WAIT','parameters'=>['date'=>'2026-10-01'],'rationale'=>'human','actor'=>'tester','source_version'=>'test-v1','evidence_refs'=>[],'affected_case_preview'=>[1]];
rmThrows(fn()=>$p->reviews->decide($id,2,$decision));
$review=$p->reviews->decide($id,3,$decision);
rmAssert($review['status']==='DECIDED' && $review['open_key']===null && rmCanonical($review['human_decision'])===rmCanonical($decision),'Decision audit');
rmAssert($review['ai_suggestion']===$suggestion,'Decision erased AI');
rmThrows(fn()=>$p->reviews->decide($id,4,$decision));
$secondReview=$p->reviews->open(1,'AMBIGUOUS',$hash,[]);
rmAssert($secondReview['id']!==$review['id'],'Closed episode prevents later review');
$definition=['rule_family_key'=>hash('sha256','family'),'match'=>['reason'=>'AMBIGUOUS'],'effect'=>['action'=>'WAIT'],'source_review_id'=>$id,'specificity'=>1];
$revision=$p->learnedRules->revision();
$rule=$p->learnedRules->promote($definition);
rmAssert((int)$rule['version']===1 && $p->learnedRules->revision()!==$revision,'Promotion/revision');
rmAssert($p->learnedRules->promote($definition)['id']===$rule['id'],'Promotion retry created a duplicate version');
rmThrows(fn()=>$p->learnedRules->promote(array_replace($definition,['effect'=>['action'=>'DIFFERENT']])));
rmThrows(fn()=>$p->learnedRules->promote(array_replace($definition,['source_review_id'=>(int)$secondReview['id']])));
foreach ($others as $other) {
    rmThrows(fn()=>$other->learnedRules->promote($definition));
    rmThrows(fn()=>$other->learnedRules->setStatus((int)$rule['id'],1,'DISABLED'));
    rmAssert($other->learnedRules->active()===[],'Rule read leaked');
}
$application=['application_key'=>hash('sha256','application'),'rule_id'=>(int)$rule['id'],'case_id'=>1,'signature_hash'=>$hash,'effect_hash'=>hash('sha256','effect'),'result'=>'MATCH','blockers'=>[],'action_ref'=>null];
$appId=$p->ruleApplications->record($application);
rmAssert($p->ruleApplications->record($application)===$appId && $p->ruleApplications->countAll()===1,'Application dedup');
rmThrows(fn()=>$p->ruleApplications->record(array_replace($application,['result'=>'CHANGED'])));
foreach ($others as $other) {
    rmThrows(fn()=>$other->ruleApplications->record($application));
    rmThrows(fn()=>$other->ruleApplications->recordOutcome($appId,'DENIED',[]));
    rmThrows(fn()=>$other->learnedRules->incrementOutcome((int)$rule['id'],'DENIED',$application['application_key']));
    rmAssert($other->ruleApplications->forCase(1)===[] && $other->ruleApplications->countAll()===0,'Application read leaked');
}
$p->ruleApplications->recordOutcome($appId,'APPROVED_PENDING_CREDIT',['event_id'=>10]);
rmAssert(count($p->ruleApplications->pendingOutcomes())===1,'Approval is not recovery');
$p->learnedRules->incrementOutcome((int)$rule['id'],'APPROVED_PENDING_CREDIT',$application['application_key']);
$p->learnedRules->incrementOutcome((int)$rule['id'],'APPROVED_PENDING_CREDIT',$application['application_key']);
rmAssert($p->learnedRules->list()[0]['outcome_counters']['APPROVED_PENDING_CREDIT']===1,'Duplicate counter');
$p->ruleApplications->recordOutcome($appId,'RECOVERED',['financial_event_id'=>11]);
$p->learnedRules->incrementOutcome((int)$rule['id'],'RECOVERED',$application['application_key']);
rmAssert($p->ruleApplications->pendingOutcomes()===[],'Terminal outcome remains pending');
$p->reviews->decide((int)$secondReview['id'],1,$decision);
$rule2=$p->learnedRules->promote(array_replace($definition,['source_review_id'=>(int)$secondReview['id'],'effect'=>['action'=>'CHECK_FINANCES']]));
rmAssert((int)$rule2['version']===2 && count($p->learnedRules->active())===1,'Version supersession');
rmAssert($p->learnedRules->list(['status'=>'SUPERSEDED'])[0]['effect']===$definition['effect'],'Historical logic changed');
$revision=$p->learnedRules->revision();
rmThrows(fn()=>$p->learnedRules->setStatus((int)$rule2['id'],1,'DISABLED'));
$p->learnedRules->setStatus((int)$rule2['id'],2,'DISABLED');
rmAssert($p->learnedRules->active()===[] && $revision!==$p->learnedRules->revision(),'Disable revision');
rmThrows(fn()=>$p->learnedRules->setStatus((int)$rule2['id'],2,'ACTIVE'));
rmAssert($p->ruleApplications->countAll()===1 && count($p->reviews->forCase(1))===2,'History lost');
$db->beginTransaction();
$thirdReview=$p->reviews->open(1,'AMBIGUOUS',$hash,[]);
$p->reviews->decide((int)$thirdReview['id'],1,$decision);
$p->learnedRules->promote(array_replace($definition,['source_review_id'=>(int)$thirdReview['id']]));
$db->rollBack();
rmAssert(count($p->learnedRules->list())===2,'Repository committed caller transaction');
rmAssert(count($p->reviews->forCase(1))===2,'Review escaped caller rollback');
$exception=$p->reviews->open(1,'EXCEPTION',$hash,[]);
$p->reviews->decide((int)$exception['id'],1,array_replace($decision,['decision_mode'=>'EXCEPTION']));
rmThrows(fn()=>$p->learnedRules->promote(array_replace($definition,['source_review_id'=>(int)$exception['id']])));
$retry=$p->reviews->open(1,'RETRY',$hash,[]);
$p->reviews->decide((int)$retry['id'],1,$decision);
$db->beginTransaction();
rmThrows(fn()=>$p->learnedRules->promote(array_replace($definition,['source_review_id'=>(int)$retry['id'],'effect'=>['invalid'=>NAN]])));
rmAssert($db->inTransaction(),'Failed promotion ended caller transaction');
rmAssert(count($p->learnedRules->list())===2,'Failed promotion left partial rule');
rmAssert($p->reviews->find((int)$retry['id'])['resulting_rule_id']===null,'Failed promotion linked a rule');
$db->commit();
rmThrows(fn()=>$p->ruleApplications->recordOutcome($appId,'DENIED',['event_id'=>12]));
rmThrows(fn()=>$p->reviews->recordAiFailure((int)$retry['id'],2,'secret message text'));
echo "review-memory-persistence-test: schema/interfaces and MySQL integration OK\n";
