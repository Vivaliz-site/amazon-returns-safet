<?php
declare(strict_types=1);

/**
 * Guards scripts/governance/check-issue-comment-listeners.py: verifies it
 * correctly counts direct top-level `issue_comment` GitHub Actions triggers
 * across a workflows directory, and fails closed when more than one active
 * listener is declared.
 */

function iclAssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function iclAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

/** @return array{0:int,1:string,2:string} [exit code, stdout, stderr] */
function iclRunGuard(string $workflowsDir): array
{
    $script = __DIR__ . '/../scripts/governance/check-issue-comment-listeners.py';
    iclAssertTrue(is_file($script), "Guard script must exist at $script");

    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        ['python3', $script, $workflowsDir],
        $descriptors,
        $pipes
    );
    iclAssertTrue($process !== false, 'Failed to launch guard script process.');

    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$exitCode, $stdout, $stderr];
}

function iclMakeTempWorkflowsDir(): string
{
    $base = sys_get_temp_dir() . '/issue-comment-listener-guard-' . bin2hex(random_bytes(8));
    $workflowsDir = $base . '/.github/workflows';
    iclAssertTrue(mkdir($workflowsDir, 0777, true), "Failed to create temp dir $workflowsDir");
    return $workflowsDir;
}

function iclWriteWorkflow(string $dir, string $filename, string $contents): void
{
    file_put_contents($dir . '/' . $filename, $contents);
}

function iclRemoveDirRecursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            iclRemoveDirRecursive($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

// --- Fixture 1: 2 workflows with a direct top-level issue_comment trigger. ---
// Must be reported/failed with count 2.
$twoListenersDir = iclMakeTempWorkflowsDir();
iclWriteWorkflow($twoListenersDir, 'listener-one.yml', <<<'YAML'
name: Listener One
on:
  issue_comment:
    types: [created]
jobs:
  respond:
    runs-on: ubuntu-latest
    steps:
      - run: echo "hi"
YAML
);
iclWriteWorkflow($twoListenersDir, 'listener-two.yml', <<<'YAML'
name: Listener Two
on: [push, issue_comment]
jobs:
  respond:
    runs-on: ubuntu-latest
    steps:
      - run: echo "hi"
YAML
);
iclWriteWorkflow($twoListenersDir, 'unrelated-ci.yml', <<<'YAML'
name: Unrelated CI
on:
  push:
    branches: [main]
  pull_request:
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - run: echo "not a listener; only mentions issue_comment in a comment: issue_comment"
YAML
);
// A file that merely mentions issue_comment inside job steps / strings must
// NOT count as a direct top-level trigger.
iclWriteWorkflow($twoListenersDir, 'mentions-only.yml', <<<'YAML'
name: Mentions Only
on:
  workflow_dispatch:
jobs:
  noop:
    runs-on: ubuntu-latest
    steps:
      - run: echo "this workflow reacts to issue_comment indirectly via another tool"
YAML
);
// A disabled workflow with issue_comment must be skipped entirely.
iclWriteWorkflow($twoListenersDir, 'disabled-listener.yml.disabled', <<<'YAML'
name: Disabled Listener
on:
  issue_comment:
    types: [created]
jobs:
  respond:
    runs-on: ubuntu-latest
    steps:
      - run: echo "hi"
YAML
);

[$exitCode, $stdout, $stderr] = iclRunGuard($twoListenersDir);
iclAssertSame(1, $exitCode, "Guard must exit 1 when 2 direct issue_comment listeners exist.\nstdout:\n$stdout\nstderr:\n$stderr");
iclAssertTrue(
    str_contains($stdout, 'issue_comment_listener_count=2'),
    "Guard must report count 2 for the two-listener fixture.\nstdout:\n$stdout"
);
iclAssertTrue(str_contains($stdout, 'listener-one.yml'), 'Guard must name listener-one.yml as a match.');
iclAssertTrue(str_contains($stdout, 'listener-two.yml'), 'Guard must name listener-two.yml as a match.');
iclAssertTrue(!str_contains($stdout, 'mentions-only.yml'), 'Guard must not flag a workflow that only mentions issue_comment in a step.');
iclAssertTrue(!str_contains($stdout, 'disabled-listener'), 'Guard must skip .disabled workflow files entirely.');

iclRemoveDirRecursive(dirname($twoListenersDir, 2));

// --- Fixture 2: 0 direct issue_comment triggers. Must pass (exit 0). ---
$zeroListenersDir = iclMakeTempWorkflowsDir();
iclWriteWorkflow($zeroListenersDir, 'ci.yml', <<<'YAML'
name: CI
on:
  push:
    branches: [main]
  pull_request:
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - run: echo "test"
YAML
);
iclWriteWorkflow($zeroListenersDir, 'dispatch-only.yml', <<<'YAML'
name: Dispatch Only
on:
  workflow_dispatch:
jobs:
  noop:
    runs-on: ubuntu-latest
    steps:
      - run: echo "noop"
YAML
);

[$exitCodeZero, $stdoutZero, $stderrZero] = iclRunGuard($zeroListenersDir);
iclAssertSame(0, $exitCodeZero, "Guard must exit 0 for zero issue_comment listeners.\nstdout:\n$stdoutZero\nstderr:\n$stderrZero");
iclAssertTrue(
    str_contains($stdoutZero, 'issue_comment_listener_count=0'),
    "Guard must report count 0 for the zero-listener fixture.\nstdout:\n$stdoutZero"
);

iclRemoveDirRecursive(dirname($zeroListenersDir, 2));

// --- Fixture 3: exactly 1 direct issue_comment trigger. Must pass (exit 0). ---
$oneListenerDir = iclMakeTempWorkflowsDir();
iclWriteWorkflow($oneListenerDir, 'sole-listener.yml', <<<'YAML'
name: Sole Listener
on:
  issue_comment:
    types: [created]
jobs:
  respond:
    runs-on: ubuntu-latest
    steps:
      - run: echo "hi"
YAML
);
iclWriteWorkflow($oneListenerDir, 'ci.yml', <<<'YAML'
name: CI
on:
  push:
    branches: [main]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - run: echo "test"
YAML
);

[$exitCodeOne, $stdoutOne, $stderrOne] = iclRunGuard($oneListenerDir);
iclAssertSame(0, $exitCodeOne, "Guard must exit 0 for exactly one issue_comment listener.\nstdout:\n$stdoutOne\nstderr:\n$stderrOne");
iclAssertTrue(
    str_contains($stdoutOne, 'issue_comment_listener_count=1'),
    "Guard must report count 1 for the one-listener fixture.\nstdout:\n$stdoutOne"
);

iclRemoveDirRecursive(dirname($oneListenerDir, 2));

// --- Fixture 4: real repository workflows must currently pass the guard. ---
$realWorkflowsDir = __DIR__ . '/../.github/workflows';
[$exitCodeReal, $stdoutReal, $stderrReal] = iclRunGuard($realWorkflowsDir);
iclAssertSame(0, $exitCodeReal, "Guard must pass against this repository's real workflows.\nstdout:\n$stdoutReal\nstderr:\n$stderrReal");

echo "issue-comment-listener-guard-test: OK\n";
