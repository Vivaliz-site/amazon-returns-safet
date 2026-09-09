<?php
declare(strict_types=1);

$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
$expected="searchMessages('newer_than:90d reembolso iniciado',500)";
$broken="searchMessages('newer_than:90d \"reembolso iniciado\"',500)";

if(!str_contains($daemon,$expected)){
    throw new RuntimeException('Refund reconciliation must search Gmail by both terms without requiring an exact contiguous phrase.');
}
if(str_contains($daemon,$broken)){
    throw new RuntimeException('Quoted refund phrase misses real subjects such as Reembolso de 85.5 BRL iniciado.');
}

echo "gmail-refund-reconciliation-query-test: OK\n";
