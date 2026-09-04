<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/Config.php';
require_once __DIR__.'/../includes/amazon-returns/TenantRegistry.php';
require_once __DIR__.'/../includes/amazon-returns/ShadowAudit.php';
require_once __DIR__.'/../includes/amazon-returns/ShadowAuditRepository.php';
require_once __DIR__.'/../includes/amazon-returns/PolicyEngine.php';
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(function_exists('posix_geteuid') && posix_geteuid()!==0){
    fwrite(STDERR,"shadow-audit must run as root for local socket comparison\n");
    exit(2);
}

$sourceTenantSlugEnv='AMAZON_RETURNS_SOURCE_TENANT_SLUG';
$sourceConnectionEnv='AMAZON_RETURNS_SOURCE_CONNECTION_KEY';
$targetTenantSlugEnv='AMAZON_RETURNS_TARGET_TENANT_SLUG';
$targetConnectionEnv='AMAZON_RETURNS_TARGET_CONNECTION_KEY';
$sourceName=getenv('AMAZON_RETURNS_SOURCE_DB') ?: 'shopvivaliz';
$targetName=getenv('AMAZON_RETURNS_TARGET_DB') ?: 'amazon_returns_safet';
$now=new DateTimeImmutable(
    getenv('AMAZON_RETURNS_SHADOW_NOW') ?: 'now',new DateTimeZone('UTC')
);

$connect=static function(string $name):PDO{
    if(preg_match('/^[a-zA-Z0-9_]+$/',$name)!==1){
        throw new InvalidArgumentException('Invalid database name.');
    }
    return new PDO(
        'mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname='.$name.';charset=utf8mb4',
        'root','',[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]
    );
};

$contextFor=static function(PDO $db,string $side):?SvAmazonTenantContext{
    if(!SvAmazonReturnsShadowAuditRepository::hasTenantColumns($db))return null;
    $prefix=$side==='source'?'AMAZON_RETURNS_SOURCE':'AMAZON_RETURNS_TARGET';
    $slug=getenv($prefix.'_TENANT_SLUG');
    $key=getenv($prefix.'_CONNECTION_KEY');
    if($side==='target'){
        $slug=$slug ?: getenv('AMAZON_RETURNS_TENANT_SLUG');
        $key=$key ?: getenv('AMAZON_RETURNS_CONNECTION_KEY');
    }
    $config=new SvAmazonReturnsConfig([
        'AMAZON_RETURNS_TENANT_SLUG'=>is_string($slug)?$slug:'',
        'AMAZON_RETURNS_CONNECTION_KEY'=>is_string($key)?$key:'',
    ]);
    return SvAmazonTenantRegistry::resolveCurrent($db,$config);
};

try{
    $sourceDb=$connect($sourceName);
    $targetDb=$connect($targetName);
    $sourceContext=$contextFor($sourceDb,'source');
    $targetContext=$contextFor($targetDb,'target');
    $source=new SvAmazonReturnsShadowAuditRepository($sourceDb,$sourceContext);
    $target=new SvAmazonReturnsShadowAuditRepository($targetDb,$targetContext);
    $sourceCases=$source->openCases();
    $targetCases=$target->openCases();
    $sourcePolicies=$source->activePolicies();
    $targetPolicies=$target->activePolicies();
    $keys=array_values(array_unique(array_merge(
        array_keys($sourceCases),array_keys($targetCases)
    )));
    sort($keys,SORT_STRING);
    $engine=new SvAmazonSafeTDecisionEngine();
    $mismatches=[];
    foreach($keys as $key){
        $a=$sourceCases[$key] ?? null;
        $b=$targetCases[$key] ?? null;
        if(!is_array($a) || !is_array($b)){
            $mismatches[]=[
                'case_key'=>$key,
                'order_id'=>(string)(($a ?? $b)['amazon_order_id'] ?? ''),
                'missing'=>$a===null?'source':'target',
            ];
            continue;
        }
        $sourceCase=$a;
        $sourceCase['policies']=$sourcePolicies;
        $targetCase=$b;
        $targetCase['policies']=$targetPolicies;
        $sourcePolicy=SvAmazonReturnPolicyEngine::evaluate($sourceCase,$now);
        $targetPolicy=SvAmazonReturnPolicyEngine::evaluate($targetCase,$now);
        $sourceDecision=$engine->nextAction(
            $sourceCase,$source->eventsForCase((int)$a['id']),$sourcePolicy
        );
        $targetDecision=$engine->nextAction(
            $targetCase,$target->eventsForCase((int)$b['id']),$targetPolicy
        );
        $caseDiff=SvAmazonReturnsShadowAudit::caseDiff($a,$b);
        $decisionDiff=SvAmazonReturnsShadowAudit::decisionDiff(
            $sourceDecision,$targetDecision
        );
        if($caseDiff!==[] || $decisionDiff!==[]){
            $mismatches[]=[
                'case_key'=>$key,
                'order_id'=>(string)($a['amazon_order_id'] ?? ''),
                'safe_t_id'=>(string)($a['safe_t_id'] ?? ''),
                'case_diff'=>$caseDiff,
                'decision_diff'=>$decisionDiff,
            ];
        }
    }
    $badPolicies=$target->nonD75ActivePolicies();
    $result=[
        'status'=>$mismatches===[] && $badPolicies===0?'OK':'MISMATCH',
        'at'=>$now->format(DATE_ATOM),
        'source_database'=>$sourceName,
        'target_database'=>$targetName,
        'source_identity'=>$source->identity(),
        'target_identity'=>$target->identity(),
        'source_open_cases'=>count($sourceCases),
        'target_open_cases'=>count($targetCases),
        'compared_cases'=>count($keys),
        'mismatch_count'=>count($mismatches),
        'target_non_d75_active_policies'=>$badPolicies,
        'mismatches'=>$mismatches,
    ];
    echo json_encode(
        $result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT
    )."\n";
    exit($result['status']==='OK'?0:1);
}catch(Throwable $e){
    fwrite(STDERR,"shadow-audit failed: ".get_class($e)."\n");
    exit(2);
}
