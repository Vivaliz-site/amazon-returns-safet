<?php
declare(strict_types=1);
$file=__DIR__.'/../includes/amazon-returns/FinancialRevalidation.php';
if(!is_file($file)){fwrite(STDERR,"Missing production financial resumption evidence\n");exit(1);}require_once $file;
function freSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
$case=['id'=>77,'reconciled_credit_amount'=>'0.00'];$result=['credit_amount'=>'0.00','outstanding_amount'=>'100.00'];
freSame(null,SvAmazonFinancialRevalidation::confirmation($case,[],$result,'2026-09-10 12:00:00'),'no API refresh is not a negative balance proof');
$source=SvAmazonFinancialRevalidation::sourceEvent(77,true,'2026-09-10 11:55:00');$source['id']=501;
$confirmed=SvAmazonFinancialRevalidation::confirmation($case,[$source],$result,'2026-09-10 12:00:00');
freSame('FINANCIAL_RECONCILIATION_CONFIRMED',$confirmed['event_type'],'production event identifies actual calculation');
freSame('2026-09-10 11:55:00',$confirmed['payload']['source_refreshed_at'],'keep API observation time rather than falsely refreshing old money');
freSame('0.00',$confirmed['payload']['credit_amount'],'exact calculated amount retained');
freSame(501,$confirmed['payload']['source_observation_id'],'link to immutable API refresh');
$failed=SvAmazonFinancialRevalidation::sourceEvent(77,false,'2026-09-10 11:59:00');$failed['id']=502;
freSame(null,SvAmazonFinancialRevalidation::confirmation($case,[$source,$failed],$result,'2026-09-10 12:00:00'),'failed latest refresh cannot authorize resumption');
$foreign=SvAmazonFinancialRevalidation::sourceEvent(88,true,'2026-09-10 11:58:00');
freSame(null,SvAmazonFinancialRevalidation::confirmation($case,[$foreign],$result,'2026-09-10 12:00:00'),'other case data cannot authorize resumption');
freSame(null,SvAmazonFinancialRevalidation::confirmation($case,[$source],$result,'2026-09-10 11:00:00'),'future API observation is not valid evidence');
$due=new DateTimeImmutable('2026-09-10T03:00:00Z');$now=new DateTimeImmutable('2026-09-10T12:00:00Z');
freSame(true,SvAmazonRequestedWait::financiallyRechecked($case,[$source,$confirmed],$due,$now),'confirmed refreshed calculation authorizes due-date gate');
freSame(false,SvAmazonRequestedWait::financiallyRechecked($case,[$source,$confirmed,$failed],$due,$now),'new failed refresh revokes older clearance');
echo "financial-resume-evidence-test: OK\n";
