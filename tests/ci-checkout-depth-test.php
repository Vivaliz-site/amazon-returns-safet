<?php
declare(strict_types=1);
$ci=(string)file_get_contents(__DIR__.'/../.github/workflows/ci.yml');
if(!preg_match('/uses:\s*actions\/checkout@v4\s*\R\s*with:\s*\R\s*fetch-depth:\s*(?:[2-9]|[1-9][0-9]+)/m',$ci)){
    throw new RuntimeException('CI checkout must fetch at least two commits because syntax/security contracts compare HEAD^ to HEAD.');
}
if(!str_contains($ci,'git diff --check HEAD^')){
    throw new RuntimeException('CI must keep the whitespace diff contract against the parent commit.');
}
echo "ci-checkout-depth-test: OK\n";
