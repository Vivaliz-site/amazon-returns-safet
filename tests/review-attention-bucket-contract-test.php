<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function aprAssert(bool $value,string $reason):void{if(!$value)throw new RuntimeException($reason);}
$repo=(string)file_get_contents($root.'/includes/amazon-returns/CaseRepository.php');
$ui=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit.js');
$coord=(string)file_get_contents($root.'/includes/amazon-returns/DecisionCoordinator.php');
aprAssert(str_contains($repo,"elseif((" . "$" . "filters['bucket']??null)==='attention')"),
    'Attention bucket must remain available');
aprAssert(str_contains($repo,'NOT $concluded AND EXISTS (SELECT 1 FROM amazon_return_reviews br'),
    'Owner attention requires a real open review');
aprAssert(!str_contains($repo,'NOT $concluded OR EXISTS (SELECT 1 FROM amazon_return_reviews br'),
    'All active financial investigations must not be mislabeled owner tasks');
aprAssert(str_contains($ui,"BLOCKED_REVIEW:'Impedimento em investigação pelo sistema'"),
    'Blocked operational work must not ask owner for decision');
aprAssert(str_contains($ui,"HUMAN_REVIEW:'Precisa da sua decisão'"),
    'Genuine owner-required decisions stay explicitly labeled');
aprAssert(str_contains($coord,"'review_owner'=>"),
    'Decision coordinator exposes who owns the next investigation');
echo "review-attention-bucket-contract-test: OK\n";
