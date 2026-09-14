<?php
declare(strict_types=1);
$source=file_get_contents(__DIR__.'/../includes/amazon-returns/CaseRepository.php');
if(!is_string($source)){fwrite(STDERR,"Unable to read CaseRepository.php\n");exit(1);}
$expected="AND (refund_at IS NULL OR refund_at > DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)) ";
if(!str_contains($source,$expected)){
    fwrite(STDERR,"openCases must exclude refund_at at or beyond D+90 while retaining rows without refund_at\n");
    exit(1);
}
echo "open-cases-refund-window-contract-test: OK\n";
