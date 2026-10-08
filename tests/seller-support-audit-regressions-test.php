<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__.'/../includes/amazon-returns/ExternalWritePayload.php';

function ssarSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}
function ssarAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$base=[
    'id'=>13231,'amazon_order_id'=>'701-0009670-5933042','program'=>'FBA',
    'safe_t_id'=>null,'support_case_id'=>'22144700811','state'=>'SUPPORT_ESCALATION',
    'physical_status'=>'NOT_RECEIVED','refund_at'=>'2026-08-04 00:00:00',
    'seller_debit_at'=>'2026-08-04 00:00:00','refund_initiator'=>'AMAZON_CUSTOMER_SERVICE',
    'expected_reimbursement_amount'=>'124.20','reconciled_credit_amount'=>'0.00',
];
$finance=[
    'id'=>1,'case_id'=>13231,'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED',
    'source'=>'SP_API_FINANCES','occurred_at'=>'2026-09-30 10:00:00',
    'payload'=>['refresh_complete'=>true,'credit_amount'=>'0.00','outstanding_amount'=>'124.20',
        'ambiguous_reimbursement_transactions'=>0,'unsettled_financial_evidence'=>false],
];

$chat=[
    'case_id'=>'22144700811','case_status'=>'RESOLVED',
    'latest_text'=>'Continuas aí? Estou aqui para ajudar, porém vejo que não houve atividade nos últimos 2 minutos. '
        .'Caso contrário, precisarei encerrar este chat. Como não houve resposta nos últimos minutos, preciso encerrar este chat seguindo nosso protocolo padrão.',
];
ssarSame('SELLER_ACTION_REQUIRED',SvAmazonSellerSupportStatus::resolution($chat),'Support chat closed because ShopVivaLiz did not answer must remain actionable.');

$directSafeT=[
    'case_id'=>'22199842931','case_status'=>'RESOLVED',
    'latest_text'=>'A melhor forma de resolver essa questão é através de uma Reclamação SAFE-T. '
        .'Esse é o canal adequado para solicitar o ressarcimento. Para abrir sua reclamação, acesse Gerenciar reclamações SAFE-T.',
];
ssarSame('SAFE_T_SUBMIT',SvAmazonSellerSupportStatus::resolution($directSafeT),'Explicit instruction to open a SAFE-T claim must be detected.');

$directAppeal=[
    'case_id'=>'21839077561','case_status'=>'RESOLVED',
    'latest_text'=>'Para resolver esta situação, você precisará enviar uma apelação da resolução diretamente à equipe SAFE-T através do Safe-T. '
        .'Entre em contato diretamente na apelação.',
];
ssarSame('SAFE_T_APPEAL',SvAmazonSellerSupportStatus::resolution($directAppeal),'SAFE-T appeal instructions must outrank new-claim submission detection.');

$wrongTopic=[
    'case_id'=>'21772281431','case_status'=>'RESOLVED',
    'latest_text'=>'We have reviewed your request for the removal of feedback on your order and found that the feedback is in violation of our policy. '
        .'Resolved Feedback Removal Request.',
];
ssarSame('UNRELATED_TOPIC',SvAmazonSellerSupportStatus::resolution($wrongTopic),'A feedback-removal case must not be accepted as the reimbursement support thread.');

$claimedPaid=[
    'case_id'=>'22144820051','case_status'=>'RESOLVED',
    'latest_text'=>'Recebemos sua solicitação de revisão do ressarcimento FBA. Após análise, identificamos que o problema com o ressarcimento foi resolvido. '
        .'Para verificar os detalhes do pagamento em sua conta, acesse o extrato de transações. Caso o valor esperado não esteja refletido em seu extrato, verifique o saldo atualizado.',
];
ssarSame('SELLER_REIMBURSEMENT_CLAIMED',SvAmazonSellerSupportStatus::resolution($claimedPaid),'Amazon claiming a seller reimbursement/payment must be reconciled, not treated as an ambiguous terminal response.');

$denied=[
    'case_id'=>'22144307571','case_status'=>'RESOLVED',
    'latest_text'=>'Recebemos sua solicitação de revisão do reembolso FBA. Concluímos nossa investigação e, infelizmente, a unidade não é elegível para reembolso de acordo com a Política de Reembolso FBA. Essa decisão é definitiva.',
];
ssarSame('REIMBURSEMENT_DENIED',SvAmazonSellerSupportStatus::resolution($denied),'Explicit seller reimbursement denial must remain actionable.');

