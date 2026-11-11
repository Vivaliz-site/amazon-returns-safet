<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ruiAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$health=(string)file_get_contents($root.'/includes/amazon-returns/CockpitHealth.php');
$api=(string)file_get_contents($root.'/admin/amazon-returns/api/reviews.php');
$summary=(string)file_get_contents($root.'/admin/amazon-returns/api/summary.php');
$ui=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit.js');
$notify=(string)file_get_contents($root.'/includes/amazon-returns/ReviewOperations.php');
ruiAssert(str_contains($health,'SvAmazonReviewOperations::requiresManualDecision'),
    'Cockpit owner counter must not use the raw count of OPEN operational investigations');
ruiAssert(!str_contains($health,"$"."humanActions=max(0,(int)($"."runtime['pending_reviews']"),
    'A raw pending_reviews count mislabels financial investigations as owner decisions');
ruiAssert(str_contains($api,"'all','human','operational'"),
    'API must preserve unfiltered operational access and support optional owner-only view');
ruiAssert(str_contains($api,"'requires_human_decision'"),
    'Individual reviews must carry ownership classification');
foreach(["'human_total'","'operational_total'"] as $key)
    ruiAssert(str_contains($api,$key),'API missing '.$key);
ruiAssert(str_contains($ui,'reviews.php?view=human&per_page=100'),
    'Manual review tab should not demand owner decisions for operational investigations');
ruiAssert(str_contains($ui,'As investigações operacionais continuam na aba Casos'),
    'A clean owner queue must not imply financial investigations were resolved');
ruiAssert(str_contains($summary,"'operational_review_count'"),
    'The summary should expose ongoing operational work without labeling it human');
ruiAssert(str_contains($notify,'requiresManualDecision'),
    'The email and owner UI must share their classification logic');
echo "review-ownership-ui-contract-test: OK\n";
