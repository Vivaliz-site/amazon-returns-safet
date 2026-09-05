<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/AmazonRequestedWait.php';
require_once __DIR__.'/../includes/amazon-returns/GmailParser.php';
require_once __DIR__.'/../includes/amazon-returns/GmailEventSink.php';
$errors=[];function aweSame(mixed $want,mixed $got,string $why):void{global $errors;if($want!==$got)$errors[]=$why.' expected='.json_encode($want).' actual='.json_encode($got);}
foreach(['Aguarde ate 10/09/2026.','Aguarde ate 2026-09-10.','Voce sera reembolsado proativamente ate 10 de setembro de 2026.'] as $text){aweSame('2026-09-10 03:00:00',SvAmazonRequestedWait::parse($text)['next_action_at']??null,'explicit date formats');}
aweSame('2026-09-11 03:00:00',SvAmazonRequestedWait::parse('Retorne apos 10/09/2026.')['next_action_at']??null,'after a date is not before its end');
aweSame(null,SvAmazonRequestedWait::parse('Aguarde ate 31/02/2026.')['next_action_at'],'invalid calendar date cannot roll into March');
aweSame(null,SvAmazonRequestedWait::parse('Aguarde ate 10/09/2026. Retorne em 20/09/2026.')['next_action_at'],'conflicting requested dates require review');
aweSame(null,SvAmazonRequestedWait::parse('O reembolso foi emitido em 01/09/2026.'),'a historical refund date is not a wait instruction');
aweSame(null,SvAmazonRequestedWait::parse("Obrigado.\nOn Tuesday wrote:\nAguarde ate 10/09/2026."),'quoted prior correspondence cannot create a wait');
aweSame('2026-09-06 12:00:00',SvAmazonRequestedWait::parse('Aguarde 5 dias.','2026-09-01 12:00:00')['next_action_at']??null,'relative wait anchored to message, never each poll');
aweSame(null,SvAmazonRequestedWait::parse('Aguarde 5 dias uteis.','2026-09-01 12:00:00')['next_action_at'],'business-day calendar is not guessed');
aweSame(null,SvAmazonRequestedWait::timestamp('2026-02-31 03:00:00'),'invalid structured date must be rejected');
$message=['id'=>'wait-message','from'=>'Safe-T Review <Safe-T-Review@amazon.com>','subject'=>'Revisao detalhada SAFE-T 11111-22222-3333333 / 702-1111111-2222222','body_text'=>'Aguarde ate 10 de setembro de 2026.','received_at'=>'2026-09-01 12:00:00','thread_id'=>'existing-thread','rfc_message_id'=>'<existing-message@amazon.com>'];
$event=(new SvAmazonGmailParser())->parse($message)[0];
aweSame('WAIT',$event['review_outcome'],'dated reply remains WAIT until due');
aweSame('2026-09-10 03:00:00',$event['review_next_action_at']??null,'parser preserves full-body requested date');
$patch=SvAmazonGmailEventSink::casePatch($event);aweSame('2026-09-10 03:00:00',$patch['next_action_at']??null,'case exposes exact resumption date');
$method=new ReflectionMethod(SvAmazonGmailEventSink::class,'payload');$payload=$method->invoke(null,$event,'702-1111111-2222222');
aweSame('2026-09-10 03:00:00',$payload['review_next_action_at']??null,'immutable event preserves requested date');
$message['body_text']='Envie comprovante e fotos. Aguarde ate 10/09/2026.';$event=(new SvAmazonGmailParser())->parse($message)[0];
aweSame('INFO_REQUESTED',$event['review_outcome'],'new evidence request must not be silently deferred');
aweSame('2026-09-08 12:00:00',SvAmazonRequestedWait::parse('Aguarde 5 dias apos o debito.','2026-09-04 12:00:00',['refund_at'=>'2026-09-01 12:00:00','seller_debit_at'=>'2026-09-03 12:00:00'])['next_action_at']??null,'explicit debit-relative wait uses debit, not earlier refund');
foreach(['Aguarde nossa resposta. Seu reembolso foi processado em 01/09/2026.','Aguarde nossa resposta, seu reembolso foi processado em 01/09/2026.','Aguarde nossa resposta; o ressarcimento foi emitido em 01/09/2026.'] as $body){aweSame(null,SvAmazonRequestedWait::parse($body)['next_action_at']??null,'historical refund sentence cannot become requested resumption date');}
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "amazon-wait-evidence-test: OK\n";
