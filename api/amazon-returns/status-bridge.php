<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap-env.php';
require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';
require_once dirname(__DIR__, 2) . '/includes/amazon-returns/Config.php';
require_once dirname(__DIR__, 2) . '/includes/amazon-returns/EventStore.php';
require_once dirname(__DIR__, 2) . '/includes/amazon-returns/Outbox.php';
require_once dirname(__DIR__, 2) . '/includes/amazon-returns/RemoteBridge.php';
require_once dirname(__DIR__, 2) . '/includes/amazon-returns/SafeTStatus.php';
require_once dirname(__DIR__, 2) . '/includes/amazon-returns/SafeTStatusService.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function sv_amz_status_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sv_amz_status_auth_header(): string
{
    $apacheHeaders = function_exists('apache_request_headers') ? apache_request_headers() : [];
    return SvAmazonReturnsRemoteBridge::resolveAuthorizationHeader($_SERVER, is_array($apacheHeaders) ? $apacheHeaders : []);
}

function sv_amz_status_ensure_jobs(PDO $db, DateTimeImmutable $now): int
{
    $cases = $db->query(
        "SELECT id,amazon_order_id,amazon_order_item_id,safe_t_id FROM amazon_return_cases "
        . "WHERE closed_at IS NULL AND safe_t_id IS NOT NULL AND safe_t_id<>'' ORDER BY id LIMIT 250"
    )?->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $pending = $db->prepare(
        "SELECT id FROM amazon_return_outbox WHERE case_id=:case_id AND kind='SAFE_T_READ' "
        . "AND status IN ('PENDING','PROCESSING') LIMIT 1"
    );
    $ensured = 0;
    foreach ($cases as $case) {
        $caseId = (int)($case['id'] ?? 0);
        $safeTId = trim((string)($case['safe_t_id'] ?? ''));
        if ($caseId < 1 || $safeTId === '') continue;
        $pending->execute([':case_id'=>$caseId]);
        if ($pending->fetchColumn() !== false) continue;
        $key = SvAmazonSafeTStatusService::readKey($caseId, $safeTId, $now);
        SvAmazonReturnsOutbox::enqueue($db, 'SAFE_T_READ', $caseId, [
            'case_id'=>$caseId,
            'order_id'=>(string)($case['amazon_order_id'] ?? ''),
            'order_item_id'=>(string)($case['amazon_order_item_id'] ?? ''),
            'safe_t_id'=>$safeTId,
            'read_only'=>true,
        ], $key);
        $ensured++;
    }
    return $ensured;
}

$expectedToken = getenv('SELLER_CENTRAL_BRIDGE_TOKEN');
$expectedToken = is_string($expectedToken) ? trim($expectedToken) : '';
if (!SvAmazonReturnsRemoteBridge::authorized($expectedToken, sv_amz_status_auth_header())) {
    header('WWW-Authenticate: Bearer realm="ShopVivaliz SAFE-T Status Bridge"');
    sv_amz_status_reply(['status'=>'UNAUTHORIZED'], 401);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    header('Allow: POST');
    sv_amz_status_reply(['status'=>'METHOD_NOT_ALLOWED'], 405);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 131072) sv_amz_status_reply(['status'=>'PAYLOAD_TOO_LARGE'], 413);
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) sv_amz_status_reply(['status'=>'INVALID_JSON'], 400);
$operation = strtolower(trim((string)($input['operation'] ?? '')));
if (!in_array($operation, ['heartbeat','pull','result'], true)) sv_amz_status_reply(['status'=>'INVALID_OPERATION'], 400);

$config = new SvAmazonReturnsConfig();
$db = sv_pdo();
if (!$db instanceof PDO) sv_amz_status_reply(['status'=>'DB_UNAVAILABLE'], 503);

if ($operation === 'heartbeat') {
    sv_amz_status_reply([
        'status'=>'OK',
        'read_only'=>true,
        'enabled'=>$config->enabled(),
        'mode'=>$config->mode(),
        'server_time'=>gmdate(DATE_ATOM),
    ]);
}

