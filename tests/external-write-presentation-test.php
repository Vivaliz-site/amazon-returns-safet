<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ExternalWritePresentation.php';

function externalWriteAssert(bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException('EXTERNAL_WRITE_PRESENTATION: '.$reason);
}

$base=[
    'id'=>4083929,'kind'=>'SELLER_SUPPORT_OPEN','status'=>'SUPERSEDED',
    'created_at'=>'2026-11-13 03:00:00','updated_at'=>'2026-11-13 03:40:12',
    'last_error'=>'HUMAN_INTERVENTION_REQUIRED: details or provider data must remain private',
    'payload_json'=>'{"secret":"must-not-leak"}',
    'attempt_count'=>1,
];
$presentation=SvAmazonExternalWritePresentation::fromOutboxRow($base);
externalWriteAssert(is_array($presentation),'O resultado deve ser um array');
externalWriteAssert(($presentation['attention_code']??null)==='HUMAN_INTERVENTION_REQUIRED','Intervenção precisa aparecer para o operador');
externalWriteAssert(($presentation['status']??null)==='SUPERSEDED','O estado real da fila não pode ser alterado');
externalWriteAssert(($presentation['id']??null)===4083929,'ID técnico da fila precisa ser preservado');
externalWriteAssert(!array_key_exists('last_error',$presentation),'Erro bruto não pode vazar ao navegador');
externalWriteAssert(!array_key_exists('payload_json',$presentation),'Payload não pode vazar ao navegador');
externalWriteAssert(!array_key_exists('attempt_count',$presentation),'Metadados internos fora do contrato não podem ser expostos');

$cases=[
 ['SELLER_SUPPORT_SCOPE_SUPERSEDED','SUPPORT_REFERENCE_CHANGED'],
 ['SUPERSEDED_BY_CURRENT_DECISION: CASE_CLOSED','DECISION_CHANGED'],
 ['OTHER PRIVATE MESSAGE',null],
];
foreach ($cases as [$error,$expected]) {
    $row=$base;$row['last_error']=$error;
    externalWriteAssert(SvAmazonExternalWritePresentation::fromOutboxRow($row)['attention_code']===$expected,'Classificação incorreta: '.$expected);
}
$row=$base;$row['status']='PENDING';$row['last_error']='UI_DRIFT: SUPPORT_WRITE_WITHOUT_READBACK_ID';
externalWriteAssert(SvAmazonExternalWritePresentation::fromOutboxRow($row)['attention_code']==='EXTERNAL_ID_UNCONFIRMED','Sem ID externo exige confirmação');

$row=$base;$row['status']='SUCCEEDED';$row['last_error']='HUMAN_INTERVENTION_REQUIRED: stale';
externalWriteAssert(SvAmazonExternalWritePresentation::fromOutboxRow($row)['attention_code']===null,'Não reaproveitar motivo antigo em sucesso');
externalWriteAssert(SvAmazonExternalWritePresentation::fromOutboxRow(null)===null,'Valor ausente deve permanecer ausente');

$casesEndpoint=(string)file_get_contents(__DIR__.'/../admin/amazon-returns/api/cases.php');
$caseEndpoint=(string)file_get_contents(__DIR__.'/../admin/amazon-returns/api/case.php');
foreach ([$casesEndpoint,$caseEndpoint] as $source) {
    externalWriteAssert(str_contains($source,'SvAmazonExternalWritePresentation::fromOutboxRow('),'Lista e detalhe precisam usar a mesma projeção sanitizada');
}
echo "external-write-presentation-test: OK\n";
