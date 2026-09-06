<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ReviewAdvisor.php';
if(!interface_exists('SvAmazonReviewAdvisor'))throw new RuntimeException('advisor interface missing');
$ref=new ReflectionClass('SvAmazonReviewAdvisor');if(!$ref->hasMethod('suggest'))throw new RuntimeException('suggest contract missing');
echo "review-advisor-test: OK\n";
