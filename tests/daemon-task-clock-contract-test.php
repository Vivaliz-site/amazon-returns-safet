<?php
declare(strict_types=1);

$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
if($daemon==='')throw new RuntimeException('Amazon returns daemon source is missing.');

$required=[
    '$fixedNow=$now!==null;',
    '$taskNow=$fixedNow ? $now : new DateTimeImmutable(\'now\',new DateTimeZone(\'UTC\'));',
    '$results[$task]=$this->runTask($task,$taskNow);',
    '$state[$task]=$taskNow->format(DATE_ATOM);',
];
foreach($required as $marker){
    if(!str_contains($daemon,$marker)){
        throw new RuntimeException('Production task clock does not advance between evidence and scheduler: '.$marker);
    }
}
if(!str_contains($daemon,'$now ??= new DateTimeImmutable(\'now\',new DateTimeZone(\'UTC\'));')){
    throw new RuntimeException('runOnce must retain a deterministic explicit clock for tests.');
}
echo "daemon-task-clock-contract-test: OK\n";
