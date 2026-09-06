<?php
declare(strict_types=1);
$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
if(!str_contains($daemon,"learned_rule_revision"))throw new RuntimeException('daemon does not wake scheduler on learned rule revision');
$service=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/ReviewService.php');
foreach(['scheduleDecision(','externalWriteAllowed','try{','matching_cases'] as $n)if(!str_contains($service,$n))throw new RuntimeException('propagation contract missing '.$n);
if(str_contains($service,'cases->update('))throw new RuntimeException('review propagation must not bulk patch case decisions');
echo "review-propagation-test: OK\n";
