<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = file_get_contents($root . '/admin/amazon-returns/index.php');
$helper = @file_get_contents($root . '/admin/amazon-returns/assets/review-focus.js');

if ($index === false) throw new RuntimeException('Could not read admin index.');
if ($helper === false) throw new RuntimeException('review-focus.js is missing.');

$checks = [
    str_contains($index, '/admin/amazon-returns/assets/review-focus.js'),
    str_contains($helper, 'scrollIntoView'),
    str_contains($helper, 'Abrindo revisão'),
    str_contains($helper, '#cockpit-error'),
];

if (in_array(false, $checks, true)) {
    throw new RuntimeException('Opening a review must provide immediate visible feedback and move focus to the review panel or error.');
}

echo "review-open-ui-contract-test: OK\n";
