<?php
declare(strict_types=1);
$runner=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/run-seller-central-daily.sh');
if(!str_contains($runner,'PREEXISTING_CDP_UNOWNED')){
    throw new RuntimeException('Daily browser runner must fail closed instead of reusing a pre-existing CDP session.');
}
if(!preg_match('/curl[^\n]+json\/version[^\n]*;\s*then\s*\n\s*echo\s+"PREEXISTING_CDP_UNOWNED"/m',$runner)){
    throw new RuntimeException('Pre-existing CDP check must happen before the runner launches or drains browser workers.');
}
if(str_contains($runner,'pkill')){
    throw new RuntimeException('CDP exclusivity must not be implemented by killing unrelated browser processes.');
}
echo "linux-browser-exclusive-cdp-test: OK\n";
