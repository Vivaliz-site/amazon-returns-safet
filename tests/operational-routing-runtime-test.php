<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/RuntimeAudit.php';
$errors=[];
$daemon=file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
foreach(['SvAmazonFinancialCheckEvidence::refresh(','SvAmazonFinancialCheckEvidence::reconciled(','financial_checks_requested','next_action_at'] as $needle){if(!str_contains($daemon,$needle))$errors[]='Runtime missing '.$needle;}
$a=SvAmazonReturnsRuntimeAudit::decision(['id'=>1,'eligibility_at'=>'2026-08-15 00:00:00'],[],['eligible'=>true,'eligibility_at'=>'2026-07-16 00:00:00'],['action'=>'CHECK_FINANCES','reason'=>'PROMISE_EXPIRED','operational_mode'=>'CHECK_FINANCES','next_action_at'=>'2026-09-05 18:00:00']);
if(($a['eligibility_at']??null)!=='2026-07-16 00:00:00')$errors[]='Audit must prefer recalculated policy over stale75-day projection';
if(($a['operational_mode']??null)!=='CHECK_FINANCES')$errors[]='Audit must expose the actual operational route';
if(($a['next_action_at']??null)!=='2026-09-05 18:00:00')$errors[]='Audit must expose the scheduled date';
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "operational-routing-runtime-test: OK\n";
