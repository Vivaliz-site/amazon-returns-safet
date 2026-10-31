<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SellerSupportStatus.php';
function sprExpect(string $expect,string $message):void{
  $got=SvAmazonSellerSupportStatus::resolution(['case_id'=>'22504154261','case_status'=>'RESOLVED','latest_text'=>$message]);
  if($got!==$expect)throw new RuntimeException("Expected {$expect}, got {$got}");
}
sprExpect('SAFE_T_SUBMIT',
 'Para solicitar esse reembolso, é necessário registrar uma nova reivindicação SAFE-T. Se a reivindicação for negada, você pode recorrer uma vez dentro de 5 dias úteis.');
sprExpect('SAFE_T_SUBMIT',
 'Após análise, para solicitar o reembolso é necessário criar uma reivindicação SAFE-T. Se negado, o recurso pode ser protocolado posteriormente.');
sprExpect('SAFE_T_APPEAL',
 'A resolução deve ser recorrida diretamente pela SAFE-T. O Seller Support não pode interferir.');
sprExpect('SAFE_T_APPEAL',
 'Sua reclamação foi negada: apresente recurso SAFE-T diretamente nesta solicitação.');
echo "seller-support-safe-t-directive-priority-test: OK\n";
