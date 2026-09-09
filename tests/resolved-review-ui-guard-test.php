<?php
declare(strict_types=1);

function rrAssert(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
}
function rrSource(string $path):string{
    $full=dirname(__DIR__).'/'.$path;
    if(!is_file($full))throw new RuntimeException('Missing '.$path);
    return (string)file_get_contents($full);
}

$review=rrSource('admin/amazon-returns/api/review.php');
$case=rrSource('admin/amazon-returns/api/case.php');
$index=rrSource('admin/amazon-returns/index.php');
$js=rrSource('admin/amazon-returns/assets/cockpit.js');
$focus=rrSource('admin/amazon-returns/assets/review-focus.js');

rrAssert(str_contains($review,"'REVIEW_NOT_OPEN'"),'Resolved review reads must return REVIEW_NOT_OPEN.');
rrAssert(str_contains($review,"['status']") && str_contains($review,"'OPEN'"),'Review detail endpoint must guard review status.');
rrAssert(str_contains($review,',409'),'Resolved review reads must use HTTP 409.');
rrAssert(!str_contains($case,'$currentReview=$reviews[array_key_last($reviews)]'),'Case detail must not expose a historical review as current.');
rrAssert(str_contains($js,'REVIEW_NOT_OPEN'),'Cockpit must recognize a review resolved by automation.');
rrAssert(str_contains($focus,'isResolvedReviewError'),'Review focus helper must detect resolved-review feedback.');
rrAssert(str_contains($focus,"panel.classList.add('hidden')"),'Resolved review feedback must close the stale panel.');
rrAssert(str_contains($focus,'window.loadReviews'),'Resolved review feedback must refresh the open review queue.');
rrAssert(str_contains($focus,'window.loadSummary'),'Resolved review feedback must refresh the pending count.');
rrAssert(str_contains($index,'review-focus.js?v=review-open-2'),'Changed review helper must be cache-busted in the cockpit page.');

echo "resolved-review-ui-guard-test: OK\n";
