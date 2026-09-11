<?php
declare(strict_types=1);
function coaAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/admin/amazon-returns/index.php');
$api=(string)file_get_contents($root.'/admin/amazon-returns/api/case.php');
$listApi=(string)file_get_contents($root.'/admin/amazon-returns/api/cases.php');
$ui=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit-operational.js');
$bootstrap=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit-operational-bootstrap.js');
$css=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit-operational.css');
$referenceSearch=(string)file_get_contents($root.'/includes/amazon-returns/CaseReferenceSearch.php');

// Same projected/current decision facts drive both list and detail.
foreach(["'current_action'","'current_reason'","'outstanding_amount'"] as $fact){
    coaAssert(str_contains($listApi,$fact),'List API missing operational fact '.$fact);
    coaAssert(str_contains($api,$fact),'Detail API missing operational fact '.$fact);
}
foreach(["'order_at'","'refund_at'","'seller_debit_at'"] as $fact){
    coaAssert(str_contains($listApi,$fact),'List API missing projected fact '.$fact);
}
coaAssert(str_contains($api,'SvAmazonReturnProjector::project('),'Detail API must return the same projected case facts used by the list.');
coaAssert(str_contains($api,"'case'=>\$case"),'Detail API must expose the projected case object.');
coaAssert(str_contains($listApi,'previewAction(')&&str_contains($api,'previewAction('),'Both views must use side-effect-free current decision preview.');

// Search promises only fields the backend actually supports, including projected tracking and invoice evidence.
coaAssert(str_contains($page,'TBR')&&str_contains($page,'rastreio'),'Search UI must advertise return and delivery tracking search.');
coaAssert(str_contains($listApi,'SvAmazonCaseReferenceSearch::caseIds'),'List API must resolve references before pagination.');
coaAssert(str_contains($referenceSearch,'customer_tracking_ids'),'Shared reference resolver must search customer tracking evidence.');
coaAssert(str_contains($referenceSearch,'SvAmazonInvoiceSearch::caseIdsExact'),'Structured NF search must use exact invoice evidence.');

// Human responsibility must win over automatic states and generic WAIT must never be the operator status.
coaAssert(str_contains($ui,"review_status==='OPEN'"),'Open review must be shown as user responsibility.');
coaAssert(str_contains($ui,"'Sua decisão é necessária'"),'User-decision state missing.');
coaAssert(!str_contains($ui,"return 'Aguardar';"),'Generic Aguardar status is prohibited.');
coaAssert(!str_contains($page,'>Aguardar<'),'Standalone Aguardar labels are prohibited in operator-facing controls.');
coaAssert(str_contains($bootstrap,"q.set('review_status','OPEN')"),'Attention quick filter must include every open review, including blocked reviews.');
coaAssert(str_contains($bootstrap,"q.delete('action')"),'Attention quick filter must not retain a narrower action filter.');
coaAssert(!str_contains($bootstrap,"q.set('page','1')"),'Attention quick filter must preserve pagination.');

// A past appeal deadline must not create a false operational failure after an appeal/follow-up was already sent.
foreach(['APPEAL_SUBMITTED','APPEAL_APPROVED','EMAIL_REVIEW_SENT','EMAIL_REVIEW_RESPONSE_PENDING','RECOVERED','CLOSED_LOSS'] as $state){
    coaAssert(str_contains($bootstrap,$state),'Delayed-action guard missing completed state '.$state);
}
coaAssert(str_contains($bootstrap,"if(action==='SAFE_T_APPEAL')due=c?.appeal_deadline_at"),'Appeal alerts must use appeal deadline rather than stale eligibility.');
coaAssert(str_contains($bootstrap,"last_external_write?.kind===action"),'Completed/pending write must suppress false delayed-action alert.');
coaAssert(str_contains($bootstrap,"field.querySelector('.muted')?.textContent==='Prazo para recurso'"),'Handled appeal states must suppress stale appeal deadline display.');

