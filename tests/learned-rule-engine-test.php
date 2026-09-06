<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/LearnedRuleEngine.php';
function lreAssert(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);} function lreSame($a,$b,string $m):void{if($a!==$b)throw new RuntimeException($m.' want='.json_encode($a).' got='.json_encode($b));}
$context=['signature'=>['review_reason'=>'X','program'=>'STANDARD','financial'=>'NO_CREDIT','material_conflict'=>false],'variables'=>['PROMISED_DATE'=>'2026-09-10 03:00:00','APPEAL_DEADLINE'=>'2026-09-12 03:00:00'],'facts'=>['material_conflict'=>false]];
$broad=['id'=>1,'version'=>1,'status'=>'ACTIVE','match'=>['review_reason'=>'X'],'effect'=>['action'=>'WAIT','parameters'=>['date_binding'=>'PROMISED_DATE']]];
$specific=['id'=>2,'version'=>1,'status'=>'ACTIVE','match'=>['review_reason'=>'X','program'=>'STANDARD'],'effect'=>['action'=>'WAIT','parameters'=>['date_binding'=>'PROMISED_DATE']]];
$engine=new SvAmazonLearnedRuleEngine();
$r=$engine->match($context,[$broad,$specific]); lreSame('MATCH',$r['status'],'compatible winner'); lreSame(2,$r['rule']['id'],'specific winner'); lreSame('2026-09-10 03:00:00',$r['effect']['parameters']['resolved_date'],'binding resolved');
$badSame=$specific; $badSame['id']=3; $badSame['effect']=['action'=>'CHECK_FINANCES','parameters'=>['date_binding'=>'NONE']];
$r=$engine->match($context,[$specific,$badSame]); lreSame('CONFLICT',$r['status'],'same specificity conflict'); lreSame(2,count($r['conflicts']),'conflict refs');
$disabled=$specific; $disabled['status']='DISABLED'; lreSame('NONE',$engine->match($context,[$disabled])['status'],'disabled ignored');
$material=$context; $material['facts']['material_conflict']=true; $material['signature']['material_conflict']=true; lreSame('CONFLICT',$engine->match($material,[$specific])['status'],'material conflict fails closed');
$unsafe=$specific; $unsafe['effect']=['action'=>'WAIT','parameters'=>['date_binding'=>'CUSTOM_DATE','literal'=>'2026-01-01']];
try{$engine->match($context,[$unsafe]);throw new RuntimeException('unsafe binding accepted');}catch(InvalidArgumentException $e){}
$unknown=$specific; $unknown['effect']=['action'=>'EXEC_SHELL','parameters'=>['date_binding'=>'NONE']];
try{$engine->match($context,[$unknown]);throw new RuntimeException('unsafe action accepted');}catch(InvalidArgumentException $e){}
echo "learned-rule-engine-test: OK\n";
