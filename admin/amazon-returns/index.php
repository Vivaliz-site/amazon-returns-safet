<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/AdminAuth.php';
require_once __DIR__.'/../../includes/Csrf.php';
SvAmazonReturnsAdminAuth::requireLogin(false);
$reviewAiCsrf=SvAmazonReturnsCsrf::token('review-ai');
$reviewPreviewCsrf=SvAmazonReturnsCsrf::token('review-preview');
$reviewDecisionCsrf=SvAmazonReturnsCsrf::token('review-decision');
$ruleStatusCsrf=SvAmazonReturnsCsrf::token('rule-status');
?>
<!doctype html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Amazon Returns & SAFE-T</title>
<link rel="stylesheet" href="/admin/amazon-returns/assets/cockpit.css">
</head><body><main class="wrap">
<div class="top"><div><h1>Amazon Returns & SAFE-T</h1><div class="muted">Devoluções, prazos, SAFE-T, recursos, suporte e recuperação financeira.</div></div><a class="btn" href="/admin/amazon-returns/intake.php">Registrar devolução recebida</a></div>
<section class="cards" id="money" aria-label="Resumo financeiro"></section>
<section class="panel"><h2>Gates de saúde</h2><div class="gates" id="gates"></div><p class="muted">Casos sem classificação · Casos elegíveis sem ação · Prazo vencido sem tratamento · Crédito sem conciliação. Meta operacional: todos os indicadores em zero.</p></section>
<section class="panel cockpit">
<nav class="cockpit-tabs" aria-label="Painel SAFE-T"><button type="button" data-view="cases" aria-selected="true">Casos</button><button type="button" data-view="reviews" aria-selected="false">Revisões <span id="review-count" class="review-count">0</span></button><button type="button" data-view="rules" aria-selected="false">Memória</button></nav>
<button type="button" id="review-alert" class="review-alert hidden" aria-live="polite"></button>
<div id="cockpit-error" class="error" role="alert"></div>
<form id="filters" class="filters" onsubmit="return false">
<label>Pesquisar<input id="search" data-filter="q" type="search" placeholder="Pedido, SAFE-T ou SKU"></label>
<label>Estado<select data-filter="state"><option value="">Todos</option><option value="SAFE_T_DENIED">SAFE-T negado</option><option value="APPEAL_REQUIRED">Recurso necessário</option><option value="APPEAL_SUBMITTED">Recurso enviado</option><option value="CREDIT_PENDING">Crédito pendente</option><option value="RECOVERED">Ressarcido</option></select></label>
<label>Ação<select data-filter="action"><option value="">Todas</option><option value="SAFE_T_SUBMIT">Solicitar ressarcimento SAFE-T</option><option value="SAFE_T_APPEAL">Recorrer no SAFE-T</option><option value="SAFE_T_EMAIL_REVIEW">Revisar e-mail do SAFE-T</option><option value="SELLER_SUPPORT_OPEN">Abrir chamado no Suporte ao Vendedor</option><option value="WAIT">Aguardar</option><option value="HUMAN_REVIEW">Revisão humana</option></select></label>
<label>Programa<select data-filter="program"><option value="">Todos</option><option value="STANDARD">Padrão</option><option value="FBA_ONSITE">FBA no local</option><option value="DELIVERY_BY_AMAZON">Entrega pela Amazon</option></select></label>
<label>Físico<select data-filter="physical_status"><option value="">Todos</option><option value="NOT_RECEIVED">Não recebido</option><option value="RECEIVED_OK">Recebido sem divergência</option><option value="RECEIVED_DISCREPANT">Recebido com divergência</option></select></label>
<label>Prazo<select data-filter="deadline"><option value="">Todos</option><option value="overdue">Vencido</option><option value="today">Hoje</option><option value="7d">Próximos 7 dias</option></select></label>
</form>
<div class="workspace"><section><div class="result-head"><h2 id="result-count">Casos</h2><span class="muted">Clique em Abrir para ver a linha do tempo completa.</span></div><section id="case-list" class="case-list" aria-live="polite"></section><div id="pager" class="pager"></div></section><aside id="case-detail" class="case-detail" aria-label="Detalhes do caso"><h2>Detalhes do caso</h2><p class="muted">Selecione um caso para visualizar SAFE-T, valores, prazos, escritas, respostas, evidências e regras.</p></aside></div>
<section id="review-panel" class="review-panel hidden" aria-label="Revisão humana SAFE-T">
<input type="hidden" id="review-ai-csrf" value="<?=htmlspecialchars($reviewAiCsrf,ENT_QUOTES,'UTF-8')?>">
<input type="hidden" id="review-preview-csrf" value="<?=htmlspecialchars($reviewPreviewCsrf,ENT_QUOTES,'UTF-8')?>">
<input type="hidden" id="review-decision-csrf" value="<?=htmlspecialchars($reviewDecisionCsrf,ENT_QUOTES,'UTF-8')?>">
<input type="hidden" id="rule-status-csrf" value="<?=htmlspecialchars($ruleStatusCsrf,ENT_QUOTES,'UTF-8')?>">
<div id="review-meta"></div><button type="button" id="review-suggest">Sugerir com IA</button><div id="review-suggestion" aria-live="polite"></div>
<label>Ação final<select id="review-final-action"><option value="CHECK_FINANCES">Verificar financeiro</option><option value="SAFE_T_APPEAL">Recorrer no SAFE-T</option><option value="SAFE_T_EMAIL_REVIEW">Revisar e-mail do SAFE-T</option><option value="SAFE_T_EMAIL_REPLY">Responder e-mail do SAFE-T</option><option value="SELLER_SUPPORT_OPEN">Abrir chamado no Suporte ao Vendedor</option><option value="SELLER_SUPPORT_UPDATE">Atualizar chamado no Suporte ao Vendedor</option><option value="WAIT">Aguardar</option><option value="CLOSE_LOSS">Encerrar como perda</option></select></label>
<label>Vínculo de data<select id="review-date-binding"><option value="NONE">Nenhum</option><option value="PROMISED_DATE">Data prometida pela Amazon</option><option value="APPEAL_DEADLINE">Prazo para recurso</option></select></label>
<div class="review-actions"><button type="button" data-review-mode="APPROVED">Aprovar sugestão</button><button type="button" data-review-mode="EDITED_APPROVED">Alterar e aprovar</button><button type="button" data-review-mode="REJECTED">Escolher outra ação</button><button type="button" data-review-mode="WAIT">Aguardar</button><button type="button" data-review-mode="EXCEPTION">Somente este caso</button></div>
<div id="review-impact" role="region" aria-label="Impacto da regra"></div>
<div class="review-submit"><button type="button" id="review-preview">Ver impacto</button><button type="button" id="review-confirm" disabled>Confirmar decisão e memória</button></div>
</section>
</section>
</main><script src="/admin/amazon-returns/assets/cockpit.js" defer></script><script src="/admin/amazon-returns/assets/review-focus.js?v=review-open-1" defer></script></body></html>
