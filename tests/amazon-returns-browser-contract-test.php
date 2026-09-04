<?php

declare(strict_types=1);

require_once __DIR__ . '/../workers/amazon-returns/seller-central-worker.php';

function bcSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
}
function bcAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

function runNodeJsonScript(string $relativeScript, array $input, array $env = []): array {
    $script = realpath(__DIR__ . '/../' . ltrim($relativeScript, '/'));
    if ($script === false) throw new RuntimeException('Node script missing: ' . $relativeScript);
    $processEnv = array_merge(getenv() ?: [], $env);
    $proc = proc_open(['node', $script], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, null, $processEnv);
    if (!is_resource($proc)) throw new RuntimeException('Could not start Node script.');
    fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR)); fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $rc = proc_close($proc);
    if ($rc !== 0) throw new RuntimeException('Node script failed: ' . $stderr);
    $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new RuntimeException('Node script output invalid.');
    return $decoded;
}
function runNodeAdapter(array $input, array $env = []): array {
    return runNodeJsonScript('scripts/amazon-returns/seller-central-adapter.mjs', $input, $env);
}

$deniedPage = <<<'TXT'
ID do pedido: 702-9582024-4340203
Data da reivindicação
seg., ago. 31, 2026, 10:00 PM
Status da reivindicação
Negado
Data de negação
ter., set. 01, 2026, 01:23 PM
Recorrer por: ter., set. 08, 2026, 01:23 PM
ID da reivindicação SAFE-T: 98143-99485-9285859
Comentário da Amazon: A devolução foi considerada concluída e a solicitação não é elegível.
TXT;
$parsedDenied = runNodeJsonScript('scripts/amazon-returns/safe-t-status-parser.mjs', [
    'body_text'=>$deniedPage,
    'expected'=>['safe_t_id'=>'98143-99485-9285859','order_id'=>'702-9582024-4340203'],
]);
bcSame('DENIED', $parsedDenied['claim_status'], 'Seller Central text “Negado” must map to DENIED.');
bcSame('2026-09-01T13:23:00-03:00', $parsedDenied['denied_at'], 'Denial timestamp must be captured from Seller Central.');
bcSame('2026-09-08T13:23:00-03:00', $parsedDenied['appeal_deadline_at'], '“Recorrer por” deadline must be captured exactly.');
$parsedEnglishDeadline = runNodeJsonScript('scripts/amazon-returns/safe-t-status-parser.mjs', [
    'body_text'=>"Negado\nRecorrer por\nTue, Sep 8, 2026, 02:29 PM",
]);
bcSame('2026-09-08T14:29:00-03:00', $parsedEnglishDeadline['appeal_deadline_at'], 'English Seller Central deadline must be captured on pt-BR pages.');
bcAssert(str_contains((string)$parsedDenied['decision_text'], 'devolução foi considerada concluída'), 'Amazon denial text must be retained for adapted appeal.');
bcAssert(preg_match('/^[a-f0-9]{64}$/', (string)$parsedDenied['decision_fingerprint']) === 1, 'Denial must get deterministic fingerprint.');
$realConversation = <<<'TXT'
Detalhes da reivindicação: 12472-25597-6629839
Negado
ID da reivindicação SAFE-T
12472-25597-6629839
Motivo
Não recebi a devolução
Recorrer por
Tue, Sep 8, 2026, 02:29 PM
Aug 10, 2026, 08:28 PM
Caro ,
Essa mensagem é para confirmar que o ID da reivindicação SAFE-T foi registrado.
Motivo da reivindicação SAFE-T – Não recebi a devolução
Sep 1, 2026, 10:19 AM
Olá, ShopVivaLiz,
Analisamos seu recurso da decisão de reivindicação SAFE-T referente ao pedido 702-5349464-0245862. Após a análise do recurso, negamos sua solicitação de reembolso.
Por que isso aconteceu?
Sua reivindicação SAFE-T para este pedido não foi registrada dentro do período elegível.
Entendemos sua posição, mas reafirmamos nossa decisão. Não podemos fornecer mais detalhes, portanto, não responderemos a outras comunicações sobre esta reivindicação.
TXT;
$parsedConversation = runNodeJsonScript('scripts/amazon-returns/safe-t-status-parser.mjs', [
    'body_text'=>$realConversation,
    'expected'=>['safe_t_id'=>'12472-25597-6629839','order_id'=>'702-5349464-0245862'],
]);
bcAssert(str_contains((string)$parsedConversation['decision_text'], 'não foi registrada dentro do período elegível'), 'Latest Amazon decision must be extracted instead of original claim Motivo.');
bcAssert(!str_starts_with((string)$parsedConversation['decision_text'], 'Não recebi a devolução'), 'Original claim Motivo must never be treated as Amazon decision text.');
bcSame(true, $parsedConversation['appeal_submitted'] ?? null, 'Seller appeal in conversation must be detected.');
bcSame(true, $parsedConversation['appeal_denied'] ?? null, 'Amazon denial after seller appeal must be detected.');
bcSame('APPROVED', runNodeJsonScript('scripts/amazon-returns/safe-t-status-parser.mjs', ['body_text'=>"Status da reivindicação\nAprovado"] )['claim_status'], 'Aprovado must map to APPROVED.');
bcSame('APPROVED', runNodeJsonScript('scripts/amazon-returns/safe-t-status-parser.mjs', ['body_text'=>"Concedido"] )['claim_status'], 'Seller Central status Concedido must map to APPROVED.');
bcSame('INFO_REQUESTED', runNodeJsonScript('scripts/amazon-returns/safe-t-status-parser.mjs', ['body_text'=>"Status da reivindicação\nInformações solicitadas"] )['claim_status'], 'Explicit information request must map to INFO_REQUESTED.');
bcSame('UNKNOWN', runNodeJsonScript('scripts/amazon-returns/safe-t-status-parser.mjs', ['body_text'=>"Status da reivindicação\nNovo texto desconhecido"] )['claim_status'], 'Unknown Seller Central status must fail closed.');

