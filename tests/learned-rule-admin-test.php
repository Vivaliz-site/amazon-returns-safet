<?php
declare(strict_types=1);
function lraAssert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function lraSource(string $f):string{$p=dirname(__DIR__).'/'.$f;if(!is_file($p))throw new RuntimeException('Missing '.$f);return(string)file_get_contents($p);}
$rules=lraSource('admin/amazon-returns/api/rules.php');
lraAssert(str_contains($rules,'AdminAuth.php'),'rules read requires admin auth');
lraAssert(str_contains($rules,'TenantRegistry'),'rules resolve tenant server-side');
lraAssert(str_contains($rules,'TenantPersistence'),'rules use scoped persistence');
lraAssert(str_contains($rules,'learnedRules->list'),'rules list learned memory');
$status=lraSource('admin/amazon-returns/api/rule-status.php');
lraAssert(str_contains($status,'Csrf.php'),'rule mutation requires CSRF');
lraAssert(str_contains($status,'SvAmazonReturnsCsrf::valid'),'rule mutation validates CSRF');
lraAssert(str_contains($status,'expected_version'),'stale rule mutation rejected');
lraAssert(str_contains($status,"'DISABLED'"),'only disable transition exposed');
lraAssert(str_contains($status,'setStatus'),'rule status delegates to repository');
lraAssert(!str_contains($status,'ACTIVE'), 'rule status endpoint must not expose reactivation');
$page=lraSource('admin/amazon-returns/index.php');$js=lraSource('admin/amazon-returns/assets/cockpit.js');
lraAssert(str_contains($page,'Memória'),'memory tab required');
lraAssert(str_contains($js,'loadRules'),'memory list interaction required');
lraAssert(str_contains($js,'disableRule'),'disable interaction required');
lraAssert(!str_contains($js,'innerHTML'),'memory UI must use safe DOM rendering');
echo "learned-rule-admin-test: OK\n";
