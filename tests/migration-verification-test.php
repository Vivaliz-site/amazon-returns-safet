<?php
declare(strict_types=1);

function mvAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$path=__DIR__.'/../scripts/verify-migration.sh';
mvAssert(is_file($path),'Migration verification script must exist.');
$s=(string)file_get_contents($path);
foreach(['amazon_return_cases','amazon_return_events','amazon_return_outbox','amazon_return_dead_letters','amazon_return_evidence','amazon_return_policies','amazon_return_source_cursors','amazon_return_overrides'] as $table){
    mvAssert(str_contains($s,$table),'Migration verification must cover '.$table);
}
mvAssert(str_contains($s,'sha256sum'),'Migration verification must compare content hashes.');
mvAssert(str_contains($s,'COUNT(*)'),'Migration verification must compare row counts.');
mvAssert(str_contains($s,'eligibility_days <> 75'),'Migration verification must assert D+75.');
mvAssert(!str_contains($s,'cat "$env_file"'),'Migration verification must not print environment secrets.');

echo "migration-verification-test: OK\n";