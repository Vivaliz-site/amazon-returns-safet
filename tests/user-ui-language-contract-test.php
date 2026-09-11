<?php
declare(strict_types=1);

function uiAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/admin/amazon-returns/index.php');
$js = (string) file_get_contents($root . '/admin/amazon-returns/assets/cockpit.js');
$operatorPath = $root . '/admin/amazon-returns/assets/operator-language.js';
$operator = is_file($operatorPath) ? (string) file_get_contents($operatorPath) : '';
$intake = (string) file_get_contents($root . '/admin/amazon-returns/intake.php');

// The operator UI must speak plain Portuguese. Internal enums/codes may remain in payloads,
// hidden values and backend logic, but must never be rendered as labels, errors or explanations.
foreach (['Situação', 'Próxima ação', 'Tipo de logística', 'Recebimento'] as $label) {
    uiAssert(str_contains($page, $label), 'Missing plain-language UI label: ' . $label);
}
foreach (['Qual decisão precisa ser tomada?', 'O que aconteceu', 'O que já foi verificado', 'Mensagens com a Amazon', 'Impacto financeiro e prazo', 'Recomendação', 'O que o sistema aprenderá', 'Casos semelhantes afetados'] as $section) {
    uiAssert(str_contains($js . $operator, $section), 'Review is missing plain-language section: ' . $section);
}
foreach (['function friendlyError(', 'function humanText(', 'function humanReviewSummary(', 'function renderMessageThread('] as $helper) {
    uiAssert(str_contains($js, $helper), 'Missing global UI humanization helper: ' . $helper);
}
uiAssert(str_contains($page, 'operator-language.js'), 'Operator language boundary script must be loaded after the cockpit.');
uiAssert(str_contains($operator, 'function recommendationExplanation('), 'Missing recommendation explanation sanitizer.');
uiAssert(str_contains($operator, 'function renderSuggestion('), 'Recommendation rendering must be overridden at the user-facing boundary.');
uiAssert(str_contains($operator, "humanText(suggestion?.rationale||'')"), 'AI rationale must pass through the global humanizer before rendering.');
uiAssert(str_contains($js, "text('span',friendlyError(message))"), 'All global UI errors must pass through friendlyError before rendering.');

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
