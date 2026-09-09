<?php
declare(strict_types=1);
$daemon=(string)file_get_contents(__DIR__."/../workers/amazon-returns/daemon.php");
$expected="if((int)(\$results['scheduler']['financial_checks_requested']??0)>0 && !isset(\$results['sp_api']))";
if(!str_contains($daemon,$expected)){fwrite(STDERR,"financial recheck must not invalidate a refresh that already ran in the same cycle\n");exit(1);}
echo "financial-recheck-throttle-test: OK\n";
