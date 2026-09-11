<?php
declare(strict_types=1);
$src=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/CaseRepository.php');
function cspAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach([':q_order',':q_safe_t',':q_sku',':q_asin'] as $placeholder){
    cspAssert(str_contains($src,$placeholder),'text search must use unique native-PDO placeholder '.$placeholder);
}
cspAssert(!str_contains($src,'LIKE :q OR'),'text search must not reuse one named placeholder with native MySQL prepares');
cspAssert(str_contains($src,"'case_ids'"),'Repository search must accept internal resolved case IDs.');
cspAssert(str_contains($src,':case_id_'),'Resolved case IDs must use unique native-PDO placeholders.');
cspAssert(str_contains($src,'$outstanding DESC'),'Equal urgency must prioritize outstanding financial exposure.');
echo "cockpit-search-native-pdo-test: OK\n";