// Missing facts are omitted rather than rendered as meaningless dashes.
coaAssert(str_contains($ui,"value===null||value===undefined||value===''||value==='—'"),'Unavailable detail fields must be omitted.');
coaAssert(str_contains($ui,"if(dates.children.length>1)root.append(dates)"),'Empty date block must be omitted.');
coaAssert(str_contains($api,"'physical_received_at'"),'Detail API must expose the actual warehouse receipt timestamp when known.');
coaAssert(str_contains($ui,'operationalDate(c.physical_received_at)'),'Receipt date must come from the physical receipt event, not case closure time.');
coaAssert(str_contains($ui,'function externalWriteSummary('),'Last automatic action must describe queued, successful, or failed execution truthfully.');
coaAssert(str_contains($ui,"includes(String(c.state||''))?null:(c.next_action_at||c.eligibility_at)"),'Concluded cases must not display an obsolete next action date.');

// Repeated telemetry is condensed while material events remain visible behind an explicit disclosure.
coaAssert(str_contains($ui,'function condenseTimeline('),'Timeline condensation is required.');
coaAssert(str_contains($ui,"repeatable=title==='Pedido sincronizado'||title==='Movimentação financeira identificada'"),'Only repetitive telemetry should be grouped.');
coaAssert(str_contains($ui,'Ver histórico completo'),'Full history disclosure is required.');
coaAssert(str_contains($ui,"source!=='Informação não disponível'"),'Unknown timeline source labels must be omitted instead of displaying a meaningless fallback.');

// Initial legacy rendering is replaced once the operational layer is loaded and case-only controls do not leak into other tabs.
coaAssert(str_contains($bootstrap,"if(state.view==='cases')loadCases()"),'Operational list must rerender after deferred scripts load.');
coaAssert(str_contains($bootstrap,'Dados podem estar desatualizados'),'Stale source data must be visibly identified.');
coaAssert(str_contains($bootstrap,'function syncOperationalChrome('),'Operational chrome must follow the selected tab.');
coaAssert(str_contains($bootstrap,"classList.toggle('hidden',hide)"),'Case quick filters must be hidden outside the cases tab.');

// Responsive layout and usable touch targets.
coaAssert(str_contains($css,'@media(max-width:980px)'),'Tablet responsive layout missing.');
coaAssert(str_contains($css,'@media(max-width:600px)'),'Mobile responsive layout missing.');
coaAssert(str_contains($css,'min-height:44px'),'Operational controls require touch-sized targets.');
coaAssert(str_contains($css,'grid-template-areas:"id open" "status open" "finance open" "responsibility responsibility"'),'Desktop case rows must keep the Abrir control visible inside the narrow list pane.');
coaAssert(str_contains($css,'.operational-case-row .case-open{grid-area:open;align-self:center;justify-self:stretch}'),'Desktop Abrir control must stay touch-sized instead of stretching vertically across the whole case.');
coaAssert(str_contains($css,'.case-flow{display:grid;grid-template-columns:1fr'),'Operational explanation cards must remain readable in the detail pane instead of being squeezed into three narrow columns.');
coaAssert(str_contains($css,'grid-template-areas:"id" "status" "finance" "responsibility" "open"'),'Mobile case rows must collapse to a single readable column.');
coaAssert(str_contains($css,'.result-head{align-items:flex-start;flex-direction:column;gap:4px}'),'Mobile result heading must stack instead of squeezing the case count beside its helper text.');

// User-provided/API content remains text-only; no HTML injection shortcut.
coaAssert(!str_contains($ui,'innerHTML')&&!str_contains($bootstrap,'innerHTML'),'Operational cockpit must not use innerHTML.');

coaAssert(str_contains($ui,'operatorFinancialSummary(c)'),'Financial list copy must come from zero-safe helper.');
coaAssert(str_contains($ui,"const returnTracks=Array.isArray(c.return_tracking_ids)"),'TBR display must stay separate from delivery tracking.');
coaAssert(str_contains($ui,'operatorCompactResponsibility'),'Compact responsibility labels are required.');
coaAssert(!str_contains($ui,"text('span','saldo ainda a recuperar','muted')"),'List must not hard-code outstanding copy for zero balances.');

echo "cockpit-operational-audit-test: OK\n";