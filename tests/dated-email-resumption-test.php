<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTEmailReview.php';
require_once __DIR__.'/../includes/amazon-returns/FinancialRevalidation.php';
$case=['id'=>77,'amazon_order_id'=>'702-1111111-2222222','safe_t_id'=>'11111-22222-3333333','state'=>'EMAIL_REVIEW_RESPONSE_PENDING','physical_status'=>'NOT_RECEIVED','expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00'];
$wait=['id'=>101,'case_id'=>77,'event_type'=>'SAFE_T_EMAIL_REVIEW_RESPONSE','source'=>'GMAIL','occurred_at'=>'2026-09-01 12:00:00','payload'=>['review_outcome'=>'WAIT','review_suggested_action'=>'WAIT','review_excerpt'=>'Aguarde ate 10/09/2026.','review_next_action_at'=>'2026-09-10 03:00:00','gmail_thread_id'=>'original-thread','gmail_rfc_message_id'=>'<amazon-message@amazon.com>']];
$source=SvAmazonFinancialRevalidation::sourceEvent(77,true,'2026-09-10 11:55:00');$source['id']=102;
$confirmation=SvAmazonFinancialRevalidation::confirmation($case,[$source],['credit_amount'=>'0.00','outstanding_amount'=>'100.00'],'2026-09-10 11:56:00');
$events=[$wait,$source,$confirmation];$errors=[];
try{$mail=SvAmazonSafeTEmailReview::composeReply($case,$events,new DateTimeImmutable('2026-09-10T12:00:00Z'));if($mail['thread_id']!=='original-thread')$errors[]='Resumption must stay in original thread';if($mail['in_reply_to']!=='<amazon-message@amazon.com>')$errors[]='Reply correlation must persist';}catch(Throwable $e){$errors[]='Due-date resumption must compose after verified finance: '.$e->getMessage();}
foreach(['2026-09-09T12:00:00Z','2026-09-11T12:00:00Z'] as $at){$rejected=false;try{SvAmazonSafeTEmailReview::composeReply($case,$events,new DateTimeImmutable($at));}catch(LogicException){$rejected=true;}if(!$rejected)$errors[]='Not due or stale finance must not permit a reply at '.$at;}
$paid=$case;$paid['reconciled_credit_amount']='100.00';$rejected=false;try{SvAmazonSafeTEmailReview::composeReply($paid,$events,new DateTimeImmutable('2026-09-10T12:00:00Z'));}catch(LogicException){$rejected=true;}if(!$rejected)$errors[]='Paid case must not reopen by email';
$initial=$case;$initial['state']='SAFE_T_DENIED';$initial['latest_denial_text']='Aguarde ate 10/09/2026.';
$review=SvAmazonSafeTEmailReview::compose($initial,[]);
if(str_contains($review['body'],'O recurso no fluxo SAFE-T j'.mb_chr(225).' foi analisado e negado'))$errors[]='Email cannot claim that an appeal was denied without that evidence';
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "dated-email-resumption-test: OK\n";
