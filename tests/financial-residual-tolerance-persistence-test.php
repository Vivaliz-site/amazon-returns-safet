<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/FinancialRevalidation.php';
require_once __DIR__.'/../includes/amazon-returns/FinancialCheckEvidence.php';
require_once __DIR__.'/../includes/amazon-returns/RuntimeAudit.php';

$errors=[];
function frpSame(mixed $want,mixed $got,string $why):void{global $errors;if($want!==$got)$errors[]=$why.' expected='.json_encode($want).' actual='.json_encode($got);}

$case=['id'=>501,'amazon_order_id'=>'702-0707321-6872209','expected_reimbursement_amount'=>'112.50','reconciled_credit_amount'=>'111.25'];
$result=['state'=>'RECOVERED','credit_amount'=>'111.25','outstanding_amount'=>'0.00','residual_tolerance_applied'=>true,'tolerated_residual_amount'=>'1.25','unclassified_transactions'=>0,'transaction_ids'=>['credit-1']];
$events=[['id'=>91,'case_id'=>501,'event_type'=>'FINANCIAL_REFRESH_CONFIRMED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-09-07 21:00:00','payload'=>['refresh_complete'=>true]]];

$confirmed=SvAmazonFinancialRevalidation::confirmation($case,$events,$result,'2026-09-07 21:05:00');
frpSame(true,$confirmed['payload']['residual_tolerance_applied']??null,'Durable financial confirmation must record that the approved residual tolerance was applied.');
frpSame('1.25',$confirmed['payload']['tolerated_residual_amount']??null,'Durable financial confirmation must retain the tolerated amount.');

$checked=SvAmazonFinancialCheckEvidence::reconciled(501,$events,$result,new DateTimeImmutable('2026-09-07 21:05:00',new DateTimeZone('UTC')));
frpSame(true,$checked['payload']['residual_tolerance_applied']??null,'Operational finance check must retain tolerance provenance.');
frpSame('1.25',$checked['payload']['tolerated_residual_amount']??null,'Operational finance check must retain tolerated amount.');

$audit=SvAmazonReturnsRuntimeAudit::financial($case,[],$result,true);
frpSame(true,$audit['residual_tolerance_applied']??null,'Runtime audit must expose the tolerance decision.');
frpSame('1.25',$audit['tolerated_residual_amount']??null,'Runtime audit must expose the tolerated amount.');

if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "financial-residual-tolerance-persistence-test: OK\n";
