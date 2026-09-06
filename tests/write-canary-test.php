<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Config.php';
function wcSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
$open=new SvAmazonReturnsConfig([]);
wcSame(true,$open->writeCaseAllowed(15),'no canary list must allow normal production scheduling');
$canary=new SvAmazonReturnsConfig(['AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'=>'15, 31']);
wcSame(true,$canary->writeCaseAllowed(15),'listed canary case allowed');
wcSame(true,$canary->writeCaseAllowed(31),'second listed canary case allowed');
wcSame(false,$canary->writeCaseAllowed(3),'unlisted case blocked during canary');
$invalid=new SvAmazonReturnsConfig(['AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'=>'not-an-id']);
wcSame(false,$invalid->writeCaseAllowed(15),'invalid non-empty canary list fails closed');
$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
wcSame(true,str_contains($daemon,'writeCaseAllowed($caseId)'),'scheduler must enforce case-scoped canary before enqueue');
echo "write-canary-test: OK\n";
