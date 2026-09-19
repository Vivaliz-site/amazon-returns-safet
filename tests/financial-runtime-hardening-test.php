<?php
declare(strict_types=1);
require_once __DIR__.'/amazon-returns-tenant-runtime-repositories-test.php';
require_once __DIR__.'/../includes/amazon-returns/FinancialRefresh.php';
require_once __DIR__.'/../workers/amazon-returns/reconcile.php';
function frhSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
if(!method_exists(SvAmazonFinancialRefresh::class,'safeSchedule')){fwrite(STDERR,"Missing safe financial scheduling boundary\n");exit(1);}
if(!method_exists(SvAmazonReturnCaseRepository::class,'financialCasesAfter')){fwrite(STDERR,"Missing keyset financial reconciliation paging\n");exit(1);}
final class FinancialGateFailurePdo extends PDO {
 public function __construct(){}
 public function prepare(string $query,array $options=[]):PDOStatement|false{throw new PDOException('simulated cursor outage');}
}
$db=new FinancialGateFailurePdo();$p=SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(3,30));
$plan=SvAmazonFinancialRefresh::safeSchedule(['bootstrap'],true,$p);
frhSame(['bootstrap'],$plan['due'],'cursor outage must not throw out of daemon planning');
frhSame('FAILED',$plan['gate']['status']??null,'cursor outage is explicit failed gate');
frhSame('PDOException',$plan['gate']['error_class']??null,'gate records error class without secret payloads');
foreach(['nextReconciliationBatch','recordReconciliationBatch'] as $method){if(!method_exists(SvAmazonFinancialRefresh::class,$method)){fwrite(STDERR,"Missing bounded reconciliation rotation: $method\n");exit(1);}}
$db=new TenantRuntimeRepoPdo();$p=SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(3,30));
$first=[];for($i=1;$i<=251;$i++)$first[]=['id'=>$i];
$db->push(['fetch'=>false]);$db->push(['rows'=>$first]);
$batch1=SvAmazonFinancialRefresh::nextReconciliationBatch($p,250);
frhSame(250,count($batch1['cases']),'one tick must be bounded to 250 cases');
frhSame(true,$batch1['has_more'],'lookahead must preserve continuation');
$db->push([]);SvAmazonFinancialRefresh::recordReconciliationBatch($p,$batch1);
$save=end($db->executed);frhSame('250',(string)($save['params'][':cursor_value']??''),'reconciliation cursor advances after processed page');
$db->push(['fetch'=>['cursor_value'=>'250','metadata_json'=>json_encode(['has_more'=>true]),'observed_at'=>null]]);
$db->push(['rows'=>[['id'=>251],['id'=>252]]]);
$batch2=SvAmazonFinancialRefresh::nextReconciliationBatch($p,250);
frhSame([251,252],array_map(fn($r)=>(int)$r['id'],$batch2['cases']),'next tick must continue after prior page');
frhSame(false,$batch2['has_more'],'final page closes continuation');
$queries=array_values(array_filter($db->executed,fn(array $e):bool=>str_contains($e['sql'],'expected_reimbursement_amount>0')));
frhSame(2,count($queries),'two bounded pages queried');
foreach($queries as $query){frhSame(true,str_contains($query['sql'],"state='RECOVERED'"),'reconciliation must retain recovered cases for reversal detection');frhSame(true,str_contains($query['sql'],'closed_at IS NULL'),'reconciliation must retain open cases');}
frhSame(3,$queries[0]['params'][':tenant_id']??null,'financial keyset query tenant scope');
frhSame(30,$queries[0]['params'][':amazon_connection_id']??null,'financial keyset query connection scope');
frhSame(0,(int)($queries[0]['params'][':after_id']??-1),'first page starts at zero');
frhSame(250,(int)($queries[1]['params'][':after_id']??-1),'second page starts after prior cursor');
$dbBad=new TenantRuntimeRepoPdo();$pBad=SvAmazonTenantPersistence::create($dbBad,new SvAmazonTenantContext(3,30));
$dbBad->push(['fetch'=>false]);$dbBad->push(['rows'=>[['id'=>0]]]);
$threw=false;try{SvAmazonFinancialRefresh::nextReconciliationBatch($pBad,250);}catch(UnexpectedValueException){$threw=true;}
frhSame(true,$threw,'invalid non-advancing case ID must fail closed');
$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
frhSame(true,str_contains($daemon,'SvAmazonFinancialRefresh::safeSchedule('),'daemon must contain guarded financial schedule');
frhSame(true,str_contains($daemon,'SvAmazonFinancialRefresh::nextReconciliationBatch('),'daemon reconciliation must use bounded persisted case rotation');
frhSame(false,str_contains($daemon,'SvAmazonFinancialRefresh::financialCases('),'unbounded full-scan helper must not remain in daemon');
frhSame(false,str_contains($daemon,'casesWithExpectedReimbursement(250)'),'fixed LIMIT 250 path must not remain in daemon');
frhSame(true,str_contains($daemon,'$worker->caseUpdate('),'daemon must delegate financial persistence to the audited worker boundary');
frhSame(true,str_contains($daemon,'casePatchChanges('),'daemon must suppress no-op financial writes before touching updated_at');
$recoveredUpdate=SvAmazonReturnsReconcileWorker::caseUpdate(
    ['state'=>SvAmazonReturnStates::CREDIT_PENDING,'next_action_at'=>'2026-09-30 12:00:00'],
    ['state'=>SvAmazonReturnStates::RECOVERED,'credit_amount'=>'10.00'],
    [],
    new DateTimeImmutable('2026-09-18 03:30:00',new DateTimeZone('UTC'))
);
frhSame(true,array_key_exists('next_action_at',$recoveredUpdate),'financial recovery update must explicitly control next-action state');
frhSame(null,$recoveredUpdate['next_action_at'],'financial recovery must clear any obsolete next-action date');
frhSame(true,str_contains($daemon,'$task===\'financial\' && $this->config->enabled()'),'disabled runtime must bypass financial acceptance gate');
echo "financial-runtime-hardening-test: OK\n";
