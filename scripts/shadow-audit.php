<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/ShadowAudit.php';
require_once __DIR__ . '/../includes/amazon-returns/EventStore.php';
require_once __DIR__ . '/../includes/amazon-returns/PolicyEngine.php';
require_once __DIR__ . '/../includes/amazon-returns/SafeTDecisionEngine.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR,"shadow-audit must run as root for local socket comparison\n");
    exit(2);
}

$sourceName=getenv('AMAZON_RETURNS_SOURCE_DB') ?: 'shopvivaliz';
$targetName=getenv('AMAZON_RETURNS_TARGET_DB') ?: 'amazon_returns_safet';
$now=new DateTimeImmutable(getenv('AMAZON_RETURNS_SHADOW_NOW') ?: 'now',new DateTimeZone('UTC'));

$connect=static function(string $name):PDO{
    if(preg_match('/^[a-zA-Z0-9_]+$/',$name)!==1) throw new InvalidArgumentException('Invalid database name.');
    return new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname='.$name.';charset=utf8mb4','root','',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
};

$loadPolicies=static function(PDO $db):array{
    return $db->query("SELECT * FROM amazon_return_policies WHERE status='ACTIVE' ORDER BY effective_from DESC,id DESC")?->fetchAll(PDO::FETCH_ASSOC) ?: [];
};
$loadOpenCases=static function(PDO $db):array{
    $rows=$db->query("SELECT * FROM amazon_return_cases WHERE closed_at IS NULL ORDER BY id")?->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out=[];
    foreach($rows as $row)$out[(int)$row['id']]=$row;
    return $out;
};
$decision=static function(PDO $db,array $case,array $policies,DateTimeImmutable $now):array{
    $case['policies']=$policies;
    $policy=SvAmazonReturnPolicyEngine::evaluate($case,$now);
    $timeline=SvAmazonReturnEventStore::eventsForCase($db,(int)$case['id']);
    return (new SvAmazonSafeTDecisionEngine())->nextAction($case,$timeline,$policy);
};

$source=$connect($sourceName);
$target=$connect($targetName);
$sourceCases=$loadOpenCases($source);
$targetCases=$loadOpenCases($target);
$sourcePolicies=$loadPolicies($source);
$targetPolicies=$loadPolicies($target);
$ids=array_values(array_unique(array_merge(array_keys($sourceCases),array_keys($targetCases))));
sort($ids,SORT_NUMERIC);
$mismatches=[];

foreach($ids as $id){
    $a=$sourceCases[$id] ?? null;
    $b=$targetCases[$id] ?? null;
    if(!is_array($a) || !is_array($b)){
        $mismatches[]=[
            'case_id'=>$id,
            'order_id'=>(string)(($a ?? $b)['amazon_order_id'] ?? ''),
            'missing'=>$a===null?'source':'target',
        ];
        continue;
    }
    $caseDiff=SvAmazonReturnsShadowAudit::caseDiff($a,$b);
    $sourceDecision=$decision($source,$a,$sourcePolicies,$now);
    $targetDecision=$decision($target,$b,$targetPolicies,$now);
    $decisionDiff=SvAmazonReturnsShadowAudit::decisionDiff($sourceDecision,$targetDecision);
    if($caseDiff!==[] || $decisionDiff!==[]){
        $mismatches[]=[
            'case_id'=>$id,
            'order_id'=>(string)($a['amazon_order_id'] ?? ''),
            'safe_t_id'=>(string)($a['safe_t_id'] ?? ''),
            'case_diff'=>$caseDiff,
            'decision_diff'=>$decisionDiff,
        ];
    }
}

$badPolicies=(int)$target->query("SELECT COUNT(*) FROM amazon_return_policies WHERE status='ACTIVE' AND eligibility_days<>75")->fetchColumn();

$result=[
    'status'=>$mismatches===[] && $badPolicies===0 ? 'OK' : 'MISMATCH',
    'at'=>$now->format(DATE_ATOM),
    'source_open_cases'=>count($sourceCases),
    'target_open_cases'=>count($targetCases),
    'compared_cases'=>count($ids),
    'mismatch_count'=>count($mismatches),
    'target_non_d75_active_policies'=>$badPolicies,
    'mismatches'=>$mismatches,
];
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
exit($result['status']==='OK' ? 0 : 1);
