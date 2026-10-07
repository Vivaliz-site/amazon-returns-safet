<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';

function ssrdSame(mixed $expected,mixed $actual,string $message):void{if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));}
function ssrdAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$engine=new SvAmazonSafeTDecisionEngine();
$policy=['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'];
$case=[
    'id'=>2,'amazon_order_id'=>'702-5349464-0245862','program'=>'FBA','safe_t_id'=>null,
    'support_case_id'=>'21839128801','state'=>'CREDIT_PENDING','physical_status'=>'NOT_RECEIVED',
    'refund_at'=>'2026-06-15 21:06:57','seller_debit_at'=>'2026-06-15 21:06:57',
    'refund_initiator'=>'AMAZON_CUSTOMER_SERVICE','expected_reimbursement_amount'=>'192.73','reconciled_credit_amount'=>'0.00',
];
$finance=[
    'id'=>1001,'case_id'=>2,'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','source'=>'SP_API_FINANCES',
    'occurred_at'=>'2026-09-10 04:15:00','payload'=>[
        'refresh_complete'=>true,'credit_amount'=>'0.00','outstanding_amount'=>'192.73',
        'ambiguous_reimbursement_transactions'=>0,'unsettled_financial_evidence'=>false,
    ],
];
$resolved=[
    'id'=>2001,'case_id'=>2,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-10 04:20:00','payload'=>[
        'case_id'=>'21839128801','case_status'=>'RESOLVED',
        'latest_text'=>'Seu crédito foi processado com sucesso. Aguarde 4 a 5 dias úteis. Reimbursement ID: 23206413461.',
    ],
];
$beforeDue=$engine->nextAction($case,[$finance,$resolved],$policy,new DateTimeImmutable('2026-09-10 05:00:00',new DateTimeZone('UTC')));
ssrdSame('WAIT',$beforeDue['action']??null,'Resolved support reimbursement promise must suppress duplicate Seller Support openings.');
ssrdSame('SUPPORT_REIMBURSEMENT_PROCESSING',$beforeDue['reason']??null,'Resolved reimbursement promise must have an explicit wait reason.');
ssrdAssert(trim((string)($beforeDue['next_action_at']??''))!=='','Support reimbursement promise must schedule a deterministic finance recheck.');

$afterDue=$engine->nextAction($case,[$finance,$resolved],$policy,new DateTimeImmutable('2026-09-18 05:00:00',new DateTimeZone('UTC')));
ssrdSame('CHECK_FINANCES',$afterDue['action']??null,'After the promised processing window, the system must verify finances before any new support escalation.');
ssrdSame('SUPPORT_REIMBURSEMENT_PROMISE_DUE',$afterDue['reason']??null,'Expired support reimbursement promise must route to finance verification.');
$appealCase=$case;
$appealCase['id']=3;$appealCase['amazon_order_id']='702-4764960-2141837';
$appealCase['safe_t_id']='12797-64249-3531034';$appealCase['state']='SUPPORT_ESCALATION';
$appealCase['latest_denial_text']='Recurso negado pela Amazon.';
$appealCase['support_case_id']='21838892481';
$reviewResolved=[
    'id'=>2002,'case_id'=>3,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-10 04:30:00','payload'=>[
        'case_id'=>'21838892481','case_status'=>'RESOLVED',
        'latest_text'=>'Para uma análise detalhada, envie sua solicitação para Safe-T-Review@amazon.com.',
    ],
];
$reviewRoute=$engine->nextAction($appealCase,[$reviewResolved],$policy,new DateTimeImmutable('2026-09-10 05:00:00',new DateTimeZone('UTC')));
ssrdSame('SAFE_T_EMAIL_REVIEW',$reviewRoute['action']??null,'Resolved support guidance to Safe-T-Review must route to the existing email review channel.');
ssrdSame('SUPPORT_RESOLUTION_DIRECTS_EMAIL_REVIEW',$reviewRoute['reason']??null,'Support-directed email review must be explicit and deterministic.');
ssrdAssert(preg_match('/^[a-f0-9]{64}$/',(string)($reviewRoute['idempotency_key']??''))===1,'Support-directed email review must be idempotent.');
$activeCase=$appealCase;
$activeCase['id']=4;$activeCase['amazon_order_id']='701-8088549-4857851';$activeCase['support_case_id']='21839072101';
$activeObserved=[
    'id'=>2003,'case_id'=>4,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-10 04:40:00','payload'=>[
        'case_id'=>'21839072101','case_status'=>'PENDINGAMAZONACTION','latest_text'=>'Um especialista está analisando o caso.',
    ],
];
$active=$engine->nextAction($activeCase,[$activeObserved],$policy,new DateTimeImmutable('2026-09-10 05:00:00',new DateTimeZone('UTC')));
ssrdSame('WAIT',$active['action']??null,'An active Seller Support case must still suppress duplicate support writes.');
ssrdSame('SUPPORT_ESCALATION_ALREADY_ACTIVE',$active['reason']??null,'Active support observation must preserve the existing wait behavior.');


