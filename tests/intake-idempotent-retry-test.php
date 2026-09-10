<?php
declare(strict_types=1);

function intakeRetryAssert(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}

$path=__DIR__.'/../admin/amazon-returns/api/intake.php';
$source=file_get_contents($path);
intakeRetryAssert(is_string($source),'Unable to read intake endpoint.');

$idempotencyPos=strpos($source,'$existingId=$p->events->findIdByIdempotencyKey($idempotency);');
$outstandingPos=strpos($source,'if($quantity>$outstanding)');
intakeRetryAssert(is_int($idempotencyPos),'Intake endpoint must check the existing operation idempotency key.');
intakeRetryAssert(is_int($outstandingPos),'Intake endpoint must validate outstanding quantity for new receipts.');
intakeRetryAssert(
    $idempotencyPos<$outstandingPos,
    'A retry of an already committed receipt must resolve the existing idempotency key before remaining-quantity validation.'
);

$duplicateReplyPos=strpos($source,"'duplicate'=>true");
intakeRetryAssert(is_int($duplicateReplyPos) && $duplicateReplyPos<$outstandingPos,'Duplicate receipt retries must return the original projection before new-receipt quantity checks.');

echo "intake-idempotent-retry-test: OK\n";