$engine=new SvAmazonSafeTDecisionEngine();
$chatEvent=['id'=>2,'case_id'=>13231,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL','occurred_at'=>'2026-09-30 10:01:00','payload'=>$chat];
$d=$engine->nextAction($base,[$finance,$chatEvent],['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'],new DateTimeImmutable('2026-09-30T10:05:00Z'));
ssarSame('SELLER_SUPPORT_UPDATE',$d['action']??null,'Closed inactive chat must be recovered in the same case.');
ssarSame('SUPPORT_REQUESTED_SELLER_RESPONSE',$d['reason']??null,'Closed inactive chat must use the seller-response recovery reason.');

$wrongCase=$base; $wrongCase['id']=13220; $wrongCase['amazon_order_id']='702-0251696-4938625'; $wrongCase['support_case_id']='21772281431'; $wrongCase['expected_reimbursement_amount']='101.87';
$wrongFinance=$finance; $wrongFinance['case_id']=13220; $wrongFinance['payload']['outstanding_amount']='101.87';
$wrongEvent=['id'=>3,'case_id'=>13220,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL','occurred_at'=>'2026-09-30 10:01:00','payload'=>$wrongTopic];
$wd=$engine->nextAction($wrongCase,[$wrongFinance,$wrongEvent],['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'],new DateTimeImmutable('2026-09-30T10:05:00Z'));
ssarSame('SELLER_SUPPORT_UPDATE',$wd['action']??null,'Unrelated support topic must trigger reimbursement recovery instead of silent wait.');
ssarSame('SUPPORT_TOPIC_MISMATCH_REIMBURSEMENT_RECOVERY',$wd['reason']??null,'Topic mismatch recovery reason must be explicit.');

$paidCase=$base; $paidCase['id']=13232; $paidCase['amazon_order_id']='701-3771172-5346610'; $paidCase['support_case_id']='22144820051'; $paidCase['expected_reimbursement_amount']='22.80';
$paidFinance=$finance; $paidFinance['case_id']=13232; $paidFinance['payload']['outstanding_amount']='22.80';
$paidEvent=['id'=>4,'case_id'=>13232,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL','occurred_at'=>'2026-09-30 10:01:00','payload'=>$claimedPaid];
$pd=$engine->nextAction($paidCase,[$paidFinance,$paidEvent],['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'],new DateTimeImmutable('2026-09-30T10:05:00Z'));
ssarSame('SELLER_SUPPORT_UPDATE',$pd['action']??null,'Claimed seller payment with fresh residual must be challenged in the same support case.');
ssarSame('SUPPORT_CLAIMED_REIMBURSEMENT_NOT_RECONCILED',$pd['reason']??null,'Claimed seller payment conflict must have a deterministic reason.');

$deniedCase=$base; $deniedCase['id']=13236; $deniedCase['amazon_order_id']='701-2474306-0966605'; $deniedCase['support_case_id']='22144307571'; $deniedCase['expected_reimbursement_amount']='24.62';
$deniedFinance=$finance; $deniedFinance['case_id']=13236; $deniedFinance['payload']['outstanding_amount']='24.62';
$deniedEvent=['id'=>5,'case_id'=>13236,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL','occurred_at'=>'2026-09-30 10:01:00','payload'=>$denied];
$dd=$engine->nextAction($deniedCase,[$deniedFinance,$deniedEvent],['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'],new DateTimeImmutable('2026-09-30T10:05:00Z'));
ssarSame('SELLER_SUPPORT_UPDATE',$dd['action']??null,'Explicit reimbursement denial with unpaid balance must receive a rebuttal.');
ssarSame('SUPPORT_REIMBURSEMENT_DENIAL_REBUTTAL',$dd['reason']??null,'Reimbursement denial must not become a silent terminal state.');


$acceptedAfterChat=[
    'id'=>6,'case_id'=>13231,'event_type'=>'SELLER_CENTRAL_ACTION_RESULT','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-30 10:06:00',
    'payload'=>[
        'action'=>'SELLER_SUPPORT_UPDATE','status'=>'ACCEPTED','submitted'=>true,
        'reason'=>'SUPPORT_CASE_UPDATED_VIA_REPLY_API_AND_READ_BACK',
        'external_id'=>'22144700811',
    ],
];
$afterWrite=$engine->nextAction(
    $base,
    [$finance,$chatEvent,$acceptedAfterChat],
    ['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'],
    new DateTimeImmutable('2026-09-30T10:07:00Z')
);
ssarSame('WAIT',$afterWrite['action']??null,'A successful Seller Support update after the latest observation must wait for a newer Amazon observation.');
ssarSame('SUPPORT_OUTBOUND_AWAITING_RESPONSE',$afterWrite['reason']??null,'Post-write wait must use a deterministic reason.');

$newChat=$chat;
$newChat['latest_text']=$chat['latest_text'].' Novo retorno da Amazon solicitando nossa resposta.';
$newObservation=[
    'id'=>7,'case_id'=>13231,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-30 10:08:00','payload'=>$newChat,
];
$rearmed=$engine->nextAction(
    $base,
    [$finance,$chatEvent,$acceptedAfterChat,$newObservation],
    ['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'],
    new DateTimeImmutable('2026-09-30T10:09:00Z')
);
ssarSame('SELLER_SUPPORT_UPDATE',$rearmed['action']??null,'A newer Seller Support observation after the successful write must rearm the recovery decision.');
ssarAssert(($rearmed['idempotency_key']??'')!==($d['idempotency_key']??''),'A materially newer Seller Support observation must generate a new idempotency key.');

foreach([$wd,$pd,$dd] as $decision){
    $payload=SvAmazonExternalWritePayload::build($decision,
        $decision===$wd?$wrongCase:($decision===$pd?$paidCase:$deniedCase),
        [$decision===$wd?$wrongFinance:($decision===$pd?$paidFinance:$deniedFinance)]
    );
    $n=(string)($payload['write_snapshot']['narrative']??'');
    ssarAssert($n!=='' && mb_stripos($n,'nós',0,'UTF-8')!==false,'Recovery replies must be first-person ShopVivaLiz narratives.');
}

echo "seller-support-audit-regressions-test: OK\n";
