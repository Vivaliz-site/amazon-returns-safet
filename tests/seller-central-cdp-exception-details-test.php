<?php
declare(strict_types=1);
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
function t(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);}
$start=strpos($worker,'  async evaluate(expression) {');
$end=$start===false?false:strpos($worker,"\n  async navigate",$start);
t($start!==false&&$end!==false,'CDP evaluate helper must remain auditable.');
$body=substr($worker,(int)$start,(int)$end-(int)$start);
t(str_contains($body,'exceptionDetails'),'CDP evaluate must inspect browser exception details.');
t(str_contains($body,'description'),'CDP evaluate must preserve the browser exception description for diagnostics.');
t(str_contains($body,'lineNumber'),'CDP evaluate diagnostics must include the failing line.');
t(str_contains($body,'columnNumber'),'CDP evaluate diagnostics must include the failing column.');
t(!str_contains($body,"throw new Error('browser expression failed')"),'CDP evaluate must not discard the root browser error.');
echo "seller-central-cdp-exception-details-test: OK\n";