$postDueFinance=$finance;
$postDueFinance["id"]=1002;
$postDueFinance["occurred_at"]="2026-09-18 04:55:00";
$continueRecovery=$engine->nextAction($case,[$finance,$resolved,$postDueFinance],$policy,new DateTimeImmutable("2026-09-18 05:00:00",new DateTimeZone("UTC")));
ssrdSame("SELLER_SUPPORT_OPEN",$continueRecovery["action"]??null,"After a post-promise finance check still proves the balance unpaid, recovery must continue instead of checking finances forever.");
ssrdSame("FBA_RETURNS_REIMBURSEMENT",$continueRecovery["support_route"]??null,"A missed Seller Support reimbursement promise must resume the normal FBA reimbursement route.");

$emailAlreadySent=$appealCase;
$emailAlreadySent["state"]="EMAIL_REVIEW_SENT";
$afterEmailSent=$engine->nextAction($emailAlreadySent,[$reviewResolved],$policy,new DateTimeImmutable("2026-09-10 05:05:00",new DateTimeZone("UTC")));
ssrdAssert(($afterEmailSent["action"]??null)!=="SAFE_T_EMAIL_REVIEW","A resolved support instruction must not resend the same email review after it has already been sent.");

$directAppealCase=$appealCase;
$directAppealCase['id']=5;
$directAppealCase['amazon_order_id']='702-7802983-5785045';
$directAppealCase['safe_t_id']='27845-46811-9805451';
$directAppealCase['support_case_id']='21839077561';
$directAppealCase['state']='SUPPORT_ESCALATION';
$directAppealCase['appeal_deadline_at']='2026-09-12 12:00:00';
$directAppealObserved=[
    'id'=>2004,'case_id'=>5,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-10 04:45:00','payload'=>[
        'case_id'=>'21839077561','case_status'=>'RESOLVED',
        'latest_text'=>'A resolução deve ser recorrida diretamente pela SAFE-T. O Seller Support não pode interferir.',
    ],
];
$directAppeal=$engine->nextAction($directAppealCase,[$directAppealObserved],$policy,new DateTimeImmutable('2026-09-10 05:10:00',new DateTimeZone('UTC')));
ssrdSame('SAFE_T_APPEAL',$directAppeal['action']??null,'Resolved Seller Support guidance to appeal directly in SAFE-T must use the SAFE-T appeal channel while the window is open.');
$appealSubmitted=$directAppealCase;
$appealSubmitted['state']='APPEAL_SUBMITTED';
$afterAppeal=$engine->nextAction($appealSubmitted,[$directAppealObserved],$policy,new DateTimeImmutable('2026-09-10 05:15:00',new DateTimeZone('UTC')));
ssrdSame('WAIT',$afterAppeal['action']??null,'A support instruction must not send an already-submitted SAFE-T appeal into human review.');
ssrdAssert(($afterAppeal['reason']??null)!=='SUPPORT_RESOLUTION_APPEAL_WINDOW_UNAVAILABLE','An already-submitted appeal is progress, not an unavailable appeal window.');

$historicalEmailSent=[
    'id'=>1999,'case_id'=>3,'event_type'=>'SAFE_T_EMAIL_REVIEW_SENT','source'=>'GMAIL',
    'occurred_at'=>'2026-09-07 20:23:23','payload'=>['safe_t_id'=>'12797-64249-3531034'],
];
$afterHistoricalEmail=$engine->nextAction($appealCase,[$historicalEmailSent,$reviewResolved],$policy,new DateTimeImmutable('2026-09-10 05:20:00',new DateTimeZone('UTC')));
ssrdSame('WAIT',$afterHistoricalEmail['action']??null,'A reconciled support case must honor an earlier email-review send even when state was overwritten by support escalation.');
ssrdSame('EXISTING_EMAIL_REVIEW_AWAITING_RESPONSE',$afterHistoricalEmail['reason']??null,'Email-review history must prevent a duplicate support-directed email.');

$acceptedAppealHistory=[
    'id'=>1998,'case_id'=>5,'event_type'=>'SELLER_CENTRAL_ACTION_RESULT','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-10 05:12:00','payload'=>['action'=>'SAFE_T_APPEAL','status'=>'ACCEPTED','submitted'=>true,'external_id'=>'27845-46811-9805451'],
];
$afterHistoricalAppeal=$engine->nextAction($directAppealCase,[$acceptedAppealHistory,$directAppealObserved],$policy,new DateTimeImmutable('2026-09-10 05:20:00',new DateTimeZone('UTC')));
ssrdSame('WAIT',$afterHistoricalAppeal['action']??null,'A reconciled support case must not repeat a SAFE-T appeal already accepted in the event history.');
ssrdSame('APPEAL_ALREADY_SUBMITTED',$afterHistoricalAppeal['reason']??null,'Accepted appeal history must remain authoritative after state changes.');

