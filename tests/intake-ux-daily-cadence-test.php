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
foreach(['gmail','gmail_refund_reconciliation','seller_central','financial','sp_api','returns_report','scheduler'] as $task){
    intakeUxAssert(
        preg_match("/'".preg_quote($task,'/')."'\\s*=>\\s*43200/",$runtime)===1,
        $task.' routine must run twice per day, not every five minutes.'
    );
}
intakeUxAssert(preg_match("/'scheduler'\\s*=>\\s*300/",$runtime)!==1,'No business scheduler may remain at five-minute cadence.');

$intakePage=intakeUxRead('admin/amazon-returns/intake.php');
intakeUxAssert(str_contains($intakePage,'Número da NF de venda'),'Intake must offer NF as a lookup option.');
intakeUxAssert(str_contains($intakePage,'id="invoice"'),'NF must be in the lookup panel.');
intakeUxAssert(!str_contains($intakePage,'name="sales_invoice_number"'),'NF must not be requested again when confirming receipt.');
intakeUxAssert(str_contains($intakePage,'sales_invoice_number:invoice'),'Lookup request must send the NF alternative.');
intakeUxAssert(str_contains($intakePage,'/admin/amazon-returns/api/intake-lookup.php'),'Intake lookup must use the on-demand lookup endpoint.');
intakeUxAssert(str_contains($intakePage,'Number(c.quantity_refunded||0)>0'),'Known refunded quantity must take precedence in the intake UI.');
intakeUxAssert(str_contains($intakePage,'submitButton.disabled=true'),'Receipt submit must prevent concurrent duplicate clicks.');

$lookup=intakeUxRead('admin/amazon-returns/api/intake-lookup.php');
intakeUxAssert(str_contains($lookup,"\$input['sales_invoice_number']"),'Lookup API must accept NF as an alternative identifier.');
intakeUxAssert(str_contains($lookup,'SvAmazonInvoiceSearch::caseIdsExact'),'Receipt lookup must resolve the exact NF through tenant-scoped invoice evidence.');
intakeUxAssert(str_contains($lookup,'$p->cases->forOrder($orderId)'),'Order lookup must still check the local case store first.');
intakeUxAssert(str_contains($lookup,'->syncOrder($orderId)'),'Order lookup must still query Amazon immediately when absent locally.');

$intakeApi=intakeUxRead('admin/amazon-returns/api/intake.php');
intakeUxAssert(!str_contains($intakeApi,"\$input['sales_invoice_number']"),'Receipt API must not require or accept NF entry from the confirmation form.');
intakeUxAssert(str_contains($intakeApi,'$knownRefundQuantity=(int)$case[\'quantity_refunded\'];'),'Receipt API must preserve safe quantity handling.');
intakeUxAssert(str_contains($intakeApi,"get_class(\$e).': '.\$e->getMessage()"),'Unexpected intake failures must retain useful server-side diagnostics.');
$filesStart=strpos($intakeApi,'function sv_amz_intake_files(): array');
$storeStart=strpos($intakeApi,'function sv_amz_intake_store_photos(');
intakeUxAssert(is_int($filesStart) && is_int($storeStart) && $storeStart>$filesStart,'Intake upload helpers must remain discoverable.');
$filesBody=substr($intakeApi,$filesStart,$storeStart-$filesStart);
intakeUxAssert(str_contains($filesBody,'UPLOAD_ERR_NO_FILE'),'Empty browser file placeholders must be filtered by sv_amz_intake_files before evidence storage starts.');

$report=intakeUxRead('includes/amazon-returns/ReturnsReport.php');
intakeUxAssert(str_contains($report,"'invoice_number'"),'Returns report parser must preserve Amazon invoice number.');
intakeUxAssert(str_contains($report,"'invoice number'"),'Returns report parser must map the Invoice number column.');
$invoiceSearch=intakeUxRead('includes/amazon-returns/InvoiceSearch.php');
intakeUxAssert(str_contains($invoiceSearch,"$.invoice_number"),'Invoice lookup must search Amazon returns-report invoice evidence.');
intakeUxAssert(str_contains($invoiceSearch,'tenant_id=:invoice_tenant_id') && str_contains($invoiceSearch,'amazon_connection_id=:invoice_connection_id'),'NF lookup must remain tenant/connection scoped.');

$memory=intakeUxRead('docs/MEMORIA-DO-PROJETO.md');
intakeUxAssert(str_contains($memory,'duas vezes por dia'),'Project memory must record the approved twelve-hour cadence.');
intakeUxAssert(!str_contains($memory,'deve executar a cada **cinco minutos**'),'Project memory must not retain the superseded five-minute scheduler rule.');

echo "intake-ux-daily-cadence-test: OK\n";
