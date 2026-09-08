<?php
declare(strict_types=1);
$file=__DIR__.'/../includes/amazon-returns/ReturnActionRouter.php';
if(!is_file($file)){fwrite(STDERR,"Missing evidence-based return action router\n");exit(1);}require_once $file;
$errors=[];function raEq(mixed $want,mixed $got,string $why):void{global $errors;if($want!==$got)$errors[]=$why.' expected='.json_encode($want).' actual='.json_encode($got);}
$now=new DateTimeImmutable('2026-09-05T15:00:00Z');
$case=['id'=>77,'amazon_order_id'=>'702-1111111-2222222','program'=>'STANDARD','order_at'=>'2026-05-01 00:00:00','refund_at'=>'2026-06-01 12:00:00','seller_debit_at'=>'2026-06-01 12:00:00','physical_status'=>'NOT_RECEIVED','expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00','state'=>'AWAITING_RETURN'];
$policy=['eligible'=>true,'eligibility_at'=>'2026-07-16 12:00:00','eligibility_days'=>45];
function raEvent(string $type,array $payload,int $id=1,string $at='2026-09-01 12:00:00',string $source='SELLER_CENTRAL'):array{return ['id'=>$id,'case_id'=>77,'event_type'=>$type,'source'=>$source,'occurred_at'=>$at,'payload'=>$payload];}
$return=raEvent('RETURN_REPORT_OBSERVED',['return_status'=>'Retornando ao Vendedor'],1,'2026-09-01 12:00:00','SP_API_REPORTS');
raEq(null,SvAmazonReturnActionRouter::decide($case,[$return],$policy,$now),'confirmed return-to-seller delegates normal eligibility');
raEq('HUMAN_REVIEW',SvAmazonReturnActionRouter::decide($case,[],$policy,$now)['action'],'without confirmed Amazon customer refund, unknown transport remains review-only');
foreach(['Perdido no Transporte','Nao foi possivel entregar','Recusado pelo cliente','Avariado pela Transportadora'] as $status){
 $event=$return;$event['payload']['return_status']=$status;
 raEq('CHECK_FINANCES',SvAmazonReturnActionRouter::decide($case,[$event],$policy,$now)['action'],'without confirmed Amazon customer refund, proactive route applies: '.$status);
}
$damage=$case;$damage['physical_status']='RECEIVED_DISCREPANT';
raEq('HUMAN_REVIEW',SvAmazonReturnActionRouter::decide($damage,[],$policy,$now)['action'],'damaged/discrepant return initial opening is manual-only');
$fba=$case;$fba['program']='FBA';raEq('CHECK_FINANCES',SvAmazonReturnActionRouter::decide($fba,[$return],$policy,$now)['action'],'classic FBA has a separate finance/support route');
$claim=$case+['safe_t_id'=>'11111-22222-3333333','appeal_deadline_at'=>'2026-09-15 18:00:00'];$claim['state']='SAFE_T_DENIED';
$promise=raEvent('SAFE_T_STATUS_OBSERVED',['claim_status'=>'DENIED','decision_text'=>'Voce sera reembolsado proativamente ate 10 de setembro de 2026.']);
$future=SvAmazonReturnActionRouter::decide($claim,[$promise],$policy,$now);
raEq('WAIT_PROACTIVE_CREDIT',$future['action'],'promise is not a negative requiring immediate appeal');
raEq('2026-09-11 03:00:00',$future['next_action_at'],'date-only promise includes the whole Brazil calendar day');
$overdue=$promise;$overdue['payload']['decision_text']='Voce sera reembolsado proativamente ate 4 de setembro de 2026.';
raEq('CHECK_FINANCES',SvAmazonReturnActionRouter::decide($claim,[$overdue],$policy,$now)['action'],'overdue promise first checks actual finance');
$checked=raEvent('FINANCIAL_RECONCILIATION_CHECKED',['refresh_complete'=>true,'credit_amount'=>'0.00','outstanding_amount'=>'100.00','unclassified_transactions'=>0,'ambiguous_reimbursement_transactions'=>0,'unsettled_financial_evidence'=>false],2,'2026-09-05 14:00:00','SP_API_FINANCES');
raEq(null,SvAmazonReturnActionRouter::decide($claim,[$overdue,$checked],$policy,$now),'fresh unpaid finance permits normal existing-claim lifecycle');
$fbaClaim=$claim;$fbaClaim['program']='FBA';
raEq('WAIT_PROACTIVE_CREDIT',SvAmazonReturnActionRouter::decide($fbaClaim,[$promise],$policy,$now)['action'],'classic FBA with an existing SAFE-T must honor Amazon promise instead of looping on finance');
raEq(null,SvAmazonReturnActionRouter::decide($fbaClaim,[$overdue,$checked],$policy,$now),'classic FBA with existing SAFE-T and verified unpaid balance must return to claim lifecycle');
$expired=$claim;$expired['appeal_deadline_at']='2026-09-02 18:00:00';
raEq('HUMAN_REVIEW',SvAmazonReturnActionRouter::decide($expired,[],$policy,$now)['action'],'expired appeal cannot be scheduled normally');
$unknown=$claim;unset($unknown['appeal_deadline_at']);
raEq('HUMAN_REVIEW',SvAmazonReturnActionRouter::decide($unknown,[],$policy,$now)['action'],'missing official appeal deadline is not guessed');
$sent=raEvent('SAFE_T_EMAIL_REVIEW_SENT',['safe_t_id'=>$claim['safe_t_id']],3,'2026-09-01 15:00:00','GMAIL');
raEq('WAIT',SvAmazonReturnActionRouter::decide($claim,[$sent],$policy,$now)['action'],'historical sent review suppresses duplicate claim stage');
$late=$claim;$late['state']='APPEAL_DENIED_FINAL';raEq('WAIT',SvAmazonReturnActionRouter::decide($late,[$sent],$policy,$now)['action'],'repeated status reads cannot resend existing review');
$support=$case+['support_case_id'=>'12345678901'];$lost=$return;$lost['payload']['return_status']='Perdido no Transporte';
raEq('WAIT',SvAmazonReturnActionRouter::decide($support,[$lost,$checked],$policy,$now)['action'],'reuse existing support, do not open a duplicate');
raEq('SELLER_SUPPORT_OPEN',SvAmazonReturnActionRouter::decide($case,[$lost,$checked],$policy,$now)['action'],'overdue proactive route with fresh unpaid finance can escalate');
$old=$checked;$old['occurred_at']='2026-07-01 00:00:00';
raEq('CHECK_FINANCES',SvAmazonReturnActionRouter::decide($case,[$lost,$old],$policy,$now)['action'],'stale finance cannot authorize escalation');
$delivered=$return;$delivered['payload']=['return_status'=>'Entregue ao vendedor','return_delivery_at'=>'2026-09-01 14:00:00'];
raEq('DAMAGE_EVIDENCE_REVIEW',SvAmazonReturnActionRouter::decide($case,[$delivered],$policy,$now)['action'],'carrier delivery opens separate7day evidence route');
$delivered['payload']['return_delivery_at']='2026-08-20 14:00:00';
raEq('HUMAN_REVIEW',SvAmazonReturnActionRouter::decide($case,[$delivered],$policy,$now)['action'],'expired7day window cannot be normal new claim');
$untrusted=$lost;$untrusted['source']='UNTRUSTED';raEq('HUMAN_REVIEW',SvAmazonReturnActionRouter::decide($case,[$untrusted],$policy,$now)['action'],'untrusted transport payload cannot choose automatic route');
$ambiguous=$promise;$ambiguous['payload']['decision_text']='Voce sera reembolsado proativamente. Aguarde nossa resposta.';
raEq('HUMAN_REVIEW',SvAmazonReturnActionRouter::decide($claim,[$ambiguous],$policy,$now)['action'],'undated proactive promise must not trigger a blind appeal');
$other=$sent;$other['payload']['safe_t_id']='99999-88888-7777777';raEq(null,SvAmazonReturnActionRouter::decide($claim,[$other],$policy,$now),'different claim cannot suppress current lifecycle');
$contradiction=$checked;$contradiction['payload']['credit_amount']='100.00';$contradiction['payload']['outstanding_amount']='0.00';
raEq('CHECK_FINANCES',SvAmazonReturnActionRouter::decide($case,[$lost,$contradiction],$policy,$now)['action'],'positive-credit disagreement must not open another support claim');

