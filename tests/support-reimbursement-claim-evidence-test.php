<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SellerSupportStatus.php';
function sice(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('SELLER_CREDIT_EVIDENCE: '.$why);}
$base=['case_id'=>'22515611011','case_status'=>'RESOLVED'];
$notApproved=$base+['latest_text'=>'FBA Returns Reimbursement. O Reembolso não pode ser aprovado no momento, a janela de devolução vai até 6 de novembro de 2026. Se não retornar, abra novo chamado. Crédito aplicado anteriormente ao comprador.'];
sice(SvAmazonSellerSupportStatus::resolution($notApproved)==='REIMBURSEMENT_DENIED',
 'Explicit most recent denial cannot be confused with a credited seller account.');
$generic=$base+['latest_text'=>'[CASE RESOLVED] FBA Returns Reimbursement. Your case was resolved. We hope this information helped you.'];
sice(SvAmazonSellerSupportStatus::resolution($generic)!=='SELLER_REIMBURSEMENT_CLAIMED',
 'Ticket subject and closure alone are not proof of any seller reimbursement.');
$paid=$base+['latest_text'=>'O crédito de ressarcimento de R$51,61 foi creditado na sua conta de vendedor em 08/10/2026.'];
sice(SvAmazonSellerSupportStatus::resolution($paid)==='SELLER_REIMBURSEMENT_CLAIMED',
 'Explicit seller credit must preserve reconciliation flow.');
echo "support-reimbursement-claim-evidence-test: OK\n";
