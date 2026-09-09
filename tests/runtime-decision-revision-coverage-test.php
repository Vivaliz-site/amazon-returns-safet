<?php
declare(strict_types=1);
$source=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/Runtime.php');
foreach(['ReturnActionRouter.php','SafeTStatusService.php','safe-t-status-parser.mjs','workers/amazon-returns/scheduler.php'] as $dependency){
    if(!str_contains($source,$dependency))throw new RuntimeException('Decision/evidence revision fingerprint must include '.$dependency);
}
echo "runtime-decision-revision-coverage-test: OK\n";
