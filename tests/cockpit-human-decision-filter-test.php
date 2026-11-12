<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/includes/amazon-returns/CockpitFilters.php';
function chfSame(mixed $w,mixed $g,string $m):void{if($w!==$g)throw new RuntimeException($m.' expected='.json_encode($w).' actual='.json_encode($g));}
$attention=SvAmazonCockpitFilters::fromQuery(['bucket'=>'attention']);
$system=SvAmazonCockpitFilters::fromQuery(['bucket'=>'system']);
$wait=['action'=>'WAIT'];
// Revisão operacional OPEN não pertence à fila de atenção do proprietário.
chfSame(false,$attention->acceptsDecision($wait,'OPEN',false),'Operational open review must not be attention.');
chfSame(true,$system->acceptsDecision($wait,'OPEN',false),'Operational open review is system work.');
// Revisão humana continua na atenção.
chfSame(true,$attention->acceptsDecision($wait,'OPEN',true),'Human review must be attention.');
chfSame(false,$system->acceptsDecision($wait,'OPEN',true),'Human review must not be system.');
// Compatibilidade: chamada antiga sem o terceiro argumento preserva a regra anterior.
chfSame(true,$attention->acceptsDecision($wait,'OPEN'),'Legacy call keeps previous semantics.');
// Ação humana sem revisão aberta segue humana.
chfSame(true,$attention->acceptsDecision(['action'=>'HUMAN_REVIEW'],null,null),'Human action without review stays attention.');
chfSame(false,$attention->acceptsDecision(['action'=>'HUMAN_REVIEW'],'OPEN',false),'Operational review overrides generic action flag.');
echo "cockpit-human-decision-filter-test OK\n";
// Caso encerrado: o servidor deve classificar como não humano e fora da atenção.
$src=(string)file_get_contents($root.'/admin/amazon-returns/api/cases.php');
if(!str_contains($src,"'CLOSED_LOSS'")||!str_contains($src,'$caseClosed'))throw new RuntimeException('cases.php must force requires_human_decision=false for closed/terminal cases.');
echo "closed-case contract OK\n";
