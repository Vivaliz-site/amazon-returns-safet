<?php
declare(strict_types=1);
function x(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);}
$w=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$a=strpos($w,'  async evaluate(expression) {');
$b=$a===false?false:strpos($w,"\n  async navigate",$a);
x($a!==false&&$b!==false,'evaluate helper missing');
$body=substr($w,(int)$a,(int)$b-(int)$a);
x(str_contains($body,'expressionPrefix'),'Runtime exception diagnostics must identify the failing expression family.');
x(str_contains($body,'slice(0, 120)'),'Expression diagnostics must remain bounded.');
x(str_contains($body,"replace(/\\s+/g, ' ')"),'Expression diagnostics must be normalized to one line.');
x(str_contains($body,'stackSummary'),'Runtime exception diagnostics must preserve bounded caller stack context.');
echo "seller-central-cdp-expression-prefix-test: OK\n";
