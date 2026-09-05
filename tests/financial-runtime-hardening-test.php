<?php
declare(strict_types=1);
require_once __DIR__.'/amazon-returns-tenant-runtime-repositories-test.php';
require_once __DIR__.'/../includes/amazon-returns/FinancialRefresh.php';
function frhSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
if(!method_exists(SvAmazonFinancialRefresh::class,'safeSchedule')){
    fwrite(STDERR,"Missing safe financial scheduling boundary\n");exit(1);
}
if(!method_exists(SvAmazonReturnCaseRepository::class,'financialCasesAfter')){
    fwrite(STDERR,"Missing keyset financial reconciliation paging\n");exit(1);
}
final class FinancialGateFailurePdo extends PDO {
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false{
        throw new PDOException('simulated cursor outage');
    }
}
$db=new FinancialGateFailurePdo();$p=SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(3,30));
$plan=SvAmazonFinancialRefresh::safeSchedule(['bootstrap'],true,$p);
frhSame(['bootstrap'],$plan['due'],'cursor outage must not throw out of daemon planning');
frhSame('FAILED',$plan['gate']['status']??null,'cursor outage is explicit failed gate');
frhSame('PDOException',$plan['gate']['error_class']??null,'gate records error class without secret payloads');
$db=new TenantRuntimeRepoPdo();$p=SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(3,30));
$first=[];for($i=1;$i<=250;$i++)$first[]=['id'=>$i];
$second=[['id'=>251],['id'=>252]];
$db->push(['rows'=>$first]);$db->push(['rows'=>$second]);$db->push(['rows'=>[]]);
$seen=[];foreach(SvAmazonFinancialRefresh::financialCases($p,250) as $case)$seen[]=(int)$case['id'];
frhSame(252,count($seen),'reconciliation must not starve cases after the first 250');
frhSame(1,$seen[0],'first financial case');frhSame(252,$seen[251],'last financial case');
$queries=array_values(array_filter($db->executed,fn(array $e):bool=>str_contains($e['sql'],'expected_reimbursement_amount>0')));
frhSame(3,count($queries),'keyset iteration must query until empty');
frhSame(0,(int)($queries[0]['params'][':after_id']??-1),'first page starts at zero');
frhSame(250,(int)($queries[1]['params'][':after_id']??-1),'second page starts after prior max id');
frhSame(252,(int)($queries[2]['params'][':after_id']??-1),'terminal probe starts after last id');
echo "financial-runtime-hardening-test: OK\n";
$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
frhSame(true,str_contains($daemon,'SvAmazonFinancialRefresh::safeSchedule('),'daemon must contain guarded financial schedule');
frhSame(true,str_contains($daemon,'SvAmazonFinancialRefresh::financialCases('),'daemon reconciliation must use keyset case iteration');
frhSame(false,str_contains($daemon,'casesWithExpectedReimbursement(250)'),'fixed LIMIT 250 path must not remain in daemon');
frhSame(true,str_contains($daemon,'$task===\'financial\' && $this->config->enabled()'),'disabled runtime must bypass financial acceptance gate');
