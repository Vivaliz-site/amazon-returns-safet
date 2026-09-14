<?php
declare(strict_types=1);
$source=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/run-seller-central-daily.sh');
$write=strpos($source,'seller-central-bridge-worker.mjs" "$bridge_mode"');
$read=strpos($source,'seller-central-safe-t-read-worker.mjs" --drain');
if($write===false||$read===false||$write>=$read)throw new RuntimeException('Seller Central cycle must drain actionable bridge jobs before discovery/read backlog.');
echo "seller-central-write-priority-test: OK\n";
