<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/includes/amazon-returns/CaseReferenceSearch.php';
function irsAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function irsSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why);}

irsSame('RETURN_TRACKING',SvAmazonCaseReferenceSearch::kind('TBR015328001'),'TBR input must use return-reference path.');
$endpoint=(string)file_get_contents($root.'/admin/amazon-returns/api/intake-lookup.php');
$page=(string)file_get_contents($root.'/admin/amazon-returns/intake.php');

irsAssert(str_contains($endpoint,'$input[\'query\']') || str_contains($endpoint,'$input[\'query\'] ??'),'Intake lookup must accept one primary query field.');
irsAssert(str_contains($endpoint,'$input[\'order_id\']') && str_contains($endpoint,'$input[\'sales_invoice_number\']'),'One release must keep legacy lookup inputs compatible.');
irsAssert(str_contains($endpoint,'SvAmazonCaseReferenceSearch::caseIds'),'Intake and cockpit must share local resolver.');
irsAssert(str_contains($endpoint,'SvAmazonGmailReturnReferenceLookup'),'Historical TBR can be resolved on demand.');
irsAssert(str_contains($page,'Localizar devolução'),'Intake must present one lookup question.');
irsAssert(str_contains($page,'Pedido, NF ou TBR') || str_contains($page,'pedido Amazon, NF de venda ou TBR'),'Intake help must advertise order, NF and TBR.');
irsAssert(str_contains($page,'id="intake-preview"'),'Matched return must have a dedicated preview before confirmation.');
irsAssert(str_contains($page,'hidden'),'State-changing form must be hidden until a case is selected.');
irsAssert(str_contains($page,"query:query"),'Lookup request must send the normalized query field.');

echo "intake-reference-search-test: OK\n";
