<?php
declare(strict_types=1);
$cases=file_get_contents(__DIR__.'/../includes/amazon-returns/CaseRepository.php');
$coordinator=file_get_contents(__DIR__.'/../includes/amazon-returns/DecisionCoordinator.php');
if(!is_string($cases)||!is_string($coordinator)){fwrite(STDERR,"case/review scheduler sources missing\n");exit(1);}
$errors=[];
if(strpos($cases,"OR EXISTS (SELECT 1 FROM amazon_return_reviews")===false){
    $errors[]='openCases() must temporarily include closed/terminal cases while they still have an OPEN review.';
}
if(strpos($cases,"r.status='OPEN'")===false){
    $errors[]='The terminal-case exception must be limited to OPEN reviews.';
}
if(strpos($cases,'r.case_id=amazon_return_cases.id')===false){
    $errors[]='The stale-review exception must be bound to the same case.';
}
if(strpos($coordinator,'resolveOpenForCase')===false){
    $errors[]='DecisionCoordinator must resolve an open review after the terminal case yields a non-review decision.';
}
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "terminal-review-cleanup-test: OK\n";
