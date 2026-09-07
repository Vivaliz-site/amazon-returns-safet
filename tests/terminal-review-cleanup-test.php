<?php
declare(strict_types=1);
$daemon=file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
if(!is_string($daemon)){fwrite(STDERR,"daemon missing\n");exit(1);}
$errors=[];
foreach([
    'resolveTerminalReviews' => 'Scheduler must run terminal review cleanup even when terminal cases are excluded from openCases().',
    'stale_reviews_resolved' => 'Scheduler result must audit how many stale reviews were resolved.',
    'resolveOpenForCase' => 'Cleanup must use the tenant-scoped review repository resolver.',
    'RECOVERED' => 'Recovered cases must be recognized as terminal for review cleanup.',
    'CLOSED_LOSS' => 'Closed-loss cases must be recognized as terminal for review cleanup.',
    'RECEIVED_OK' => 'Physically resolved cases must be recognized as terminal for review cleanup.',
] as $needle=>$message){
    if(strpos($daemon,$needle)===false)$errors[]=$message;
}
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "terminal-review-cleanup-test: OK\n";
