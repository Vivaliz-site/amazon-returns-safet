<?php
declare(strict_types=1);
$file=__DIR__.'/../includes/amazon-returns/FinancialCheckEvidence.php';if(!is_file($file)){fwrite(STDERR,"Missing case-scoped financial freshness evidence\n");exit(1);}require_once $file;
$now=new DateTimeImmutable('2026-09-05T15:00:00Z');$result=['state'=>'CREDIT_PENDING','credit_amount'=>'0.00','outstanding_amount'=>'100.00','unclassified_transactions'=>0];
$event=SvAmazonFinancialCheckEvidence::refresh(77,'702-1111111-2222222',$now);$event['id']=100;
$check=SvAmazonFinancialCheckEvidence::reconciled(77,[$event],$result,$now);
if(($check['payload']['refresh_complete']??false)!==true)throw new RuntimeException('A fresh completed case refresh must be provable');
if(($check['payload']['financial_truth']??true)!==false)throw new RuntimeException('Freshness metadata cannot pretend to be a payment');
if(SvAmazonFinancialCheckEvidence::reconciled(78,[$event],$result,$now)!==null)throw new RuntimeException('Another case cannot reuse financial freshness');
$old=$event;$old['occurred_at']='2026-09-01 12:00:00';if(SvAmazonFinancialCheckEvidence::reconciled(77,[$old],$result,$now)!==null)throw new RuntimeException('Stale read cannot authorize a fresh financial decision');
if(SvAmazonFinancialCheckEvidence::reconciled(77,[],$result,$now)!==null)throw new RuntimeException('No response is not evidence of no credit');
$untrusted=$event;$untrusted['source']='GMAIL';if(SvAmazonFinancialCheckEvidence::reconciled(77,[$untrusted],$result,$now)!==null)throw new RuntimeException('Email is not a financial API refresh');
$again=SvAmazonFinancialCheckEvidence::reconciled(77,[$event],$result,$now->modify('+1 minute'));
if($again['idempotency_key']!==$check['idempotency_key'])throw new RuntimeException('Same observed result must be idempotent');
$bad=$event;$bad['occurred_at']='';if(SvAmazonFinancialCheckEvidence::reconciled(77,[$bad],$result,(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+1 minute'))!==null)throw new RuntimeException('Missing observation time must not become current time');
echo "financial-check-evidence-test: OK\n";
