<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/admin-guard.php';
require_once __DIR__ . '/../../../includes/csrf.php';
require_once __DIR__ . '/../../../includes/amazon-returns/Schema.php';
require_once __DIR__ . '/../../../includes/amazon-returns/EventStore.php';
require_once __DIR__ . '/../../../includes/amazon-returns/Projector.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function sv_amz_intake_reply(array $payload, int $status=200): never { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function sv_amz_intake_files(): array {
    if (!isset($_FILES['photos'])) return [];
    $f=$_FILES['photos'];
    if (!is_array($f['name'] ?? null)) return [$f];
    $out=[];
    foreach ($f['name'] as $i=>$name) $out[]=['name'=>$name,'type'=>$f['type'][$i] ?? '','tmp_name'=>$f['tmp_name'][$i] ?? '','error'=>$f['error'][$i] ?? UPLOAD_ERR_NO_FILE,'size'=>$f['size'][$i] ?? 0];
    return $out;
}
function sv_amz_intake_store_photos(int $caseId, array $files): array {
    if ($files===[]) return [];
    if (count($files)>6) throw new InvalidArgumentException('Máximo de 6 fotos por recebimento.');
    $base=trim((string)getenv('AMAZON_RETURN_EVIDENCE_DIR'));
    if ($base==='') throw new RuntimeException('AMAZON_RETURN_EVIDENCE_DIR não configurado.');
    $dir=rtrim($base,'/') . '/case-' . $caseId;
    if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Não foi possível criar diretório protegido de evidências.');
    $finfo=new finfo(FILEINFO_MIME_TYPE); $stored=[];
    $exts=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    foreach ($files as $file) {
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) continue;
        if ((int)$file['error']!==UPLOAD_ERR_OK) throw new InvalidArgumentException('Falha no upload de evidência.');
        $size=(int)($file['size'] ?? 0); if ($size<1 || $size>8*1024*1024) throw new InvalidArgumentException('Cada foto deve ter no máximo 8 MB.');
        $tmp=(string)($file['tmp_name'] ?? ''); if (!is_uploaded_file($tmp)) throw new InvalidArgumentException('Upload de foto inválido.');
        $mime=(string)$finfo->file($tmp); if (!isset($exts[$mime])) throw new InvalidArgumentException('Formato de foto não permitido.');
        $hash=hash_file('sha256',$tmp); if (!is_string($hash)) throw new RuntimeException('Falha ao calcular hash da evidência.');
        $name=bin2hex(random_bytes(16)).'.'.$exts[$mime]; $dest=$dir.'/'.$name;
        if (!move_uploaded_file($tmp,$dest)) throw new RuntimeException('Falha ao armazenar evidência protegida.');
        chmod($dest,0600);
        $stored[]=['path'=>$dest,'storage_ref'=>'case-'.$caseId.'/'.$name,'hash'=>$hash,'mime'=>$mime,'size'=>$size];
    }
    return $stored;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='POST') sv_amz_intake_reply(['success'=>false,'error'=>'Método não permitido.'],405);
$contentType=strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
$input=str_contains($contentType,'application/json') ? (json_decode((string)file_get_contents('php://input'),true) ?: []) : $_POST;
$csrf=$_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
if (!sv_csrf_valid('amazon_returns_intake',$csrf)) sv_amz_intake_reply(['success'=>false,'error'=>'CSRF inválido.'],403);

