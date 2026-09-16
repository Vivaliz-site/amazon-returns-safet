<?php
declare(strict_types=1);

$root=dirname(__DIR__);
function caAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function caSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true));
}
function caSource(string $relative):string{
    $path=dirname(__DIR__).'/'.$relative;
    if(!is_file($path))throw new RuntimeException('Missing cockpit API file: '.$relative);
    return (string)file_get_contents($path);
}

$filterFile=$root.'/includes/amazon-returns/CockpitFilters.php';
if(!is_file($filterFile))throw new RuntimeException('CockpitFilters.php missing');
require_once $filterFile;
require_once $root.'/includes/amazon-returns/TenantContext.php';
require_once $root.'/includes/amazon-returns/CaseRepository.php';

$filters=SvAmazonCockpitFilters::fromQuery([
    'q'=>'98143-99485-9285859','state'=>'SAFE_T_DENIED','action'=>'SAFE_T_APPEAL',
    'review_status'=>'OPEN','program'=>'STANDARD','physical_status'=>'NOT_RECEIVED',
    'deadline'=>'7d','learned_rule'=>'applied','min_outstanding'=>'10.50','max_outstanding'=>'900',
    'page'=>'2','per_page'=>'999',
]);
caSame(2,$filters->page(),'Page parsing.');
caSame(100,$filters->perPage(),'Per-page must clamp to 100.');
caSame('SAFE_T_APPEAL',$filters->action(),'Action filter normalized.');
caSame(true,$filters->requiresDecisionFilter(),'Action requires pure decision preview filter.');
$bucketFilters=SvAmazonCockpitFilters::fromQuery(['bucket'=>'closed']);
caSame('closed',$bucketFilters->bucket(),'Operational bucket must be parsed server-side.');
$systemFilters=SvAmazonCockpitFilters::fromQuery(['bucket'=>'system']);
caSame(true,$systemFilters->requiresDecisionFilter(),'System bucket requires decision-aware post-filtering.');
caSame(false,$systemFilters->acceptsDecision(['action'=>'HUMAN_REVIEW']),'System bucket must exclude HUMAN_REVIEW before persistence.');
caSame(false,$systemFilters->acceptsDecision(['action'=>'BLOCKED_REVIEW']),'System bucket must exclude BLOCKED_REVIEW before persistence.');
caSame(true,$systemFilters->acceptsDecision(['action'=>'WAIT']),'System bucket keeps automatic decisions.');
caSame(5000,SvAmazonCockpitFilters::MAX_DECISION_POST_FILTER_CANDIDATES,'Decision post-filter scan must have an explicit safety bound.');
SvAmazonCockpitFilters::assertDecisionCandidateCount(5000);
$tooMany=false;try{SvAmazonCockpitFilters::assertDecisionCandidateCount(5001);}catch(OverflowException){$tooMany=true;}
caAssert($tooMany,'Decision post-filter candidate overflow must fail closed.');
$thrown=false;
try{SvAmazonCockpitFilters::fromQuery(['tenant_id'=>'999']);}catch(InvalidArgumentException){$thrown=true;}
caAssert($thrown,'Request tenant_id must be rejected, never trusted.');
$thrown=false;
try{SvAmazonCockpitFilters::fromQuery(['amazon_connection_id'=>'999']);}catch(InvalidArgumentException){$thrown=true;}
caAssert($thrown,'Request amazon_connection_id must be rejected.');

final class CaPdo extends PDO{
    public array $responses=[];
    public array $executed=[];
    public function __construct(){}
    public function queue(array $response):void{$this->responses[]=$response;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new CaStatement($this,$query,array_shift($this->responses)??[]);}
}
final class CaStatement extends PDOStatement{
    public function __construct(private CaPdo $db,private string $sql,private array $response){}
    public function execute(?array $params=null):bool{$this->db->executed[]=['sql'=>$this->sql,'params'=>$params??[]];return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->response['fetch']??false;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->response['rows']??[];}
    public function fetchColumn(int $column=0):mixed{return $this->response['column']??false;}
}
$db=new CaPdo();
$db->queue(['column'=>1]);
$db->queue(['rows'=>[['id'=>77,'tenant_id'=>1,'amazon_connection_id'=>10,'amazon_order_id'=>'702-1234567-7654321','safe_t_id'=>'98143-99485-9285859']]]);
$repo=new SvAmazonReturnCaseRepository($db,new SvAmazonTenantContext(1,10));
$result=$repo->search(['safe_t_id'=>'98143-99485-9285859','review_status'=>'OPEN'],1,50);
caSame(1,$result['total']??null,'Search total.');
caSame(1,count($result['items']??[]),'Search rows.');
$sql=implode("\n",array_column($db->executed,'sql'));
$params=[];foreach($db->executed as $execution)$params+=$execution['params'];
caAssert(str_contains($sql,'tenant_id=:tenant_id'),'Case search must scope tenant.');
caAssert(str_contains($sql,'amazon_connection_id=:amazon_connection_id'),'Case search must scope connection.');
caSame(1,$params[':tenant_id']??null,'Tenant binding comes from context.');
caSame(10,$params[':amazon_connection_id']??null,'Connection binding comes from context.');
caSame('98143-99485-9285859',$params[':safe_t_id']??null,'SAFE-T filter must be bound.');
caSame('OPEN',$params[':review_status']??null,'Review status filter must be bound.');

