<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Runtime.php';
$cadence=SvAmazonReturnsRuntime::cadences()['review_operations']??null;
if($cadence!==7200)throw new RuntimeException('Review suggestions/reminders must run at least every two hours; got '.json_encode($cadence));
$source=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/ReviewOperations.php');
if(!str_contains($source,'SvAmazonHybridReviewAdvisor'))throw new RuntimeException('Automatic review operations must use the hybrid ChatGPT/Codex advisor.');
if(!str_contains($source,'SvAmazonReviewMemoryContext'))throw new RuntimeException('Automatic review operations must enrich AI context with persisted memory.');
echo "review-operations-cadence-test: OK\n";