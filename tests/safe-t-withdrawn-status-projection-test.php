<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/amazon-returns/SafeTStatus.php';
require_once __DIR__ . '/../includes/amazon-returns/SafeTStatusService.php';
function withdrawnAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$read=SvAmazonSafeTStatus::normalize(['claim_status'=>'WITHDRAWN','safe_t_id'=>'11111-22222-3333333',
 'decision_text'=>'Retiramos sua reivindicação SAFE-T em seu nome.']);
withdrawnAssert($read['claim_status']==='WITHDRAWN','withdrawn must be a distinct external observation');
withdrawnAssert(SvAmazonSafeTStatus::domainState('WITHDRAWN')===null,'no invented terminal business state');
withdrawnAssert(SvAmazonSafeTStatusService::nextState('SAFE_T_SUBMITTED','WITHDRAWN')==='SAFE_T_SUBMITTED',
 'withdrawn must not automatically become denied or approved');
$now=new DateTimeImmutable('2026-11-06T09:00:00Z');
$case=['state'=>'SAFE_T_SUBMITTED','appeal_deadline_at'=>null,'last_denial_fingerprint'=>null,'repeated_denial_count'=>0];
$patch=SvAmazonSafeTStatusService::projection($case,$read,true,$now);
withdrawnAssert(($patch['state']??'')==='SAFE_T_SUBMITTED','business state must stay unchanged');
withdrawnAssert(($patch['next_action_at']??'')==='2026-11-06 09:00:00','manual review must be due now');
withdrawnAssert(!array_key_exists('safe_t_id',$patch),'do not overwrite claim identity');
withdrawnAssert(!array_key_exists('last_claim_outcome',$patch),'do not invent an outcome');
$replayed=SvAmazonSafeTStatusService::projection($case,$read,false,$now);
withdrawnAssert(!isset($replayed['next_action_at']),'replay must not schedule duplicate review');
$closed=$case;$closed['state']='RECOVERED';
withdrawnAssert(!isset(SvAmazonSafeTStatusService::projection($closed,$read,true,$now)['next_action_at']),
 'do not reopen a recovered case');
echo "safe-t-withdrawn-status-projection-test: OK\n";
