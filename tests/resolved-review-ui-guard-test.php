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
$js=rrSource('admin/amazon-returns/assets/cockpit.js');

rrAssert(str_contains($review,"'REVIEW_NOT_OPEN'"),'Resolved review reads must return REVIEW_NOT_OPEN.');
rrAssert(str_contains($review,"['status']") && str_contains($review,"'OPEN'"),'Review detail endpoint must guard review status.');
rrAssert(str_contains($review,',409'),'Resolved review reads must use HTTP 409.');
rrAssert(!str_contains($case,'$currentReview=$reviews[array_key_last($reviews)]'),'Case detail must not expose a historical review as current.');
rrAssert(str_contains($js,'REVIEW_NOT_OPEN'),'Cockpit must recognize a review resolved by automation.');
rrAssert(str_contains($js,'await loadReviews()'),'Cockpit must refresh the open review queue after a stale review is detected.');
rrAssert(str_contains($js,'await loadSummary()'),'Cockpit must refresh pending review counts after a stale review is detected.');

echo "resolved-review-ui-guard-test: OK\n";
