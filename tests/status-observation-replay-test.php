<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTStatusService.php';
$errors=[];function sorSame(mixed $want,mixed $got,string $why):void{global $errors;if($want!==$got)$errors[]=$why.' expected='.json_encode($want).' actual='.json_encode($got);}
foreach(['APPEAL_SUBMITTED','APPEAL_DENIED_FINAL','EMAIL_REVIEW_SENT','EMAIL_REVIEW_RESPONSE_PENDING','SUPPORT_ESCALATION','RECOVERED'] as $state){sorSame($state,SvAmazonSafeTStatusService::nextState($state,'DENIED',false),'generic status must not rewind '.$state);}
sorSame('RECOVERED',SvAmazonSafeTStatusService::nextState('RECOVERED','APPROVED',false),'only finances controls financially recovered closure');
sorSame('APPEAL_DENIED_FINAL',SvAmazonSafeTStatusService::nextState('APPEAL_SUBMITTED','DENIED',true),'an explicit appeal denial still advances appeal');
if(!method_exists(SvAmazonSafeTStatusService::class,'observationPlan') || !method_exists(SvAmazonSafeTStatusService::class,'projection')){$errors[]='Missing chronological observation replay and projection repair';}
else{
 $denied=['safe_t_id'=>'11111-22222-3333333','claim_status'=>'DENIED','decision_fingerprint'=>str_repeat('a',64),'decision_text'=>'Aguarde ate 10/09/2026.','appeal_denied'=>false,'appeal_deadline_at'=>'2026-09-12 12:00:00'];
 $approved=$denied;$approved['claim_status']='APPROVED';$approved['decision_fingerprint']=str_repeat('b',64);
 $a=['id'=>10,'case_id'=>77,'source'=>'SELLER_CENTRAL','event_type'=>'SAFE_T_STATUS_OBSERVED','occurred_at'=>'2026-09-01 12:00:00','payload'=>$denied,'idempotency_key'=>str_repeat('c',64)];
 $b=$a;$b['id']=11;$b['occurred_at']='2026-09-02 12:00:00';$b['payload']=$approved;$b['idempotency_key']=str_repeat('d',64);
 $plan=SvAmazonSafeTStatusService::observationPlan(77,$denied,[$b,$a]);sorSame(true,$plan['append'],'A-B-A change must append evidence rather than suppress old global fingerprint');sorSame(false,$plan['idempotency_key']===$a['idempotency_key'],'returned denial needs a new revision identity');
 $c=$a;$c['id']=12;$c['occurred_at']='2026-09-03 12:00:00';$c['idempotency_key']=$plan['idempotency_key'];
 $same=SvAmazonSafeTStatusService::observationPlan(77,$denied,[$a,$b,$c]);sorSame(false,$same['append'],'unchanged repeated read must not append evidence on each poll');sorSame($c['idempotency_key'],$same['idempotency_key'],'reuse current observation for repeated read');
 $case=['id'=>77,'state'=>'SAFE_T_APPROVED','repeated_denial_count'=>2,'last_denial_fingerprint'=>str_repeat('a',64),'appeal_deadline_at'=>null];
 $patch=SvAmazonSafeTStatusService::projection($case,$denied,false,new DateTimeImmutable('2026-09-05T12:00:00Z'));
 sorSame('SAFE_T_DENIED',$patch['state'],'fresh identical denial must repair a stale approved projection');sorSame(2,$patch['repeated_denial_count'],'repeated polling cannot increment denial count');sorSame('2026-09-10 03:00:00',$patch['next_action_at'],'read refresh must preserve Amazon requested date rather than replacing with now');
}
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "status-observation-replay-test: OK\n";
