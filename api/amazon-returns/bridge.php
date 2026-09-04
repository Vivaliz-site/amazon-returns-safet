<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap-env.php';
require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';
require_once dirname(__DIR__, 2) . '/includes/amazon-returns/Config.php';
require_once dirname(__DIR__, 2) . '/includes/amazon-returns/Enums.php';
require_once dirname(__DIR__, 2) . '/includes/amazon-returns/EventStore.php';
require_once dirname(__DIR__, 2) . '/includes/amazon-returns/RemoteBridge.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function sv_amz_bridge_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sv_amz_bridge_auth_header(): string
{
    $apacheHeaders = function_exists('apache_request_headers') ? apache_request_headers() : [];
    return SvAmazonReturnsRemoteBridge::resolveAuthorizationHeader($_SERVER, is_array($apacheHeaders) ? $apacheHeaders : []);
}

$expectedToken = getenv('SELLER_CENTRAL_BRIDGE_TOKEN');
$expectedToken = is_string($expectedToken) ? trim($expectedToken) : '';
if (!SvAmazonReturnsRemoteBridge::authorized($expectedToken, sv_amz_bridge_auth_header())) {
    header('WWW-Authenticate: Bearer realm="ShopVivaliz Amazon Returns Bridge"');
    sv_amz_bridge_reply(['status'=>'UNAUTHORIZED'], 401);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    header('Allow: POST');
    sv_amz_bridge_reply(['status'=>'METHOD_NOT_ALLOWED'], 405);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 131072) {
    sv_amz_bridge_reply(['status'=>'PAYLOAD_TOO_LARGE'], 413);
}

$raw = (string)file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    sv_amz_bridge_reply(['status'=>'INVALID_JSON'], 400);
}
$operation = strtolower(trim((string)($input['operation'] ?? '')));
if (!in_array($operation, ['heartbeat','pull','result'], true)) {
    sv_amz_bridge_reply(['status'=>'INVALID_OPERATION'], 400);
}

$config = new SvAmazonReturnsConfig();
$db = sv_pdo();
if (!$db instanceof PDO) sv_amz_bridge_reply(['status'=>'DB_UNAVAILABLE'], 503);

if ($operation === 'heartbeat') {
    sv_amz_bridge_reply([
        'status'=>'OK',
        'bridge_mode'=>$config->sellerCentralBridgeMode(),
        'enabled'=>$config->enabled(),
        'mode'=>$config->mode(),
        'write_flags'=>$config->writeFlags(),
        'server_time'=>gmdate(DATE_ATOM),
    ]);
}

$writeFlags = $config->writeFlags();
$enabledKinds = array_keys(array_filter($writeFlags, static fn(bool $v): bool => $v));

if ($operation === 'pull') {
    if ($config->sellerCentralBridgeMode() !== 'polling') {
        sv_amz_bridge_reply(['status'=>'BRIDGE_MODE_MISMATCH'], 409);
    }
    if ($enabledKinds === []) {
        sv_amz_bridge_reply(['status'=>'NO_JOB','reason'=>'ALL_WRITE_FLAGS_OFF']);
    }
    $trusted = ['SAFE_T_SUBMIT','SAFE_T_APPEAL','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'];
    $enabledKinds = array_values(array_intersect($trusted, $enabledKinds));
    if ($enabledKinds === []) sv_amz_bridge_reply(['status'=>'NO_JOB','reason'=>'NO_APPROVED_KIND']);

    $quoted = implode(',', array_map(static fn(string $kind): string => $db->quote($kind), $enabledKinds));
    $db->beginTransaction();
    try {
        $sql = "SELECT o.*, c.amazon_order_id, c.amazon_order_item_id, c.safe_t_id, c.support_case_id, "
            . "c.quantity_refunded, c.quantity_received, c.physical_status, c.state, c.program, "
            . "c.refund_at, c.seller_debit_at, c.eligibility_at, c.appeal_deadline_at "
            . "FROM amazon_return_outbox o JOIN amazon_return_cases c ON c.id=o.case_id "
            . "WHERE o.kind IN ({$quoted}) AND ((o.status='PENDING' AND o.available_at<=UTC_TIMESTAMP()) "
            . "OR (o.status='PROCESSING' AND o.locked_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 300 SECOND))) "
            . "ORDER BY o.available_at,o.id LIMIT 1 FOR UPDATE SKIP LOCKED";
        $row = $db->query($sql)?->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!is_array($row)) {
            $db->commit();
            sv_amz_bridge_reply(['status'=>'NO_JOB']);
        }
        $update = $db->prepare("UPDATE amazon_return_outbox SET status='PROCESSING', attempt_count=attempt_count+1, locked_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=:id");
        $update->execute([':id'=>(int)$row['id']]);
        $row['attempt_count'] = (int)($row['attempt_count'] ?? 0) + 1;
        $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
        $row['payload'] = is_array($payload) ? $payload : [];
        $case = $row;
        $job = SvAmazonReturnsRemoteBridge::jobEnvelope($row, $case, $writeFlags);
        $job['case']['physical_status'] = (string)($row['physical_status'] ?? '');
        $job['case']['state'] = (string)($row['state'] ?? '');
        $job['case']['program'] = (string)($row['program'] ?? '');
        $job['case']['refund_at'] = $row['refund_at'] ?? null;
        $job['case']['seller_debit_at'] = $row['seller_debit_at'] ?? null;
        $job['case']['eligibility_at'] = $row['eligibility_at'] ?? null;
        $job['case']['appeal_deadline_at'] = $row['appeal_deadline_at'] ?? null;
        $db->commit();
        sv_amz_bridge_reply(['status'=>'JOB','job'=>$job]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[amazon-returns-bridge-pull] ' . $e->getMessage());
        sv_amz_bridge_reply(['status'=>'SERVER_ERROR'], 500);
    }
}

