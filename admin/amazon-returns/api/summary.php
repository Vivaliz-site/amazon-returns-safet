<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/admin-guard.php';
require_once __DIR__ . '/../../../includes/amazon-returns/Schema.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function sv_amz_summary_reply(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $db = function_exists('sv_pdo') ? sv_pdo() : null;
    if (!$db instanceof PDO) sv_amz_summary_reply(['success'=>false,'error'=>'Banco indisponível.'], 503);
    SvAmazonReturnsSchema::ensure($db);
    $exposure = '(CASE WHEN expected_reimbursement_amount > 0 THEN expected_reimbursement_amount ELSE refund_amount END - reconciled_credit_amount)';
    $sql = "SELECT COUNT(*) total_cases,
        COALESCE(SUM(GREATEST($exposure,0)),0) at_risk,
        COALESCE(SUM(CASE WHEN state IN ('SAFE_T_ELIGIBLE','SAFE_T_READY') THEN GREATEST($exposure,0) ELSE 0 END),0) eligible_now,
        COALESCE(SUM(CASE WHEN state='SAFE_T_SUBMITTED' THEN GREATEST($exposure,0) ELSE 0 END),0) safe_t_submitted,
        COALESCE(SUM(CASE WHEN state='SAFE_T_DENIED' THEN GREATEST($exposure,0) ELSE 0 END),0) denied,
        COALESCE(SUM(CASE WHEN state IN ('APPEAL_REQUIRED','APPEAL_SUBMITTED') THEN GREATEST($exposure,0) ELSE 0 END),0) appeal,
        COALESCE(SUM(CASE WHEN state='SUPPORT_ESCALATION' THEN GREATEST($exposure,0) ELSE 0 END),0) support,
        COALESCE(SUM(CASE WHEN state IN ('SAFE_T_APPROVED','APPEAL_APPROVED','CREDIT_PENDING') THEN GREATEST($exposure,0) ELSE 0 END),0) approved_awaiting_credit,
        COALESCE(SUM(CASE WHEN state='RECOVERED' THEN reconciled_credit_amount ELSE 0 END),0) recovered,
        COALESCE(SUM(CASE WHEN state='CLOSED_LOSS' THEN GREATEST($exposure,0) ELSE 0 END),0) loss,
        SUM(CASE WHEN program='UNKNOWN' THEN 1 WHEN program IN ('STANDARD','FBA_ONSITE','DELIVERY_BY_AMAZON') AND (refund_initiator='UNKNOWN' OR seller_debit_at IS NULL) THEN 1 ELSE 0 END) unclassified,
        SUM(CASE WHEN state IN ('SAFE_T_ELIGIBLE','SAFE_T_READY') AND safe_t_id IS NULL THEN 1 ELSE 0 END) eligible_without_action,
        SUM(CASE WHEN appeal_deadline_at IS NOT NULL AND appeal_deadline_at < UTC_TIMESTAMP() AND state IN ('SAFE_T_DENIED','SAFE_T_INFO_REQUESTED','APPEAL_REQUIRED') THEN 1 ELSE 0 END) expired_without_treatment,
        SUM(CASE WHEN reconciled_credit_amount > 0 AND state NOT IN ('RECOVERED','CREDIT_PENDING') THEN 1 ELSE 0 END) credit_without_reconciliation
        FROM amazon_return_cases";
    $row = $db->query($sql)?->fetch(PDO::FETCH_ASSOC) ?: [];
    $moneyKeys = ['at_risk','eligible_now','safe_t_submitted','denied','appeal','support','approved_awaiting_credit','recovered','loss'];
    $money = [];
    foreach ($moneyKeys as $key) $money[$key] = number_format((float)($row[$key] ?? 0), 2, '.', '');
    $gates = [];
    foreach (['unclassified','eligible_without_action','expired_without_treatment','credit_without_reconciliation'] as $key) $gates[$key] = (int)($row[$key] ?? 0);
    $recent = $db->query("SELECT id, amazon_order_id, amazon_order_item_id, sku, state, physical_status, eligibility_at, safe_t_id, support_case_id, refund_amount, expected_reimbursement_amount, reconciled_credit_amount, updated_at FROM amazon_return_cases ORDER BY updated_at DESC, id DESC LIMIT 50")?->fetchAll(PDO::FETCH_ASSOC) ?: [];
    sv_amz_summary_reply(['success'=>true,'money'=>$money,'health_gates'=>$gates,'total_cases'=>(int)($row['total_cases'] ?? 0),'recent_cases'=>$recent,'checked_at'=>gmdate(DATE_ATOM)]);
} catch (Throwable $e) {
    error_log('[amazon-returns-summary] ' . $e->getMessage());
    sv_amz_summary_reply(['success'=>false,'error'=>'Não foi possível carregar o resumo.'], 500);
}
