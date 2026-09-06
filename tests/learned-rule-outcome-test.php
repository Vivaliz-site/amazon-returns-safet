<?php
declare(strict_types=1);
$path=__DIR__.'/../includes/amazon-returns/LearnedRuleOutcome.php';if(!is_file($path))throw new RuntimeException('LearnedRuleOutcome.php missing');require_once $path;
function lroSame(mixed $a,mixed $b,string $m):void{if($a!==$b)throw new RuntimeException($m.' expected='.var_export($a,true).' got='.var_export($b,true));}
$base=['id'=>7,'expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00','state'=>'APPEAL_SUBMITTED'];
lroSame('PENDING',SvAmazonLearnedRuleOutcome::classify($base,[]),'active appeal remains pending');
lroSame('APPROVED_PENDING_CREDIT',SvAmazonLearnedRuleOutcome::classify(array_replace($base,['state'=>'SAFE_T_APPROVED']),[]),'approval waits for financial credit');
lroSame('RECOVERED',SvAmazonLearnedRuleOutcome::classify(array_replace($base,['state'=>'CREDIT_PENDING','reconciled_credit_amount'=>'100.00']),[]),'full reconciled credit is recovered');
lroSame('PENDING',SvAmazonLearnedRuleOutcome::classify(array_replace($base,['state'=>'RECOVERED','reconciled_credit_amount'=>'0.00']),[]),'state label alone cannot prove recovery');
lroSame('DENIED',SvAmazonLearnedRuleOutcome::classify(array_replace($base,['state'=>'APPEAL_DENIED_FINAL']),[]),'final appeal denial is terminal denied outcome');
lroSame('CLOSED_LOSS',SvAmazonLearnedRuleOutcome::classify(array_replace($base,['state'=>'CLOSED_LOSS']),[]),'documented loss is terminal');
$refs=SvAmazonLearnedRuleOutcome::evidenceRefs(array_replace($base,['state'=>'APPEAL_DENIED_FINAL']),[['id'=>44,'event_type'=>'SAFE_T_STATUS_READ','evidence_sha256'=>str_repeat('a',64)]]);
lroSame('event:44',$refs[0]??null,'outcome evidence references persisted timeline facts');
$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
foreach(['pendingOutcomes','SvAmazonLearnedRuleOutcome::classify','recordOutcome(','incrementOutcome('] as $needle)if(!str_contains($daemon,$needle))throw new RuntimeException('daemon outcome refresh missing '.$needle);
$reviews=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/ReviewRepository.php');if(!str_contains($reviews,'recordOutcome('))throw new RuntimeException('origin review outcome persistence missing');
echo "learned-rule-outcome-test: OK\n";