$base = [
    'action'=>'SAFE_T_SUBMIT',
    'case'=>['order_id'=>'702-1234567-7654321','safe_t_id'=>null],
    'snapshot'=>['authenticated'=>true,'mfa_required'=>false,'captcha_present'=>false,'ui_contract'=>'safet-v1','existing_claim_id'=>null,'existing_support_case_id'=>null,'eligibility'=>['allowed'=>true]],
];
$dry = runNodeAdapter($base);
bcSame('ACCEPTED', $dry['status'], 'Default adapter evaluation must be accepted in dry-run.');
bcSame(true, $dry['dry_run'], 'Dry-run must default true.');
bcSame(false, $dry['submitted'], 'Dry-run must never submit.');

$mfa = $base; $mfa['snapshot']['mfa_required'] = true;
bcSame('AUTH_REQUIRED', runNodeAdapter($mfa)['status'], 'MFA must pause without bypass.');
$captcha = $base; $captcha['snapshot']['captcha_present'] = true;
bcSame('HUMAN_CHALLENGE', runNodeAdapter($captcha)['status'], 'CAPTCHA must pause without bypass.');
$drift = $base; $drift['snapshot']['ui_contract'] = 'unknown-v99';
bcSame('UI_DRIFT', runNodeAdapter($drift)['status'], 'Unexpected UI contract must circuit break.');
$existing = $base; $existing['snapshot']['existing_claim_id'] = '12797-64249-3531034';
$existingResult = runNodeAdapter($existing);
bcSame('ALREADY_EXISTS', $existingResult['status'], 'Existing SAFE-T must suppress duplicate write.');
bcSame('12797-64249-3531034', $existingResult['external_id'], 'Existing claim ID must be read back.');
$blocked = $base; $blocked['snapshot']['eligibility'] = ['allowed'=>false,'reason'=>'Aguarde o prazo de reembolso proativo.','next_allowed_at'=>'2026-09-06T00:00:00Z'];
$blockedResult = runNodeAdapter($blocked);
bcSame('BLOCKED_UNTIL', $blockedResult['status'], 'Eligibility block must be persisted instead of spam retry.');
bcSame('2026-09-06T00:00:00Z', $blockedResult['next_allowed_at'], 'Exact next permitted date must survive.');

