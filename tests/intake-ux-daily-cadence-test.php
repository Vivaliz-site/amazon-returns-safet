<?php
declare(strict_types=1);

function intakeUxAssert(bool $condition,string $message): void {
    if(!$condition) throw new RuntimeException($message);
}
function intakeUxRead(string $path): string {
    $full=__DIR__.'/../'.$path;
    intakeUxAssert(is_file($full),'Missing required file: '.$path);
    $content=file_get_contents($full);
    intakeUxAssert(is_string($content),'Unable to read: '.$path);
    return $content;
}

$runtime=intakeUxRead('includes/amazon-returns/Runtime.php');
foreach(['gmail','gmail_refund_reconciliation','seller_central','financial','sp_api','returns_report'] as $task){
    intakeUxAssert(
        preg_match("/'".preg_quote($task,'/')."'\\s*=>\\s*43200/",$runtime)===1,
        $task.' routine external consultation must run twice per day.'
    );
}
intakeUxAssert(preg_match("/'scheduler'\\s*=>\\s*43200/",$runtime)===1,'Routine internal scheduler sweep must run twice per day, not every five minutes.');
intakeUxAssert(preg_match("/'health'\\s*=>\\s*900/",$runtime)===1,'Technical health monitoring may remain frequent.');
intakeUxAssert(str_contains($runtime,'knownActionDue'),'Runtime must expose an event/date-driven due check for known next-action timestamps.');
$daemon=intakeUxRead('workers/amazon-returns/daemon.php');
intakeUxAssert(str_contains($daemon,'SvAmazonReturnsRuntime::knownActionDue'),'Daemon must wake the scheduler for a known due action without restoring periodic five-minute sweeps.');
intakeUxAssert(str_contains($daemon,"unset(\$state['sp_api'],\$state['financial'])"),'When a due decision requires financial revalidation, it must force a fresh external read instead of waiting for the routine 12-hour cadence.');

$intakePage=intakeUxRead('admin/amazon-returns/intake.php');
intakeUxAssert(str_contains($intakePage,'sales_invoice_number'),'Intake must offer a sales invoice number field.');
intakeUxAssert(str_contains($intakePage,'/admin/amazon-returns/api/intake-lookup.php'),'Intake lookup must use the on-demand sync endpoint.');
intakeUxAssert(str_contains($intakePage,'Nenhuma devolução encontrada'),'Intake must retain a clear no-result message.');
intakeUxAssert(str_contains($intakePage,'Number(c.quantity_refunded||0)>0'),'Known refunded quantity must take precedence in the intake UI.');
intakeUxAssert(str_contains($intakePage,'Math.max(1,Number(c.quantity_ordered||0))'),'Ordered quantity must be only the fallback when refund quantity is not known yet.');

$lookup=intakeUxRead('admin/amazon-returns/api/intake-lookup.php');
intakeUxAssert(str_contains($lookup,'$p->cases->forOrder($orderId)'),'Lookup must check the local case store first.');
intakeUxAssert(str_contains($lookup,'new SvAmazonReturnsSpApi'),'Lookup must use the existing SP-API facade on demand.');
intakeUxAssert(str_contains($lookup,'->syncOrder($orderId)'),'Lookup must query the exact Amazon order when it is absent locally.');
intakeUxAssert(str_contains($lookup,'SvAmazonSpApiEventSink::persist'),'Lookup must persist the discovered order before returning results.');
intakeUxAssert(!str_contains($lookup,"'error'=>\$e->getMessage()"),'Lookup must not expose connector/internal exception text to the operator.');

$intakeApi=intakeUxRead('admin/amazon-returns/api/intake.php');
intakeUxAssert(str_contains($intakeApi,'$input[\'sales_invoice_number\']'),'Receipt API must accept the sales invoice number.');
intakeUxAssert(str_contains($intakeApi,"'sales_invoice_number'=>\$salesInvoiceNumber"),'Receipt event must persist the sales invoice number.');
intakeUxAssert(str_contains($intakeApi,'$knownRefundQuantity=(int)$case[\'quantity_refunded\'];'),'Receipt API must distinguish a known refund quantity from a not-yet-projected refund.');
intakeUxAssert(str_contains($intakeApi,'$expectedQuantity=$knownRefundQuantity>0 ? $knownRefundQuantity : max(1,(int)$case[\'quantity_ordered\']);'),'Receipt API must prefer known refunded quantity and only fall back to ordered quantity.');

$casesApi=intakeUxRead('admin/amazon-returns/api/cases.php');
intakeUxAssert(str_contains($casesApi,'SvAmazonInvoiceSearch::caseIds'),'Cockpit case search must delegate NF lookup to the server-scoped helper.');
$invoiceSearch=intakeUxRead('includes/amazon-returns/InvoiceSearch.php');
intakeUxAssert(str_contains($invoiceSearch,'sales_invoice_number'),'Invoice helper must search the number recorded at intake.');
intakeUxAssert(str_contains($invoiceSearch,'JSON_EXTRACT'),'NF search must read the physical-receipt event payload without exposing internal event details.');
intakeUxAssert(str_contains($invoiceSearch,':q_invoice'),'NF search must use a bound search parameter.');
intakeUxAssert(str_contains($invoiceSearch,'tenant_id=:invoice_tenant_id') && str_contains($invoiceSearch,'amazon_connection_id=:invoice_connection_id'),'NF lookup must remain tenant/connection scoped from TenantContext.');

$index=intakeUxRead('admin/amazon-returns/index.php');
intakeUxAssert(str_contains($index,'Pedido, NF, SAFE-T ou SKU'),'Cockpit search must tell the user that NF is searchable.');
intakeUxAssert(str_contains($index,'/admin/amazon-returns/assets/ux-polish.js'),'Cockpit must load the plain-language/collapsible-history polish.');
$ux=intakeUxRead('admin/amazon-returns/assets/ux-polish.js');
intakeUxAssert(str_contains($ux,"document.createElement('details')"),'Case timeline must be collapsible.');
intakeUxAssert(str_contains($ux,'Ver histórico'),'Timeline must have a clear collapsed summary.');
intakeUxAssert(str_contains($ux,"['O que aconteceu','O que já foi verificado','Por que preciso da sua decisão?','Mensagens trocadas']"),'Review must prioritize facts before asking the user to decide.');

$memory=intakeUxRead('docs/MEMORIA-DO-PROJETO.md');
intakeUxAssert(str_contains($memory,'duas vezes ao dia') && str_contains($memory,'12 horas'),'Project memory must record the latest twice-daily business cadence.');
intakeUxAssert(str_contains($memory,'sem depender de um ciclo periódico de cinco minutos'),'Project memory must record that known dates wake the scheduler without a five-minute routine.');
intakeUxAssert(str_contains($memory,'consulta manual') && str_contains($memory,'imediatamente'),'Project memory must preserve immediate on-demand lookup.');
$delivery=intakeUxRead('docs/REGRAS-DE-ENTREGA.md');
intakeUxAssert(str_contains($delivery,'teste funcional de ponta a ponta') && str_contains($delivery,'não pode ser considerada concluída'),'Delivery rules must require real end-to-end functional validation before completion.');

echo "intake-ux-daily-cadence-test: OK\n";