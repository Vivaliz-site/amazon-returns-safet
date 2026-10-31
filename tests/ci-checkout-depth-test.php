<?php
declare(strict_types=1);
$ci=(string)file_get_contents(__DIR__.'/../.github/workflows/ci.yml');
if(!preg_match('/uses:\s*actions\/checkout@v4\s*\R\s*with:\s*\R\s*fetch-depth:\s*(?:0|[2-9]|[1-9][0-9]+)/m',$ci)){
    throw new RuntimeException('CI checkout must fetch full history or at least two commits for auditable diff checks.');
}
if(!str_contains($ci,'git diff --check HEAD^')){
    throw new RuntimeException('CI must keep the whitespace diff contract against the parent commit.');
}
if(!str_contains($ci,'git diff --check "$BASE_SHA...$HEAD_SHA"')){ throw new RuntimeException('CI must check the entire PR diff, not only the final commit.'); }
echo "ci-checkout-depth-test: OK\n";
