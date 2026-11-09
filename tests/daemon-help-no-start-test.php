<?php
declare(strict_types=1);

require_once __DIR__.'/../workers/amazon-returns/daemon.php';

$fail=[];
ob_start();
$exit=sv_amazon_returns_daemon_main(['daemon.php','--help']);
$text=(string)ob_get_clean();
if($exit!==0)$fail[]='--help must exit successfully without running any cycle';
if(!str_contains($text,'Usage: php workers/amazon-returns/daemon.php'))$fail[]='Help must show explicit safe usage';
if(!str_contains($text,'without starting a worker'))$fail[]='Help must explain it will not start a daemon';
if(str_contains($text,'cycle_summary')||str_contains($text,'database unavailable'))$fail[]='Help must not start the worker or open a DB';

ob_start();
$short=sv_amazon_returns_daemon_main(['daemon.php','-h']);
$shortText=(string)ob_get_clean();
if($short!==0 || !str_contains($shortText,'Usage:'))$fail[]='-h must also exit without a worker';

if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}
echo "daemon-help-no-start-test: OK\n";
