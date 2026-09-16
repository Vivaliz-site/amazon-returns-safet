<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Runtime.php';
function wgcdAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
wgcdAssert(method_exists(SvAmazonReturnsRuntime::class,'writeConfigurationChangeTasks'),'Runtime must expose tasks due on write configuration change');
$tasks=SvAmazonReturnsRuntime::writeConfigurationChangeTasks();
wgcdAssert(in_array('scheduler',$tasks,true),'write gate changes must re-evaluate scheduler immediately');
wgcdAssert(in_array('erp_sales_returns',$tasks,true),'write gate changes must reconcile ERP immediately');
echo "write-gate-change-due-test: OK\n";