// Owner rule 2026-09-05: Amazon customer refund + D45 + seller loss => SAFE-T regardless of transport status.
$refunded=$case+['refund_initiator'=>'AMAZON_AUTOMATIC'];
raEq(null,SvAmazonReturnActionRouter::decide($refunded,[],$policy,$now),'D45 customer refund cannot be blocked by missing transport status');
foreach(['Retornando ao Vendedor','Perdido no Transporte','Nao foi possivel entregar','Recusado pelo cliente','Avariado pela Transportadora','Status Desconhecido'] as $status){
 $event=$return;$event['payload']['return_status']=$status;
 raEq(null,SvAmazonReturnActionRouter::decide($refunded,[$event],$policy,$now),'D45 seller loss delegates SAFE-T regardless of transport: '.$status);
}
$receivedLoss=$refunded;$receivedLoss['physical_status']='RECEIVED_OK';
raEq(null,SvAmazonReturnActionRouter::decide($receivedLoss,[$return],$policy,$now),'returned item with unresolved seller financial loss still delegates SAFE-T at D45');
$paid=$refunded;$paid['reconciled_credit_amount']='100.00';
raEq(null,SvAmazonReturnActionRouter::decide($paid,[$return],$policy,$now),'router delegates fully reimbursed cases to engine top-level credit suppression');

if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "return-action-router-test: OK\n";
