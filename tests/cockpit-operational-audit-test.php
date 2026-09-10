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
coaAssert(str_contains($page,'rastreio'),'Search UI must advertise tracking search.');
coaAssert(str_contains($listApi,'foreach($case[\'customer_tracking_ids\']'),'Tracking search must use projected tracking IDs.');
coaAssert(str_contains($listApi,'SvAmazonInvoiceSearch::caseIds'),'NF search must use invoice evidence.');

// Human responsibility must win over automatic states and generic WAIT must never be the operator status.
coaAssert(str_contains($ui,"review_status==='OPEN'"),'Open review must be shown as user responsibility.');
coaAssert(str_contains($ui,"'Sua decisão é necessária'"),'User-decision state missing.');
coaAssert(!str_contains($ui,"return 'Aguardar';"),'Generic Aguardar status is prohibited.');
coaAssert(str_contains($bootstrap,"filters.set('review_status','OPEN')"),'Attention quick filter must include every open review, including blocked reviews.');

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

// Repeated telemetry is condensed while material events remain visible behind an explicit disclosure.
coaAssert(str_contains($ui,'function condenseTimeline('),'Timeline condensation is required.');
coaAssert(str_contains($ui,"repeatable=title==='Pedido sincronizado'||title==='Movimentação financeira identificada'"),'Only repetitive telemetry should be grouped.');
coaAssert(str_contains($ui,'Ver histórico completo'),'Full history disclosure is required.');

// Initial legacy rendering is replaced once the operational layer is loaded and case-only controls do not leak into other tabs.
coaAssert(str_contains($bootstrap,"if(state.view==='cases')loadCases()"),'Operational list must rerender after deferred scripts load.');
coaAssert(str_contains($bootstrap,'Dados podem estar desatualizados'),'Stale source data must be visibly identified.');
coaAssert(str_contains($bootstrap,'function syncOperationalChrome('),'Operational chrome must follow the selected tab.');
coaAssert(str_contains($bootstrap,"classList.toggle('hidden',state.view!=='cases')"),'Case quick filters must be hidden outside the cases tab.');

// Responsive layout and usable touch targets.
coaAssert(str_contains($css,'@media(max-width:980px)'),'Tablet responsive layout missing.');
coaAssert(str_contains($css,'@media(max-width:600px)'),'Mobile responsive layout missing.');
coaAssert(str_contains($css,'min-height:44px'),'Operational controls require touch-sized targets.');

// User-provided/API content remains text-only; no HTML injection shortcut.
coaAssert(!str_contains($ui,'innerHTML')&&!str_contains($bootstrap,'innerHTML'),'Operational cockpit must not use innerHTML.');

echo "cockpit-operational-audit-test: OK\n";