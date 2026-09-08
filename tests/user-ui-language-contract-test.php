<?php
declare(strict_types=1);

function uiAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/amazon-returns/index.php');
$js = (string) file_get_contents($root . '/admin/amazon-returns/assets/cockpit.js');
$intake = (string) file_get_contents($root . '/admin/amazon-returns/intake.php');

// The operator UI must speak plain Portuguese. Internal enums/codes may remain in payloads,
// hidden values and backend logic, but must never be rendered as labels, errors or explanations.
foreach (['Situação', 'Próxima ação', 'Tipo de logística', 'Recebimento'] as $label) {
    uiAssert(str_contains($page, $label), 'Missing plain-language UI label: ' . $label);
}
foreach (['O que aconteceu', 'Por que preciso da sua decisão?', 'O que já foi verificado', 'Mensagens trocadas', 'Recomendação'] as $section) {
    uiAssert(str_contains($js, $section), 'Review is missing plain-language section: ' . $section);
}
foreach (['function friendlyError(', 'function humanText(', 'function humanReviewSummary(', 'function renderMessageThread('] as $helper) {
    uiAssert(str_contains($js, $helper), 'Missing global UI humanization helper: ' . $helper);
}

$forbiddenPage = [
    '<label>Estado<select',
    '<label>Programa<select',
    '<label>Físico<select',
];
foreach ($forbiddenPage as $needle) {
    uiAssert(!str_contains($page, $needle), 'Technical/ambiguous label still exposed in main UI: ' . $needle);
}

$forbiddenJs = [
    "field('Versão'",
    "field('Estado'",
    "field('Físico'",
    "field('Programa'",
    '`Fatos: ${localizedJson(ctx.facts)}`',
    '`Pendências: ${localizedJson(ctx.unresolved_facts)}`',
    "field('Condições',localizedJson",
    "field('Efeito',localizedJson",
    "field('Resultados',localizedJson",
    '`Família ${String(r.rule_family_key',
    "SP_API:'SP-API'",
    "SP_API_FINANCES:'SP-API Financeiro'",
    'showError(e.message',
    "throw new Error(j.error||'Falha na consulta')",
    "text('p',suggestion.rationale||'—')",
];
foreach ($forbiddenJs as $needle) {
    uiAssert(!str_contains($js, $needle), 'Backend/technical content can still leak to user UI: ' . $needle);
}

uiAssert(!str_contains($intake, '${c.state}'), 'Receiving screen still renders raw backend state.');
uiAssert(str_contains($intake, 'friendlyIntakeError'), 'Receiving screen must sanitize backend errors before showing them.');
uiAssert(str_contains($intake, 'humanIntakeStatus'), 'Receiving screen must translate case status to plain Portuguese.');

echo "user-ui-language-contract-test: OK\n";
