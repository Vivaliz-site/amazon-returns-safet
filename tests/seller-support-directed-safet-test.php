<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__.'/../includes/amazon-returns/ExternalWritePayload.php';
function sdsSame(mixed $e,mixed $a,string $m):void{if($e!==$a)throw new RuntimeException($m.' expected='.var_export($e,true).' actual='.var_export($a,true));}
function sdsAssert(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);}
$case=[
 'id'=>13233,'amazon_order_id'=>'702-9494128-8106647','program'=>'FBA','safe_t_id'=>null,
 'support_case_id'=>'22144788261','support_case_status'=>'RESOLVED','state'=>'SUPPORT_ESCALATION',
 'physical_status'=>'NOT_RECEIVED','refund_at'=>'2026-07-30 05:51:34','seller_debit_at'=>'2026-07-30 05:51:34',
 'refund_initiator'=>'AMAZON_CUSTOMER_SERVICE','expected_reimbursement_amount'=>'49.00','reconciled_credit_amount'=>'0.00',
];
$finance=['id'=>10,'case_id'=>13233,'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-09-19 20:35:00','payload'=>[
 'refresh_complete'=>true,'credit_amount'=>'0.00','outstanding_amount'=>'49.00','ambiguous_reimbursement_transactions'=>0,'unsettled_financial_evidence'=>false,
]];
$text='Entendemos que você entrou em contato sobre o pedido 702-9494128-8106647, no qual o produto foi entregue ao comprador e reembolsado sem que o item retornasse ao seu estoque. Para pedidos FBA Onsite, o processo de solicitação de reembolso foi atualizado. Nos casos em que o pedido foi entregue, o reembolso foi concedido ao comprador e o produto não retornou ao seu estoque, é necessário registrar uma reivindicação SAFE-T. O tipo de reembolso aplicável é "Perdido em trânsito/Itens ausentes". Para registrar sua reivindicação, acesse o Seller Central e navegue até Gerenciar reivindicações SAFE-T e Registrar uma nova reivindicação SAFE-T.';
$support=['id'=>20,'case_id'=>13233,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL','occurred_at'=>'2026-09-19 20:40:00','payload'=>[
 'case_id'=>'22144788261','case_status'=>'RESOLVED','latest_text'=>$text,
]];
sdsSame('SAFE_T_SUBMIT',SvAmazonSellerSupportStatus::resolution($support['payload']),'Explicit Amazon instruction to register a new SAFE-T must outrank buyer-refund-only wording.');
$pendingSeller=$support['payload'];$pendingSeller['case_status']='PENDINGSELLERACTION';
sdsSame('SAFE_T_SUBMIT',SvAmazonSellerSupportStatus::resolution($pendingSeller),'Seller-action-pending support instruction must still route to SAFE-T.');
$pendingAmazon=$support['payload'];$pendingAmazon['case_status']='PENDINGAMAZONACTION';
sdsSame('ACTIVE',SvAmazonSellerSupportStatus::resolution($pendingAmazon),'Amazon-action-pending status must remain fail-closed even when old text mentions SAFE-T.');
$engine=new SvAmazonSafeTDecisionEngine();$now=new DateTimeImmutable('2026-09-19T21:00:00Z');
$d=$engine->nextAction($case,[$finance,$support],['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'],$now);
sdsSame('SAFE_T_SUBMIT',$d['action']??null,'Case-specific Amazon instruction must override generic classic-FBA support routing.');
sdsSame('SUPPORT_RESOLUTION_DIRECTS_SAFE_T_SUBMISSION',$d['reason']??null,'Support-directed SAFE-T reason must remain auditable.');
sdsSame('RNOTR',$d['reason_code']??null,'Support-directed missing return must use the return-not-received SAFE-T reason.');
sdsSame('RNOTR-a',$d['reason_subcategory']??null,'Support-directed missing return must pin the supported subcategory.');
sdsSame('22144788261',$d['support_case_id']??null,'Decision must retain the source Seller Support case.');
sdsAssert(preg_match('/^[a-f0-9]{64}$/',(string)($d['idempotency_key']??''))===1,'Support-directed SAFE-T must be idempotent.');
$snapshot=SvAmazonExternalWritePayload::build($d,$case,[$finance,$support])['write_snapshot']??[];$n=(string)($snapshot['narrative']??'');
foreach(['Amazon nos orientou expressamente','22144788261','Perdido em trânsito/Itens ausentes','nosso estoque','nosso ressarcimento como vendedores'] as $needle){sdsAssert(mb_stripos($n,$needle,0,'UTF-8')!==false,'SAFE-T narrative must preserve primary support evidence: '.$needle);}
sdsAssert(!str_contains($n,'SUPPORT_RESOLUTION_DIRECTS_SAFE_T_SUBMISSION'),'Internal decision code must not leak into SAFE-T narrative.');
$noFinance=$engine->nextAction($case,[$support],['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'],$now);
sdsSame('CHECK_FINANCES',$noFinance['action']??null,'Support-directed SAFE-T must still verify seller credit before writing.');
sdsSame('SUPPORT_DIRECTED_SAFE_T_REQUIRES_SELLER_CREDIT_CHECK',$noFinance['reason']??null,'Missing fresh finance proof must remain explicit.');
$received=$case;$received['physical_status']='RECEIVED_OK';
$conflict=$engine->nextAction($received,[$finance,$support],['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'],$now);
sdsSame('BLOCKED_REVIEW',$conflict['action']??null,'Physical receipt conflict must fail closed instead of filing a missing-return claim.');
sdsSame('SUPPORT_DIRECTED_SAFE_T_PHYSICAL_STATUS_CONFLICT',$conflict['reason']??null,'Physical conflict reason must remain auditable.');
$appealResolution=SvAmazonSellerSupportStatus::resolution(['case_id'=>'22144788261','case_status'=>'RESOLVED','latest_text'=>'O comprador foi reembolsado. Para a SAFE-T existente, envie recurso/appeal pelo Seller Central.']);
sdsSame('SAFE_T_APPEAL',$appealResolution,'Explicit SAFE-T appeal direction must outrank buyer-refund-only wording.');
$worker=(string)file_get_contents(dirname(__DIR__).'/scripts/amazon-returns/seller-central-bridge-worker.mjs');
sdsAssert(str_contains($worker,'job.payload?.decision?.reason_code'),'Bridge must consume reason codes persisted inside decision payload.');
sdsAssert(str_contains($worker,'job.payload?.decision?.reason_subcategory'),'Bridge must consume persisted SAFE-T subcategory.');
echo "seller-support-directed-safet-test: OK\n";