// A Seller Support observation that fails normalization (invalid case ID) must
// never be silently ignored or crash the engine: it must block for human review.
$invalidObservationCase=$appealCase;
$invalidObservationCase['id']=6;
$invalidObservationCase['amazon_order_id']='703-1111111-1111111';
$invalidObservationCase['support_case_id']='NOT-A-VALID-ID';
$invalidObservation=[
    'id'=>2005,'case_id'=>6,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-10 04:50:00','payload'=>[
        'case_id'=>'NOT-A-VALID-ID','case_status'=>'RESOLVED','latest_text'=>'Caso resolvido.',
    ],
];
$invalidResult=$engine->nextAction($invalidObservationCase,[$invalidObservation],$policy,new DateTimeImmutable('2026-09-10 05:00:00',new DateTimeZone('UTC')));
ssrdSame('BLOCKED_REVIEW',$invalidResult['action']??null,'A Seller Support observation that fails normalization must block for human review, never be silently skipped.');
ssrdSame('SELLER_SUPPORT_OBSERVATION_INVALID',$invalidResult['reason']??null,'Invalid Seller Support observation must be named explicitly.');

// Support guidance to email review without a SAFE-T ID linked to the case must
// block for human review rather than attempt an email review with nothing to reference.
$noSafeTIdCase=$appealCase;
$noSafeTIdCase['id']=7;
$noSafeTIdCase['amazon_order_id']='703-2222222-2222222';
$noSafeTIdCase['safe_t_id']=null;
$noSafeTIdCase['support_case_id']='21838892482';
$noSafeTIdObserved=[
    'id'=>2006,'case_id'=>7,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-10 04:55:00','payload'=>[
        'case_id'=>'21838892482','case_status'=>'RESOLVED',
        'latest_text'=>'Para uma análise detalhada, envie sua solicitação para Safe-T-Review@amazon.com.',
    ],
];
$noSafeTIdResult=$engine->nextAction($noSafeTIdCase,[$noSafeTIdObserved],$policy,new DateTimeImmutable('2026-09-10 05:00:00',new DateTimeZone('UTC')));
ssrdSame('BLOCKED_REVIEW',$noSafeTIdResult['action']??null,'Support-directed email review without a linked SAFE-T ID must block for human review, never send an email review with nothing to reference.');
ssrdSame('SUPPORT_RESOLUTION_SAFE_T_ID_MISSING',$noSafeTIdResult['reason']??null,'Missing SAFE-T ID on a support-directed email review must be named explicitly.');



// A newer active sibling must supersede an older terminal sibling that still
// looks semantically actionable. Otherwise the engine keeps targeting a
// closed case while Amazon is already working the newer sibling.
$newerActiveSiblingCase=$case;
$newerActiveSiblingCase['id']=8;
$newerActiveSiblingCase['amazon_order_id']='702-3172035-4814644';
$newerActiveSiblingCase['support_case_id']='22403466041';
$newerActiveSiblingCase['state']='SUPPORT_ESCALATION';
$newerActiveSiblingCase['refund_at']='2026-08-20 12:00:00';
$newerActiveSiblingCase['seller_debit_at']='2026-08-20 12:00:00';
$newerActiveSiblingCase['expected_reimbursement_amount']='72.78';
$newerActiveSiblingCase['reconciled_credit_amount']='0.00';

$newerActiveFinance=$finance;
$newerActiveFinance['id']=3001;
$newerActiveFinance['case_id']=8;
$newerActiveFinance['occurred_at']='2026-10-07 02:08:00';
$newerActiveFinance['payload']['outstanding_amount']='72.78';

$staleClosedSibling=[
    'id'=>3002,'case_id'=>8,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-10-07 02:10:00','payload'=>[
        'case_id'=>'22403466041','case_status'=>'RESOLVED',
        'latest_text'=>"Because we haven't received a response from you, we assume that your issue is resolved.",
    ],
];
$newerActiveSibling=[
    'id'=>3003,'case_id'=>8,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-10-07 02:11:00','payload'=>[
        'case_id'=>'22403666441','case_status'=>'PENDINGAMAZONACTION',
        'latest_text'=>'A Amazon está analisando o ressarcimento deste pedido.',
    ],
];
$newerActiveSiblingDecision=$engine->nextAction(
    $newerActiveSiblingCase,
    [$newerActiveFinance,$staleClosedSibling,$newerActiveSibling],
    $policy,
    new DateTimeImmutable('2026-10-07 02:12:00',new DateTimeZone('UTC'))
);
ssrdSame(
    'WAIT',
    $newerActiveSiblingDecision['action']??null,
    'A newer active Seller Support sibling must suppress a stale closed sibling response.'
);
ssrdSame(
    'SUPPORT_ESCALATION_ALREADY_ACTIVE',
    $newerActiveSiblingDecision['reason']??null,
    'The newer active sibling must remain authoritative while Amazon is acting.'
);