$jobId = (int)($input['job_id'] ?? 0);
$idempotencyKey = strtolower(trim((string)($input['idempotency_key'] ?? '')));
$resultInput = is_array($input['result'] ?? null) ? $input['result'] : [];
if ($jobId < 1 || preg_match('/^[a-f0-9]{64}$/', $idempotencyKey) !== 1 || $resultInput === []) {
    sv_amz_bridge_reply(['status'=>'INVALID_RESULT'], 400);
}

try {
    $result = SvAmazonReturnsRemoteBridge::validateResult($resultInput);
} catch (Throwable $e) {
    sv_amz_bridge_reply(['status'=>'INVALID_RESULT','reason'=>'RESULT_CONTRACT_REJECTED'], 400);
}

$db->beginTransaction();
try {
    $stmt = $db->prepare(
        "SELECT o.*, c.safe_t_id, c.support_case_id FROM amazon_return_outbox o "
        . "JOIN amazon_return_cases c ON c.id=o.case_id WHERE o.id=:id LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([':id'=>$jobId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || !hash_equals((string)$row['idempotency_key'], $idempotencyKey)) {
        $db->rollBack();
        sv_amz_bridge_reply(['status'=>'JOB_NOT_FOUND'], 404);
    }
    if ((string)$row['status'] === 'SUCCEEDED') {
        $db->commit();
        sv_amz_bridge_reply(['status'=>'ACK','already_completed'=>true]);
    }
    if ((string)$row['status'] !== 'PROCESSING') {
        $db->rollBack();
        sv_amz_bridge_reply(['status'=>'JOB_NOT_PROCESSING'], 409);
    }

    $kind = strtoupper((string)$row['kind']);
    $caseId = (int)$row['case_id'];
    $status = (string)$result['status'];
    $externalId = $result['external_id'];
    $success = in_array($status, ['ACCEPTED','ALREADY_EXISTS'], true);

    if ($success) {
        $done = $db->prepare("UPDATE amazon_return_outbox SET status='SUCCEEDED',locked_at=NULL,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id");
        $done->execute([':id'=>$jobId]);
        if ($kind === 'SAFE_T_SUBMIT' && $externalId !== null) {
            $u = $db->prepare("UPDATE amazon_return_cases SET safe_t_id=:external_id,state='SAFE_T_SUBMITTED',updated_at=UTC_TIMESTAMP() WHERE id=:case_id");
            $u->execute([':external_id'=>$externalId,':case_id'=>$caseId]);
        } elseif ($kind === 'SAFE_T_APPEAL') {
            $u = $db->prepare("UPDATE amazon_return_cases SET state='APPEAL_SUBMITTED',updated_at=UTC_TIMESTAMP() WHERE id=:case_id");
            $u->execute([':case_id'=>$caseId]);
        } elseif (in_array($kind, ['SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'], true)) {
            $supportId = $externalId ?? ($row['support_case_id'] ?? null);
            $u = $db->prepare("UPDATE amazon_return_cases SET support_case_id=COALESCE(:external_id,support_case_id),state='SUPPORT_ESCALATION',updated_at=UTC_TIMESTAMP() WHERE id=:case_id");
            $u->execute([':external_id'=>$supportId,':case_id'=>$caseId]);
        }
    } elseif ($status === 'BLOCKED_UNTIL') {
        $next = new DateTimeImmutable('+6 hours', new DateTimeZone('UTC'));
        if ($result['next_allowed_at'] !== null) {
            try {
                $candidate = new DateTimeImmutable((string)$result['next_allowed_at']);
                if ($candidate > new DateTimeImmutable('now', new DateTimeZone('UTC'))) $next = $candidate;
            } catch (Throwable) {
                // Keep conservative six-hour retry when Seller Central did not expose a parseable date.
            }
        }
        $u = $db->prepare("UPDATE amazon_return_outbox SET status='PENDING',attempt_count=GREATEST(attempt_count-1,0),available_at=:next_at,locked_at=NULL,last_error=:error,updated_at=UTC_TIMESTAMP() WHERE id=:id");
        $u->execute([
            ':next_at'=>$next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ':error'=>mb_substr((string)($result['block_reason'] ?? $status),0,1900,'UTF-8'),
            ':id'=>$jobId,
        ]);
    } elseif (in_array($status, ['AUTH_REQUIRED','HUMAN_CHALLENGE','UI_DRIFT'], true)) {
        $hours = $status === 'UI_DRIFT' ? 6 : 1;
        $u = $db->prepare("UPDATE amazon_return_outbox SET status='PENDING',attempt_count=GREATEST(attempt_count-1,0),available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$hours} HOUR),locked_at=NULL,last_error=:error,updated_at=UTC_TIMESTAMP() WHERE id=:id");
        $u->execute([':error'=>$status . ': ' . (string)($result['reason'] ?? ''), ':id'=>$jobId]);
    } else {
        $attempts = (int)($row['attempt_count'] ?? 0);
        $retry = ($result['retry_safe'] ?? false) === true && $attempts < 5;
        if ($retry) {
            $delay = min(3600, 60 * (2 ** max(0, $attempts - 1)));
            $u = $db->prepare("UPDATE amazon_return_outbox SET status='PENDING',available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$delay} SECOND),locked_at=NULL,last_error=:error,updated_at=UTC_TIMESTAMP() WHERE id=:id");
            $u->execute([':error'=>$status . ': ' . (string)($result['reason'] ?? ''), ':id'=>$jobId]);
        } else {
            $payloadJson = (string)($row['payload_json'] ?? '{}');
            $dead = $db->prepare("INSERT INTO amazon_return_dead_letters (outbox_id,case_id,kind,idempotency_key,payload_sha256,payload_json,error_class,error_message,attempt_count,first_attempt_at,failed_at,created_at) VALUES (:outbox_id,:case_id,:kind,:idempotency_key,:payload_sha256,:payload_json,'RemoteBridgeFailure',:error_message,:attempt_count,:first_attempt_at,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE error_message=VALUES(error_message),attempt_count=GREATEST(attempt_count,VALUES(attempt_count)),failed_at=UTC_TIMESTAMP()");
            $dead->execute([
                ':outbox_id'=>$jobId,
                ':case_id'=>$caseId,
                ':kind'=>$kind,
                ':idempotency_key'=>$idempotencyKey,
                ':payload_sha256'=>hash('sha256',$payloadJson),
                ':payload_json'=>$payloadJson,
                ':error_message'=>mb_substr($status . ': ' . (string)($result['reason'] ?? ''),0,1900,'UTF-8'),
                ':attempt_count'=>$attempts,
                ':first_attempt_at'=>$row['created_at'] ?? null,
            ]);
            $u = $db->prepare("UPDATE amazon_return_outbox SET status='DEAD_LETTER',locked_at=NULL,last_error=:error,updated_at=UTC_TIMESTAMP() WHERE id=:id");
            $u->execute([':error'=>$status . ': ' . (string)($result['reason'] ?? ''), ':id'=>$jobId]);
        }
    }

    $eventKey = hash('sha256', 'seller-central-result|' . $idempotencyKey . '|' . $status . '|' . (string)($externalId ?? ''));
    $snapshotHash = $result['evidence']['snapshot_sha256'] ?? null;
    if (!is_string($snapshotHash) || preg_match('/^[a-f0-9]{64}$/i', $snapshotHash) !== 1) $snapshotHash = null;
    SvAmazonReturnEventStore::append($db, [
        'case_id'=>$caseId,
        'event_type'=>'SELLER_CENTRAL_ACTION_RESULT',
        'source'=>'SELLER_CENTRAL',
        'source_event_id'=>(string)$jobId,
        'idempotency_key'=>$eventKey,
        'occurred_at'=>gmdate('Y-m-d H:i:s'),
        'payload'=>[
            'action'=>$kind,
            'status'=>$status,
            'submitted'=>$result['submitted'],
            'external_id'=>$externalId,
            'retry_safe'=>$result['retry_safe'],
            'block_reason'=>$result['block_reason'],
            'next_allowed_at'=>$result['next_allowed_at'],
            'reason'=>$result['reason'],
        ],
        'evidence_sha256'=>$snapshotHash,
    ]);

    $db->commit();
    sv_amz_bridge_reply([
        'status'=>'ACK',
        'job_id'=>$jobId,
        'result_status'=>$status,
        'completed'=>$success,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[amazon-returns-bridge-result] ' . $e->getMessage());
    sv_amz_bridge_reply(['status'=>'SERVER_ERROR'], 500);
}
