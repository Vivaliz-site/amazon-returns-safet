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
intakeUxAssert(str_contains($intakePage,'Number(c.quantity_refunded||0)>0'),'Known refunded quantity must take precedence in the intake UI.');
intakeUxAssert(str_contains($intakePage,'Math.max(1,Number(c.quantity_ordered||0))'),'Ordered quantity must be only the fallback when refund quantity is not known yet.');

$lookup=intakeUxRead('admin/amazon-returns/api/intake-lookup.php');
intakeUxAssert(str_contains($lookup,'$p->cases->forOrder($orderId)'),'Lookup must check the local case store first.');
intakeUxAssert(str_contains($lookup,'new SvAmazonReturnsSpApi'),'Lookup must use the existing SP-API facade on demand.');
intakeUxAssert(str_contains($lookup,'->syncOrder($orderId)'),'Lookup must query the exact Amazon order when it is absent locally.');
intakeUxAssert(str_contains($lookup,'SvAmazonSpApiEventSink::persist'),'Lookup must persist the discovered order before returning results.');

$intakeApi=intakeUxRead('admin/amazon-returns/api/intake.php');
intakeUxAssert(str_contains($intakeApi,'$input[\'sales_invoice_number\']'),'Receipt API must accept the sales invoice number.');
intakeUxAssert(str_contains($intakeApi,"'sales_invoice_number'=>\$salesInvoiceNumber"),'Receipt event must persist the sales invoice number.');
intakeUxAssert(str_contains($intakeApi,'$knownRefundQuantity=(int)$case[\'quantity_refunded\'];'),'Receipt API must distinguish a known refund quantity from a not-yet-projected refund.');
intakeUxAssert(str_contains($intakeApi,'$expectedQuantity=$knownRefundQuantity>0 ? $knownRefundQuantity : max(1,(int)$case[\'quantity_ordered\']);'),'Receipt API must prefer known refunded quantity and only fall back to ordered quantity.');

$caseRepository=intakeUxRead('includes/amazon-returns/CaseRepository.php');
intakeUxAssert(str_contains($caseRepository,'sales_invoice_number'),'Case search must include the sales invoice number recorded at intake.');
intakeUxAssert(str_contains($caseRepository,'JSON_EXTRACT'),'NF search must read the scoped physical-receipt event payload without exposing internal event details.');
intakeUxAssert(str_contains($caseRepository,':q_invoice'),'NF search must use a bound search parameter.');

$index=intakeUxRead('admin/amazon-returns/index.php');
intakeUxAssert(str_contains($index,'Pedido, NF, SAFE-T ou SKU'),'Cockpit search must tell the user that NF is searchable.');
intakeUxAssert(str_contains($index,'/admin/amazon-returns/assets/ux-polish.js'),'Cockpit must load the plain-language/collapsible-history polish.');
$ux=intakeUxRead('admin/amazon-returns/assets/ux-polish.js');
intakeUxAssert(str_contains($ux,"document.createElement('details')"),'Case timeline must be collapsible.');
intakeUxAssert(str_contains($ux,'Ver histórico'),'Timeline must have a clear collapsed summary.');
intakeUxAssert(str_contains($ux,"['O que aconteceu','O que já foi verificado','Por que preciso da sua decisão?','Mensagens trocadas']"),'Review must prioritize facts before asking the user to decide.');

$memory=intakeUxRead('docs/MEMORIA-DO-PROJETO.md');
intakeUxAssert(str_contains($memory,'consultas rotineiras de negócio') && str_contains($memory,'uma vez por dia'),'Project memory must record the latest daily business-consultation rule.');

echo "intake-ux-daily-cadence-test: OK\n";
