<?php
declare(strict_types=1);

// Recovery after a GitHub App merge whose push workflow trigger is suppressed:
// run the same full CI via GitHub's official workflow_dispatch, never a bypass.
$workflow = (string)file_get_contents(__DIR__ . '/../.github/workflows/ci.yml');
$header = explode('permissions:', $workflow, 2)[0];
foreach (['  push:', '    branches: [main]', '  pull_request:', '  workflow_dispatch:'] as $required) {
    if (!str_contains($header, $required)) {
        throw new RuntimeException("Amazon Returns CI missing trigger: {$required}");
    }
}
if (!str_contains($workflow, 'python3 scripts/ci-impact-selection.py --force-full --github-output "$GITHUB_OUTPUT"')) {
    throw new RuntimeException('Manually dispatched main CI must run all impacted suites.');
}
if (!str_contains($workflow, 'for test in tests/*.php; do php "$test"; done')
    || !str_contains($workflow, 'node --test tests/*.test.mjs')) {
    throw new RuntimeException('Manual CI must not omit PHP or Node regressions.');
}
echo "ci-workflow-dispatch-contract-test: OK\n";
