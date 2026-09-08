<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ExternalWritePayload.php';

function cfsnAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$case=[
    'id'=>496,'amazon_order_id'=>'702-2751217-8386605','program'=>'FBA','safe_t_id'=>null,
    'expected_reimbursement_amount'=>'106.14','reconciled_credit_amount'=>'0.00',
    'physical_status'=>'NOT_RECEIVED',
];
$decision=[
    'action'=>'SELLER_SUPPORT_OPEN','reason'=>'CLASSIC_FBA_UNPAID_AFTER_FINANCE_RECONCILIATION',
    'idempotency_key'=>hash('sha256','classic-fba-support'),
];
$payload=SvAmazonExternalWritePayload::build($decision,$case,[]);
$text=(string)($payload['write_snapshot']['narrative']??'');
cfsnAssert(str_contains($text,'FBA'),'Classic FBA support narrative must identify the FBA reimbursement route.');
cfsnAssert(str_contains($text,'R$ 106,14'),'Narrative must state expected reimbursement.');
cfsnAssert(str_contains($text,'R$ 0,00'),'Narrative must state actual reconciled seller credit.');
cfsnAssert(str_contains($text,'R$ 106,14'),'Narrative must make the unpaid balance explicit.');
cfsnAssert(!str_contains($text,'SAFE-T ,'),'Classic FBA no-claim narrative must never render an empty SAFE-T identifier.');
cfsnAssert(!str_contains(mb_strtolower($text,'UTF-8'),'nova negativa'),'Classic FBA initial support escalation must not claim a prior denial that does not exist.');
echo "classic-fba-support-narrative-test: OK\n";
