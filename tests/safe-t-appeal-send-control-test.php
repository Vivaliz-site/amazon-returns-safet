<?php
declare(strict_types=1);

function stasAssert(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException($message);
}
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$start=strpos($worker,'async function safeTAppeal');
$end=$start===false?false:strpos($worker,'async function hillChatReady',$start);
stasAssert($start!==false && $end!==false,'SAFE-T appeal flow must remain auditable.');
$appeal=substr($worker,(int)$start,(int)$end-(int)$start);
stasAssert(str_contains($appeal,"kat-button.right-floated[label=\"Enviar\"]"),'Legacy Portuguese KAT appeal button remains supported.');
stasAssert(
    str_contains($appeal,"clickButtonTrustedByText(['Send','Enviar'])"),
    'Current SAFE-T appeal UI uses a visible plain Send button, so the bridge must support trusted semantic Send/Enviar click.'
);
stasAssert(str_contains($worker,'async function waitForSafeTAppealField'),'SAFE-T appeal field readiness must use a bounded polling helper.');
stasAssert(str_contains($appeal,'await waitForSafeTAppealField(cdp)'),'SAFE-T appeal flow must wait for the asynchronously rendered appeal field before declaring it unavailable.');
stasAssert(str_contains($worker,'SAFE_T_APPEAL_FIELD_WAIT_MS'),'SAFE-T appeal field wait must be explicitly bounded.');
stasAssert(str_contains($appeal,'SAFE_T_APPEAL_SEND_MISSING'),'Missing send control must still fail closed as UI drift.');
echo "safe-t-appeal-send-control-test: OK\n";
