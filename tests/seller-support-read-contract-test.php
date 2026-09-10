<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/RemoteBridge.php';

function ssrcAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function ssrcSame(mixed $expected,mixed $actual,string $message):void{if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));}

$validated=SvAmazonReturnsRemoteBridge::validateResult([
    'status'=>'ACCEPTED','submitted'=>false,'external_id'=>'21839128801','retry_safe'=>true,
    'support'=>[
        'case_id'=>'21839128801',
        'case_status'=>'Resolved',
        'latest_text'=>'Seu crédito foi processado com sucesso. Aguarde 4 a 5 dias úteis. Reimbursement ID: 23206413461.',
    ],
]);
ssrcAssert(is_array($validated['support']??null),'Seller Support observations must survive bridge validation.');
ssrcSame('21839128801',$validated['support']['case_id']??null,'Support observation must preserve the case id.');
ssrcSame('RESOLVED',$validated['support']['case_status']??null,'Support status must normalize to uppercase.');
ssrcAssert(str_contains((string)($validated['support']['latest_text']??''),'23206413461'),'Support response text must survive bounded validation for deterministic routing.');

$service=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/StatusBridgeService.php');
$reader=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-safe-t-read-worker.mjs');
ssrcAssert(str_contains($service,"'SELLER_SUPPORT_READ'"),'Read-only status service must schedule and accept Seller Support observations.');
ssrcAssert(str_contains($service,'SELLER_SUPPORT_STATUS_OBSERVED'),'Read-only status service must persist immutable Seller Support observations.');
ssrcAssert(str_contains($reader,"job.action === 'SELLER_SUPPORT_READ'"),'Read-only Seller Central worker must execute Seller Support reads.');
ssrcAssert(str_contains($reader,'SearchForCases'),'Read-only Seller Central worker must use the authenticated support case API for reconciliation.');
ssrcAssert(str_contains($reader,'ViewCase'),'Read-only Seller Central worker must read authoritative support case details.');
ssrcAssert(!str_contains($reader,'SELLER_SUPPORT_OPEN'),'Read-only Seller Central worker must never contain Seller Support write actions.');
ssrcAssert(!str_contains($reader,'SELLER_SUPPORT_UPDATE'),'Read-only Seller Central worker must never contain Seller Support update actions.');

echo "seller-support-read-contract-test: OK\n";
