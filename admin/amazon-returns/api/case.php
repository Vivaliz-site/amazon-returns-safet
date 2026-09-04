<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/admin-guard.php';
require_once __DIR__ . '/../../../includes/amazon-returns/Schema.php';
require_once __DIR__ . '/../../../includes/amazon-returns/EventStore.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
function sv_amz_case_reply(array $payload, int $status=200): never { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }

try {
    $db = function_exists('sv_pdo') ? sv_pdo() : null;
    if (!$db instanceof PDO) sv_amz_case_reply(['success'=>false,'error'=>'Banco indisponível.'],503);
    SvAmazonReturnsSchema::ensure($db);
    $caseId = filter_input(INPUT_GET, 'case_id', FILTER_VALIDATE_INT) ?: 0;
    $orderId = trim((string)($_GET['order_id'] ?? ''));
    if ($caseId > 0) {
        $stmt=$db->prepare('SELECT * FROM amazon_return_cases WHERE id=:id LIMIT 1'); $stmt->execute([':id'=>$caseId]); $case=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($case)) sv_amz_case_reply(['success'=>false,'error'=>'Caso não encontrado.'],404);
        $events=SvAmazonReturnEventStore::eventsForCase($db,$caseId);
        sv_amz_case_reply(['success'=>true,'case'=>$case,'events'=>$events]);
    }
    if (preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/',$orderId)!==1) sv_amz_case_reply(['success'=>false,'error'=>'Informe um pedido Amazon válido.'],422);
    $stmt=$db->prepare('SELECT id,amazon_order_id,amazon_order_item_id,sku,asin,quantity_ordered,quantity_refunded,quantity_received,physical_status,state,safe_t_id FROM amazon_return_cases WHERE amazon_order_id=:order_id ORDER BY id');
    $stmt->execute([':order_id'=>$orderId]);
    sv_amz_case_reply(['success'=>true,'cases'=>$stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
} catch (Throwable $e) {
    error_log('[amazon-returns-case] '.$e->getMessage());
    sv_amz_case_reply(['success'=>false,'error'=>'Não foi possível consultar o caso.'],500);
}
