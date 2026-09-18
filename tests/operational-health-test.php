<?php
declare(strict_types=1);
$path=__DIR__.'/../includes/amazon-returns/OperationalHealth.php';
if(!is_file($path))throw new RuntimeException('OperationalHealth helper must exist.');
require_once $path;
function ohSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.var_export($want,true).' actual='.var_export($got,true));}
$now=new DateTimeImmutable('2026-09-16T20:30:00Z');
$cadences=['gmail'=>43200,'scheduler'=>43200,'review_operations'=>7200,'health'=>900,'policy_monitor'=>43200];
$fresh=['gmail'=>['metadata'=>['status'=>'OK'],'observed_at'=>'2026-09-16 20:00:00'],'scheduler'=>['metadata'=>['status'=>'OK'],'observed_at'=>'2026-09-16 20:00:00'],'review_operations'=>['metadata'=>['status'=>'OK'],'observed_at'=>'2026-09-16 20:00:00']];
$ok=SvAmazonOperationalHealth::evaluate($cadences,$fresh,$now,0);
ohSame([], $ok['blockers'], 'fresh mandatory tasks without dead letters must be healthy');
$stale=$fresh;$stale['scheduler']['observed_at']='2026-09-15 00:00:00';
$r=SvAmazonOperationalHealth::evaluate($cadences,$stale,$now,0);
ohSame(true,in_array('TASK_SCHEDULER_STALE',$r['blockers'],true),'stale scheduler must be explicit');
$failed=$fresh;$failed['gmail']['metadata']['status']='FAILED';
$r=SvAmazonOperationalHealth::evaluate($cadences,$failed,$now,1);
ohSame(true,in_array('TASK_GMAIL_FAILED',$r['blockers'],true),'failed task must be explicit');
ohSame(true,in_array('DEAD_LETTERS_PRESENT',$r['blockers'],true),'dead letters must degrade operational health');
$erpCadences=$cadences;$erpCadences['erp_sales_returns']=3600;
$erpUnconfigured=$fresh;$erpUnconfigured['erp_sales_returns']=['metadata'=>['status'=>'SKIPPED_NOT_CONFIGURED'],'observed_at'=>'2026-09-16 20:00:00'];
$r=SvAmazonOperationalHealth::evaluate($erpCadences,$erpUnconfigured,$now,0);
ohSame(true,in_array('TASK_ERP_SALES_RETURNS_SKIPPED_NOT_CONFIGURED',$r['blockers'],true),'refunds pending ERP return reconciliation without readable credentials must degrade health, never silently pass as healthy.');
echo "operational-health-test: OK\n";