$support = $base; $support['action']='SELLER_SUPPORT_OPEN'; $support['case']['safe_t_id']='12797-64249-3531034'; $support['snapshot']['existing_support_case_id']='21839000001';
$supportResult = runNodeAdapter($support);
bcSame('ALREADY_EXISTS', $supportResult['status'], 'Existing Help case must suppress duplicate support ticket.');
bcSame('21839000001', $supportResult['external_id'], 'Existing support Case ID must be retained.');

$realWrite = $base; $realWrite['dry_run']=false; $realWrite['write_flags']=['SAFE_T_SUBMIT'=>true];
$realWriteResult = runNodeAdapter($realWrite);
bcSame('FAILED', $realWriteResult['status'], 'Write enabled without a real browser bridge must fail closed.');
bcSame(false, $realWriteResult['retry_safe'], 'Unknown external write state must not blind-retry.');

$enabled = $base; $enabled['dry_run']=false; $enabled['write_flags']=['SAFE_T_SUBMIT'=>false];
$disabledResult = runNodeAdapter($enabled);
bcSame('ACCEPTED', $disabledResult['status'], 'Disabled write flag remains safe evaluation.');
bcSame(false, $disabledResult['submitted'], 'Write flag off cannot submit.');
bcSame(true, $disabledResult['dry_run'], 'Write flag off forces effective dry-run.');

$router = tempnam(sys_get_temp_dir(), 'amazon-bridge-test-');
if ($router === false) throw new RuntimeException('Could not create bridge test router.');
file_put_contents($router, <<<'PHP'
<?php
$input=json_decode(file_get_contents('php://input'),true) ?: [];
header('Content-Type: application/json');
if (($input['operation'] ?? '') === 'snapshot') {
    echo json_encode(['status'=>'ACCEPTED','snapshot'=>[
        'authenticated'=>true,'mfa_required'=>false,'captcha_present'=>false,'ui_contract'=>'safet-v1',
        'current_url'=>'https://sellercentral.amazon.com.br/safet-claims/create-v2',
        'existing_claim_id'=>null,'existing_support_case_id'=>null,'eligibility'=>['allowed'=>true],
    ]]);
    return;
}
echo json_encode(['status'=>'ACCEPTED','external_id'=>'99999-11111-2222222','submitted'=>true,'retry_safe'=>true]);
PHP);
$port = 19192;
$bridgeProc = proc_open(['php','-S','127.0.0.1:'.$port,$router], [['pipe','r'],['pipe','w'],['pipe','w']], $bridgePipes);
if (!is_resource($bridgeProc)) throw new RuntimeException('Could not start mock browser bridge.');
usleep(300000);
try {
    $bridgeInput = ['action'=>'SAFE_T_SUBMIT','case'=>['order_id'=>'702-9999999-0000001'],'dry_run'=>false,'write_flags'=>['SAFE_T_SUBMIT'=>true]];
    $bridgeResult = runNodeAdapter($bridgeInput, ['SELLER_CENTRAL_BROWSER_BRIDGE_URL'=>'http://127.0.0.1:'.$port]);
    bcSame('ACCEPTED',$bridgeResult['status'],'Configured bridge must supply its own preflight snapshot.');
    bcSame(true,$bridgeResult['submitted'],'Bridge write must be independently read back as submitted.');
    bcSame('99999-11111-2222222',$bridgeResult['external_id'],'Bridge read-back external ID is mandatory.');
} finally {
    proc_terminate($bridgeProc);
    foreach ($bridgePipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    proc_close($bridgeProc);
    @unlink($router);
}

$worker = new SvAmazonSellerCentralWorker();
$result = $worker->execute(['kind'=>'SAFE_T_SUBMIT','payload'=>['case_id'=>77]], static fn(array $payload): array => ['status'=>'ACCEPTED','submitted'=>false,'dry_run'=>true,'external_id'=>null]);
bcSame('ACCEPTED', $result['status'], 'Worker accepts structured adapter result.');
$thrown = false;
try { $worker->execute(['kind'=>'DELETE_ANYTHING','payload'=>[]], static fn(array $payload): array => []); } catch (InvalidArgumentException) { $thrown = true; }
bcAssert($thrown, 'Worker must reject unapproved operation kinds.');

echo "amazon-returns-browser-contract-test: OK\n";