$condition=strtoupper(trim((string)($input['condition'] ?? '')));
$conditions=['OK','DAMAGED','USED','WRONG_ITEM','INCOMPLETE','EMPTY_PACKAGE'];
if (!in_array($condition,$conditions,true)) sv_amz_intake_reply(['success'=>false,'error'=>'Condição de recebimento inválida.'],422);
$quantity=filter_var($input['quantity_correct'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
if ($quantity===false) sv_amz_intake_reply(['success'=>false,'error'=>'Quantidade do item correto recebida é inválida.'],422);
if (in_array($condition,['OK','DAMAGED','USED'],true) && $quantity<1) sv_amz_intake_reply(['success'=>false,'error'=>'Informe ao menos uma unidade do item correto recebido.'],422);
$files=sv_amz_intake_files();
if ($condition!=='OK' && $files===[]) sv_amz_intake_reply(['success'=>false,'error'=>'Foto é obrigatória quando há divergência.'],422);
$operationId=trim((string)($input['operation_id'] ?? ''));
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',$operationId)!==1) sv_amz_intake_reply(['success'=>false,'error'=>'Identificador da operação inválido.'],422);
$caseId=filter_var($input['case_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if ($caseId===false) sv_amz_intake_reply(['success'=>false,'error'=>'Selecione o item/pedido recebido.'],422);
$note=mb_substr(trim((string)($input['note'] ?? '')),0,2000,'UTF-8');
$db=function_exists('sv_pdo') ? sv_pdo() : null; if (!$db instanceof PDO) sv_amz_intake_reply(['success'=>false,'error'=>'Banco indisponível.'],503);
$stored=[];
try {
    SvAmazonReturnsSchema::ensure($db); $db->beginTransaction();
    $lock=$db->prepare('SELECT * FROM amazon_return_cases WHERE id=:id FOR UPDATE'); $lock->execute([':id'=>$caseId]); $case=$lock->fetch(PDO::FETCH_ASSOC);
    if (!is_array($case)) throw new OutOfBoundsException('Caso não encontrado.');
    $outstanding=max(0,(int)$case['quantity_refunded']-(int)$case['quantity_received']);
    if ($quantity>$outstanding) throw new InvalidArgumentException('Quantidade recebida excede a quantidade ainda pendente.');
    $idempotency=hash('sha256','warehouse-intake|'.$operationId);
    $existing=$db->prepare('SELECT id FROM amazon_return_events WHERE idempotency_key=:key LIMIT 1'); $existing->execute([':key'=>$idempotency]);
    $existingId=$existing->fetchColumn();
    if ($existingId!==false) {
        $projection=SvAmazonReturnProjector::project($db,$caseId); $db->commit();
        sv_amz_intake_reply(['success'=>true,'duplicate'=>true,'event_id'=>(int)$existingId,'case'=>$projection]);
    }
    $stored=sv_amz_intake_store_photos($caseId,$files);
    $photoHashes=array_column($stored,'hash');
    $occurredAt=gmdate('Y-m-d H:i:s');
    $eventId=SvAmazonReturnEventStore::append($db,[
        'case_id'=>$caseId,'event_type'=>'PHYSICAL_RECEIVED','source'=>'WAREHOUSE','source_event_id'=>$operationId,
        'idempotency_key'=>$idempotency,'occurred_at'=>$occurredAt,
        'payload'=>['quantity'=>(int)$quantity,'condition'=>$condition,'note'=>$note,'operator_id'=>(int)($_SESSION['user_id'] ?? 0)],
        'evidence_sha256'=>hash('sha256',implode('|',$photoHashes).'|'.$condition.'|'.$note),
    ]);
    if ($stored!==[]) {
        $evidence=$db->prepare('INSERT IGNORE INTO amazon_return_evidence (case_id,kind,source,external_id,content_sha256,storage_ref,metadata_json,captured_at,created_at) VALUES (:case_id,\'WAREHOUSE_PHOTO\',\'ADMIN_INTAKE\',:external_id,:hash,:storage_ref,:metadata,:captured_at,UTC_TIMESTAMP())');
        foreach ($stored as $photo) $evidence->execute([':case_id'=>$caseId,':external_id'=>$operationId,':hash'=>$photo['hash'],':storage_ref'=>$photo['storage_ref'],':metadata'=>json_encode(['mime'=>$photo['mime'],'size'=>$photo['size']],JSON_THROW_ON_ERROR),':captured_at'=>$occurredAt]);
    }
    $projection=SvAmazonReturnProjector::project($db,$caseId); $db->commit();
    sv_amz_intake_reply(['success'=>true,'duplicate'=>false,'event_id'=>$eventId,'case'=>$projection]);
} catch (InvalidArgumentException|OutOfBoundsException $e) {
    if ($db->inTransaction()) $db->rollBack(); foreach ($stored as $photo) @unlink($photo['path']); sv_amz_intake_reply(['success'=>false,'error'=>$e->getMessage()],422);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack(); foreach ($stored as $photo) @unlink($photo['path']); error_log('[amazon-returns-intake] '.$e->getMessage()); sv_amz_intake_reply(['success'=>false,'error'=>'Não foi possível registrar o recebimento.'],500);
}
