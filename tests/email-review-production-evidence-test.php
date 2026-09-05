<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ReviewReplyAnalyzer.php';
require_once __DIR__.'/../includes/amazon-returns/Config.php';
$fail=[];function epeSame(mixed $want,mixed $got,string $why):void{global $fail;if($want!==$got)$fail[]=$why.' expected='.json_encode($want).' actual='.json_encode($got);}
$a=new SvAmazonSafeTReviewReplyAnalyzer();
$base=['from'=>'Amazon Seller Support <merch.service05@amazon.com.br>','subject'=>'SAFE-T review'];
foreach([
 'Entramos em contato com nossa equipe interna para obter informa\u{00e7}\u{00f5}es. Entraremos em contato assim que tivermos uma atualiza\u{00e7}\u{00e3}o. Incentivamos voc\u{00ea} a aguardar nossa resposta.',
 'J\u{00e1} abrimos uma solicita\u{00e7}\u{00e3}o junto \u{00e0} equipe respons\u{00e1}vel para revisar o hist\u{00f3}rico log\u{00ed}stico. Voc\u{00ea} receber\u{00e1} uma atualiza\u{00e7}\u{00e3}o por e-mail com o resultado dessa an\u{00e1}lise.',
] as $text){$text=preg_replace_callback('/\\\\u\{([0-9a-f]+)\}/i',fn($m)=>mb_chr(hexdec($m[1])),$text);$r=$a->analyze($base+['body_text'=>$text],[]);epeSame('WAIT',$r['outcome'],'actual internal-review wording must wait');epeSame('WAIT',$r['suggested_action'],'do not duplicate an active internal review');}
foreach(['Safe-T-Review@amazon.com.attacker.invalid','amazon.com <fraud@example.org>','Safe-T <review@amazon.com.br.attacker.invalid>'] as $from){$r=$a->analyze(['from'=>$from,'body_text'=>'Sua solicitacao foi aprovada.'],[]);epeSame('UNKNOWN_AMBIGUOUS',$r['outcome'],'sender must be an exact Amazon mailbox domain');}
$r=$a->analyze($base+['body_text'=>'Resolu\u{00e7}\u{00e3}o do caso. Voc\u{00ea} ficou satisfeito com o suporte prestado?'],[]);
epeSame('HUMAN_REVIEW',$r['suggested_action'],'a support survey is not payment evidence');
$env=['AMAZON_RETURNS_ENABLED'=>'1','AMAZON_RETURNS_MODE'=>'production','AMAZON_RETURNS_EMAIL_REVIEW_WRITE'=>'1','AMAZON_RETURNS_EMAIL_REPLY_WRITE'=>'0'];
$c=new SvAmazonReturnsConfig($env);epeSame(true,$c->externalWriteAllowed('SAFE_T_EMAIL_REVIEW'),'review channel enabled independently');epeSame(false,$c->externalWriteAllowed('SAFE_T_EMAIL_REPLY'),'enabling review must not enable replies');
$env['AMAZON_RETURNS_EMAIL_REVIEW_WRITE']='0';$env['AMAZON_RETURNS_EMAIL_REPLY_WRITE']='1';$c=new SvAmazonReturnsConfig($env);
epeSame(false,$c->externalWriteAllowed('SAFE_T_EMAIL_REVIEW'),'reply flag cannot enable review');epeSame(true,$c->externalWriteAllowed('SAFE_T_EMAIL_REPLY'),'reply has its own explicit gate');
if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}echo "email-review-production-evidence-test: OK\n";
