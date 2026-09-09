<?php
declare(strict_types=1);
$src=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$errors=[];
if(!str_contains($src,"const drainBlocked = ['AUTH_REQUIRED','HUMAN_CHALLENGE'].includes(result.status);"))$errors[]='worker must classify auth/challenge as drain blockers';
if(!str_contains($src,"return { processed: true, drainBlocked };"))$errors[]='runOnce must expose drain blocker state';
if(!str_contains($src,"if (!outcome.processed || outcome.drainBlocked) break;"))$errors[]='--drain must stop after the first auth/challenge result';
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);} echo "bridge-auth-block-stops-drain-test: OK\n";