// When sibling observations arrive in the same discovery second, the current
// persisted support binding must win the tie. Otherwise the engine can target
// a sibling that the bridge will correctly reject as scope-superseded.
$sameSecondCase=$case;
$sameSecondCase['id']=9;
$sameSecondCase['amazon_order_id']='702-9207715-8524262';
$sameSecondCase['support_case_id']='22450149561';
$sameSecondCase['state']='SUPPORT_ESCALATION';
$sameSecondCase['expected_reimbursement_amount']='485.75';
$sameSecondCase['reconciled_credit_amount']='262.85';

$currentBindingObservation=[
    'id'=>4001,'case_id'=>9,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-10-07 16:35:09','payload'=>[
        'case_id'=>'22450149561','case_status'=>'PENDINGMERCHANTACTION',
        'latest_text'=>'This is a reminder to let you know that we need more information to resolve your case.',
    ],
];
$siblingObservation=[
    'id'=>4002,'case_id'=>9,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-10-07 16:35:09','payload'=>[
        'case_id'=>'22449931941','case_status'=>'PENDINGMERCHANTACTION',
        'latest_text'=>'This is a reminder to let you know that we need more information to resolve your case.',
    ],
];
$sameSecondDecision=$engine->nextAction(
    $sameSecondCase,
    [$currentBindingObservation,$siblingObservation],
    $policy,
    new DateTimeImmutable('2026-10-07 16:36:00',new DateTimeZone('UTC'))
);
ssrdSame('SELLER_SUPPORT_UPDATE',$sameSecondDecision['action']??null,'Seller action required must remain actionable.');
ssrdSame('22450149561',$sameSecondDecision['support_case_id']??null,'Current Seller Support binding must win a same-second sibling tie.');

// An active newer sibling must also suppress the APPEAL_DENIED_FINAL fallback.
$activeAfterDenied=$case;
$activeAfterDenied['id']=10;
$activeAfterDenied['amazon_order_id']='702-1808735-0608208';
$activeAfterDenied['program']='FBA';
$activeAfterDenied['safe_t_id']='37495-60041-8543443';
$activeAfterDenied['state']=SvAmazonReturnStates::APPEAL_DENIED_FINAL;
$activeAfterDenied['latest_denial_text']='Recurso negado. Decisão final mantida.';
$activeAfterDenied['support_case_id']='22144771981';
$activeAfterDenied['refund_at']='2026-08-03 18:22:13';
$activeAfterDenied['seller_debit_at']='2026-08-03 18:22:13';
$activeAfterDenied['expected_reimbursement_amount']='68.57';
$activeAfterDenied['reconciled_credit_amount']='0.00';

$oldDeniedSupport=[
    'id'=>5001,'case_id'=>10,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-10-07 19:20:00','payload'=>[
        'case_id'=>'22144771981','case_status'=>'RESOLVED',
        'latest_text'=>'Caso anterior encerrado.',
    ],
];
$activeInternalReview=[
    'id'=>5002,'case_id'=>10,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-10-07 19:23:30','payload'=>[
        'case_id'=>'22455791871','case_status'=>'PENDINGAMAZONACTION',
        'latest_text'=>'Recebemos sua solicitação SAFE-T e encaminhamos para a equipe interna. Aguarde nossa atualização.',
    ],
];
$activeAfterDeniedDecision=$engine->nextAction(
    $activeAfterDenied,
    [$oldDeniedSupport,$activeInternalReview],
    $policy,
    new DateTimeImmutable('2026-10-07 19:24:00',new DateTimeZone('UTC'))
);
ssrdSame('WAIT',$activeAfterDeniedDecision['action']??null,'An active Seller Support sibling must suppress a duplicate post-denial review write.');
ssrdSame('SUPPORT_ESCALATION_ALREADY_ACTIVE',$activeAfterDeniedDecision['reason']??null,'Amazon-owned active support work must remain authoritative after final appeal denial.');
ssrdSame('22455791871',$activeAfterDeniedDecision['support_case_id']??null,'Wait must identify the active sibling that Amazon is currently handling.');

echo "seller-support-resolution-decision-test: OK\n";