if ($operation === 'pull') {
    try {
        $ensured = sv_amz_status_ensure_jobs($db, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $db->beginTransaction();
        $row = $db->query(
            "SELECT o.*,c.amazon_order_id,c.amazon_order_item_id,c.safe_t_id,c.support_case_id,c.quantity_refunded,c.quantity_received "
            . "FROM amazon_return_outbox o JOIN amazon_return_cases c ON c.id=o.case_id "
            . "WHERE o.kind='SAFE_T_READ' AND ((o.status='PENDING' AND o.available_at<=UTC_TIMESTAMP()) "
            . "OR (o.status='PROCESSING' AND o.locked_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 300 SECOND))) "
            . "ORDER BY o.available_at,o.id LIMIT 1 FOR UPDATE SKIP LOCKED"
        )?->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!is_array($row)) {
            $db->commit();
            sv_amz_status_reply(['status'=>'NO_JOB','ensured'=>$ensured]);
        }
        $u = $db->prepare("UPDATE amazon_return_outbox SET status='PROCESSING',attempt_count=attempt_count+1,locked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id");
        $u->execute([':id'=>(int)$row['id']]);
        $row['attempt_count'] = (int)($row['attempt_count'] ?? 0) + 1;
        $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
        $row['payload'] = is_array($payload) ? $payload : [];
        $job = SvAmazonReturnsRemoteBridge::jobEnvelope($row, $row, []);
        $db->commit();
        sv_amz_status_reply(['status'=>'JOB','job'=>$job,'ensured'=>$ensured]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[amazon-returns-status-pull] ' . $e->getMessage());
        sv_amz_status_reply(['status'=>'SERVER_ERROR'], 500);
    }
}

$jobId = (int)($input['job_id'] ?? 0);
$idempotencyKey = strtolower(trim((string)($input['idempotency_key'] ?? '')));
$resultInput = is_array($input['result'] ?? null) ? $input['result'] : [];
if ($jobId < 1 || preg_match('/^[a-f0-9]{64}$/', $idempotencyKey) !== 1 || $resultInput === []) {
    sv_amz_status_reply(['status'=>'INVALID_RESULT'], 400);
}
try {
    $result = SvAmazonReturnsRemoteBridge::validateResult($resultInput);
} catch (Throwable) {
    sv_amz_status_reply(['status'=>'INVALID_RESULT','reason'=>'RESULT_CONTRACT_REJECTED'], 400);
}

$db->beginTransaction();
try {
    $stmt = $db->prepare(
        "SELECT o.*,c.amazon_order_id,c.safe_t_id,c.state,c.appeal_deadline_at,c.last_denial_fingerprint,c.repeated_denial_count "
        . "FROM amazon_return_outbox o JOIN amazon_return_cases c ON c.id=o.case_id "
        . "WHERE o.id=:id AND o.kind='SAFE_T_READ' LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([':id'=>$jobId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || !hash_equals((string)$row['idempotency_key'], $idempotencyKey)) {
        $db->rollBack();
        sv_amz_status_reply(['status'=>'JOB_NOT_FOUND'], 404);
    }
    if ((string)$row['status'] === 'SUCCEEDED') {
        $db->commit();
        sv_amz_status_reply(['status'=>'ACK','already_completed'=>true]);
    }
    if ((string)$row['status'] !== 'PROCESSING') {
        $db->rollBack();
        sv_amz_status_reply(['status'=>'JOB_NOT_PROCESSING'], 409);
    }

    $status = (string)$result['status'];
    $caseId = (int)$row['case_id'];
    if ($status === 'ACCEPTED' && is_array($result['read'] ?? null)) {
        $read = $result['read'];
        $knownSafeT = trim((string)($row['safe_t_id'] ?? ''));
        if (($read['safe_t_id'] ?? null) !== null && !hash_equals($knownSafeT, (string)$read['safe_t_id'])) {
            throw new RuntimeException('SAFE-T read-back ID does not match case.');
        }
        $snapshotHash = $result['evidence']['snapshot_sha256'] ?? null;
        if (!is_string($snapshotHash) || preg_match('/^[a-f0-9]{64}$/i', $snapshotHash) !== 1) $snapshotHash = null;
        $eventKey = SvAmazonSafeTStatusService::observationKey($caseId, $read, $snapshotHash);
        $exists = $db->prepare('SELECT id FROM amazon_return_events WHERE idempotency_key=:key LIMIT 1');
        $exists->execute([':key'=>$eventKey]);
        $isNew = $exists->fetchColumn() === false;
        if ($isNew) {
            SvAmazonReturnEventStore::append($db, [
                'case_id'=>$caseId,
                'event_type'=>'SAFE_T_STATUS_OBSERVED',
                'source'=>'SELLER_CENTRAL',
                'source_event_id'=>$knownSafeT,
                'idempotency_key'=>$eventKey,
                'occurred_at'=>gmdate('Y-m-d H:i:s'),
                'payload'=>$read,
                'evidence_sha256'=>$snapshotHash,
            ]);
            $nextState = SvAmazonSafeTStatusService::nextState((string)$row['state'], (string)$read['claim_status'], (bool)($read['appeal_denied'] ?? false));
            $newFingerprint = (string)($read['decision_fingerprint'] ?? '');
            $repeatCount = (int)($row['repeated_denial_count'] ?? 0);
            $lastFingerprint = trim((string)($row['last_denial_fingerprint'] ?? ''));
            if ((string)$read['claim_status'] === 'DENIED') {
                $repeatCount = SvAmazonSafeTStatusService::repeatCount($lastFingerprint ?: null, $repeatCount, $newFingerprint ?: null);
                if ($newFingerprint !== '') $lastFingerprint = $newFingerprint;
            }
            $deadline = $read['appeal_deadline_at'] ?? $row['appeal_deadline_at'];
            if ((string)$read['claim_status'] === 'APPROVED') $deadline = null;
            $update = $db->prepare(
                'UPDATE amazon_return_cases SET state=:state,appeal_deadline_at=:deadline,last_denial_fingerprint=:fingerprint,'
                . 'repeated_denial_count=:repeat_count,next_action_at=' . (in_array((string)$read['claim_status'], ['DENIED','INFO_REQUESTED'], true) ? 'UTC_TIMESTAMP()' : 'next_action_at')
                . ',updated_at=UTC_TIMESTAMP() WHERE id=:case_id'
            );
            $update->execute([
                ':state'=>$nextState,
                ':deadline'=>$deadline,
                ':fingerprint'=>$lastFingerprint !== '' ? $lastFingerprint : null,
                ':repeat_count'=>$repeatCount,
                ':case_id'=>$caseId,
            ]);
        }
        $done = $db->prepare("UPDATE amazon_return_outbox SET status='SUCCEEDED',locked_at=NULL,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id");
        $done->execute([':id'=>$jobId]);
        $db->commit();
        sv_amz_status_reply(['status'=>'ACK','job_id'=>$jobId,'claim_status'=>$read['claim_status'],'new_observation'=>$isNew]);
    }

    if (in_array($status, ['AUTH_REQUIRED','HUMAN_CHALLENGE','UI_DRIFT'], true)) {
        $hours = $status === 'UI_DRIFT' ? 6 : 1;
        $retry = $db->prepare("UPDATE amazon_return_outbox SET status='PENDING',attempt_count=GREATEST(attempt_count-1,0),available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$hours} HOUR),locked_at=NULL,last_error=:error,updated_at=UTC_TIMESTAMP() WHERE id=:id");
        $retry->execute([':error'=>$status . ': ' . (string)($result['reason'] ?? ''),':id'=>$jobId]);
        $db->commit();
        sv_amz_status_reply(['status'=>'ACK','job_id'=>$jobId,'result_status'=>$status,'retry_scheduled'=>true]);
    }

    $db->commit();
    $row['payload'] = json_decode((string)($row['payload_json'] ?? '{}'), true) ?: [];
    SvAmazonReturnsOutbox::markFailed($db, $row, $status . ': ' . (string)($result['reason'] ?? 'SAFE_T_READ_FAILED'));
    sv_amz_status_reply(['status'=>'ACK','job_id'=>$jobId,'result_status'=>$status,'completed'=>false]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[amazon-returns-status-result] ' . $e->getMessage());
    sv_amz_status_reply(['status'=>'SERVER_ERROR'], 500);
}