$apis=['admin/amazon-returns/api/cases.php','admin/amazon-returns/api/reviews.php','admin/amazon-returns/api/review.php','admin/amazon-returns/api/case.php'];
foreach($apis as $api){
    $src=caSource($api);
    caAssert(str_contains($src,'AdminAuth.php'),$api.' requires admin auth.');
    caAssert(str_contains($src,'TenantRegistry'),$api.' resolves tenant server-side.');
    caAssert(str_contains($src,'TenantPersistence'),$api.' uses tenant persistence.');
    caAssert(!preg_match('/(?:_GET|_POST|_REQUEST).*tenant_id/s',$src),$api.' must never accept request tenant_id.');
}
$casesSrc=caSource('admin/amazon-returns/api/cases.php');
caAssert(str_contains($casesSrc,'previewAction('),'Case listing must use side-effect-free previewAction.');
caAssert(!str_contains($casesSrc,'->nextAction('),'Read-only case listing must never call mutating nextAction.');
caAssert(str_contains($casesSrc,"'order_at'"),'Case listing must expose order date.');
caAssert(str_contains($casesSrc,"'seller_debit_at'"),'Case listing must expose seller debit date.');
caAssert(str_contains($casesSrc,"'refund_amount'"),'Case listing must expose customer refund amount.');
caAssert(str_contains($casesSrc,"'customer_tracking_ids'"),'Case listing must expose customer tracking evidence.');
caAssert(str_contains($casesSrc,"'return_tracking_ids'"),'Case listing must expose return tracking evidence separately.');
caAssert(str_contains($casesSrc,'SvAmazonCaseReferenceSearch::caseIds'),'Cockpit search must resolve references before pagination.');
caAssert(str_contains($casesSrc,'SvAmazonGmailReturnReferenceLookup'),'TBR search may use read-only Gmail recovery when local evidence is absent.');
caAssert(str_contains($casesSrc,'$requiresPostFilter=$filters->requiresDecisionFilter();'),'Decision-aware filters must use exact post-filter pagination.');
caAssert(str_contains($casesSrc,'count($rawItems)<$rawTotal')&&str_contains($casesSrc,'$batchItems!==[]'),'Decision post-filtering must scan all server-side candidate pages with an empty-batch guard.');
caAssert(str_contains($casesSrc,'if(!$filters->acceptsDecision($decision))continue;'),'Projected decisions must enforce action and system-bucket membership.');
caAssert(str_contains($casesSrc,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')&&str_contains($casesSrc,'beginTransaction()'),'Decision post-filter scan must use one repeatable-read snapshot.');
caAssert(str_contains($casesSrc,'assertDecisionCandidateCount($rawTotal)'),'Decision post-filter scan must enforce its candidate bound before projection.');
caAssert(str_contains($casesSrc,'commit()')&&str_contains($casesSrc,'rollBack()'),'Decision snapshot must be closed on success and failure.');
caAssert(!str_contains($casesSrc,'$filters->requiresDecisionFilter() || $searchTerm'),'Text search must never trigger full-tenant post-filter pagination.');
caAssert(!str_contains($casesSrc,'foreach($case[\'customer_tracking_ids\']'),'Cockpit search must not post-filter projected tracking in PHP.');
$caseSrc=caSource('admin/amazon-returns/api/case.php');
$consultationSrc=caSource('includes/amazon-returns/CaseConsultation.php');
caAssert(str_contains($caseSrc,'SvAmazonCockpitTimeline'),'Case detail must use 360 timeline projector.');
caAssert(str_contains($caseSrc,"'timeline'"),'Case detail must expose timeline.');
caAssert(str_contains($caseSrc,"'current_review'"),'Case detail must expose current review.');
caAssert(str_contains($caseSrc,"'rule_applications'"),'Case detail must expose rule applications.');
caAssert(str_contains($caseSrc,"'sales_invoice_number'"),'Case detail must expose sales invoice number when available.');
caAssert(str_contains($caseSrc,"'sales_invoice_source'"),'Case detail must expose whether the sales invoice came from Tiny/Olist.');
caAssert(str_contains($caseSrc,"'return_invoice_numbers'"),'Case detail must expose all linked return invoice numbers.');
caAssert(str_contains($caseSrc,"'amazon_reimbursement_at'"),'Case detail must expose the date of a real reconciled Amazon credit.');
caAssert(str_contains($caseSrc,"'safe_t_submitted_at'"),'Case detail must expose a confirmed SAFE-T submission date.');
caAssert(str_contains($caseSrc,"'amazon_decision_at'"),'Case detail must expose when an Amazon decision was observed.');
caAssert(str_contains($caseSrc,"SALES_INVOICE_LINKED"),'Case detail must derive invoice from linked invoice evidence.');
caAssert(str_contains($caseSrc,'SvAmazonCaseConsultation::invoiceFacts'),'Case detail must delegate invoice facts to the consultation layer.');
caAssert(str_contains($consultationSrc,"RETURN_INVOICE_LINKED"),'Consultation facts must derive return invoices from immutable linked evidence.');
caAssert(str_contains($caseSrc,"'erp_return'"),'Case detail must retain the canonical ERP return workflow projection.');
caAssert(str_contains($caseSrc,"'last_external_write'"),'Case detail must expose the last external write.');
caAssert(str_contains($caseSrc,"'last_read_back'"),'Case detail must expose the last verification.');
caAssert(str_contains($caseSrc,"'return_tracking_ids'"),'Case detail must expose TBR / return tracking separately.');
caAssert(str_contains($caseSrc,"'applied_rule'"),'Case detail must expose a safe latest applied-rule reference.');
caAssert(str_contains($caseSrc,'RETURN_REPORT_OBSERVED'),'Case detail must derive return reason from the actual returns report event.');
caAssert(!str_contains($caseSrc,'new SvAmazonReturnsSpApi'),'Opening case detail must not trigger an external SP-API read.');

echo "cockpit-api-contract-test: OK\n";