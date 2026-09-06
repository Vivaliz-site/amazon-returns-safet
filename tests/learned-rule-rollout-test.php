<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Config.php';
function lrrAssert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function lrrSame(mixed $a,mixed $b,string $m):void{if($a!==$b)throw new RuntimeException($m.' expected='.var_export($a,true).' got='.var_export($b,true));}
function lrrSource(string $f):string{$p=dirname(__DIR__).'/'.$f;if(!is_file($p))throw new RuntimeException('Missing '.$f);return(string)file_get_contents($p);}
lrrSame(false,(new SvAmazonReturnsConfig([]))->learnedRuleExecutionEnabled(),'learned execution starts fail closed');
lrrSame(true,(new SvAmazonReturnsConfig(['AMAZON_RETURNS_LEARNED_RULE_EXECUTION'=>'1']))->learnedRuleExecutionEnabled(),'explicit flag enables learned execution');
$coordinator=lrrSource('includes/amazon-returns/DecisionCoordinator.php');
lrrAssert(str_contains($coordinator,'learnedRuleExecutionEnabled'),'coordinator gates learned execution');
lrrAssert(str_contains($coordinator,'learned_rule_shadow_match'),'disabled learned execution still reports shadow match');
$replay=lrrSource('scripts/replay-learned-rules.php');
foreach(['hard_gate_bypass_count','learned_matches','conflicts','projected_actions'] as $needle)lrrAssert(str_contains($replay,$needle),'replay missing '.$needle);
foreach(['cases->update(','events->append(','outbox->enqueue(','learnedRules->promote(','reviews->decide('] as $mutator)lrrAssert(!str_contains($replay,$mutator),'replay must be read-only: '.$mutator);
$runtime=lrrSource('includes/amazon-returns/Runtime.php');
foreach(['pending_reviews','rule_conflicts','rule_applications','ai_suggestion_failures','review_ai_ready','pending_outbox','dead_letters','learned_rule_execution_enabled'] as $field)lrrAssert(str_contains($runtime,$field),'health missing '.$field);
$health=lrrSource('api/health.php');lrrAssert(str_contains($health,'SvAmazonReturnsRuntime::health'),'health endpoint delegates runtime health');
echo "learned-rule-rollout-test: OK\n";
