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
        preg_match("/'".preg_quote($task,'/')."'\\s*=>\\s*86400/",$runtime)===1,
        $task.' business consultation must run at most once per day.'
    );
}
intakeUxAssert(preg_match("/'health'\\s*=>\\s*900/",$runtime)===1,'Technical health monitoring may remain frequent.');

$intakePage=intakeUxRead('admin/amazon-returns/intake.php');
intakeUxAssert(str_contains($intakePage,'sales_invoice_number'),'Intake must offer a sales invoice number field.');
intakeUxAssert(str_contains($intakePage,'/admin/amazon-returns/api/intake-lookup.php'),'Intake lookup must use the on-demand sync endpoint.');
intakeUxAssert(str_contains($intakePage,'Nenhuma devolução encontrada'),'Intake must retain a clear no-result message.');

$lookup=intakeUxRead('admin/amazon-returns/api/intake-lookup.php');
intakeUxAssert(str_contains($lookup,'$p->cases->forOrder($orderId)'),'Lookup must check the local case store first.');
intakeUxAssert(str_contains($lookup,'new SvAmazonReturnsSpApi'),'Lookup must use the existing SP-API facade on demand.');
intakeUxAssert(str_contains($lookup,'->syncOrder($orderId)'),'Lookup must query the exact Amazon order when it is absent locally.');
intakeUxAssert(str_contains($lookup,'SvAmazonSpApiEventSink::persist'),'Lookup must persist the discovered order before returning results.');

$intakeApi=intakeUxRead('admin/amazon-returns/api/intake.php');
intakeUxAssert(str_contains($intakeApi,'$input[\'sales_invoice_number\']'),'Receipt API must accept the sales invoice number.');
intakeUxAssert(str_contains($intakeApi,"'sales_invoice_number'=>\$salesInvoiceNumber"),'Receipt event must persist the sales invoice number.');
intakeUxAssert(str_contains($intakeApi,'max((int)$case[\'quantity_ordered\'],(int)$case[\'quantity_refunded\'])'),'Physical receipt quantity must not depend only on refund projection.');

$cockpit=intakeUxRead('admin/amazon-returns/assets/cockpit.js');
intakeUxAssert(str_contains($cockpit,"document.createElement('details')"),'Case timeline must be collapsible.');
intakeUxAssert(str_contains($cockpit,"text('summary','Ver histórico"),'Timeline must have a clear collapsed summary.');
$happened=strpos($cockpit,"reviewSection('O que aconteceu'");
$verified=strpos($cockpit,"reviewSection('O que já foi verificado'");
$why=strpos($cockpit,"reviewSection('Por que preciso da sua decisão?'");
intakeUxAssert($happened!==false && $verified!==false && $why!==false,'Review must expose plain-language decision blocks.');
intakeUxAssert($happened<$verified && $verified<$why,'Review blocks must prioritize facts before asking the user to decide.');

$memory=intakeUxRead('docs/MEMORIA-DO-PROJETO.md');
intakeUxAssert(str_contains($memory,'consultas rotineiras de negócio') && str_contains($memory,'uma vez por dia'),'Project memory must record the latest daily business-consultation rule.');

echo "intake-ux-daily-cadence-test: OK\n";
