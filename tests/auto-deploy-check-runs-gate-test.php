<?php
declare(strict_types=1);

function adcgAssert(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
}

$root=dirname(__DIR__);
$filter=$root.'/scripts/ci-check-runs-green.jq';
$deployScript=$root.'/scripts/auto-deploy.sh';

adcgAssert(is_file($filter),'Auto-deploy check-run gate filter must exist.');
adcgAssert(is_file($deployScript),'Auto-deploy script must exist.');

function adcgGreen(string $filter,array $checkRuns):bool{
    $path=tempnam(sys_get_temp_dir(),'amazon-returns-check-runs-');
    if($path===false)throw new RuntimeException('Could not create check-run fixture.');
    file_put_contents($path,json_encode([
        'total_count'=>count($checkRuns),
        'check_runs'=>$checkRuns,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    $command='jq -e -f '.escapeshellarg($filter).' '.escapeshellarg($path).' >/dev/null 2>&1';
    exec($command,$output,$code);
    @unlink($path);
    return $code===0;
}

$successDuplicate=[
    ['id'=>110,'name'=>'test','status'=>'completed','conclusion'=>'success'],
    ['id'=>90,'name'=>'test','status'=>'completed','conclusion'=>'cancelled'],
    ['id'=>105,'name'=>'self-test','status'=>'completed','conclusion'=>'success'],
    ['id'=>104,'name'=>'main-guard','status'=>'completed','conclusion'=>'success'],
    ['id'=>103,'name'=>'governance-gate','status'=>'completed','conclusion'=>'success'],
];
adcgAssert(
    adcgGreen($filter,array_reverse($successDuplicate)),
    'An older cancelled duplicate must not block deploy when the newest same-name check succeeded.'
);

$latestFailure=$successDuplicate;
$latestFailure[0]=['id'=>120,'name'=>'test','status'=>'completed','conclusion'=>'failure'];
$latestFailure[1]=['id'=>119,'name'=>'test','status'=>'completed','conclusion'=>'success'];
adcgAssert(
    !adcgGreen($filter,$latestFailure),
    'A newest failed check must block deploy even when an older same-name check succeeded.'
);

$latestRunning=$successDuplicate;
$latestRunning[0]=['id'=>130,'name'=>'test','status'=>'in_progress','conclusion'=>null];
$latestRunning[1]=['id'=>129,'name'=>'test','status'=>'completed','conclusion'=>'success'];
adcgAssert(
    !adcgGreen($filter,$latestRunning),
    'A newest in-progress check must block deploy.'
);

adcgAssert(!adcgGreen($filter,[]),'A commit with no checks must not deploy.');

$script=(string)file_get_contents($deployScript);
adcgAssert(
    str_contains($script,'ci-check-runs-green.jq'),
    'Auto-deploy must use the audited latest-per-name check-run gate.'
);

echo "auto-deploy-check-runs-gate-test: OK\n";
