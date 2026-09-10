<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/amazon-returns/SafeTDecisionEngine.php';

function rdSame(mixed $expected,mixed $actual,string $message):void{if($expected!==$actual)throw new RuntimeException($message.'\nExpected: '.var_export($expected,true).'\nActual: '.var_export($actual,true));}
$engine=new SvAmazonSafeTDecisionEngine();
$case=['id'=>77,'amazon_order_id'=>'702-1234567-7654321','safe_t_id'=>'12472-25597-6629839','state'=>'EMAIL_REVIEW_RESPONSE_PENDING','physical_status'=>'NOT_RECEIVED','expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00','support_case_id'=>null];
$policy=['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'];
$event=static fn(string $outcome,string $action):array=>['event_type'=>'SAFE_T_EMAIL_REVIEW_RESPONSE','payload'=>['review_outcome'=>$outcome,'review_suggested_action'=>$action,'content_sha256'=>hash('sha256',$outcome.'|'.$action)]];

rdSame('WAIT',$engine->nextAction($case,[$event('WAIT','WAIT')],$policy)['action'],'Promised future action must wait.');
rdSame('BLOCKED_REVIEW',$engine->nextAction($case,[$event('UNKNOWN_AMBIGUOUS','HUMAN_REVIEW')],$policy)['action'],'Ambiguous email reply must fail closed.');
$support=$engine->nextAction($case,[$event('DENIED_ACTIONABLE','OPEN_SUPPORT')],$policy);
rdSame('SELLER_SUPPORT_OPEN',$support['action'],'Actionable email denial may escalate to Support when analyzer selects it.');
rdSame('GENERAL_ORDER_SUPPORT',$support['support_route']??null,'Analyzer-selected Seller Support must use the general support route.');
rdSame('SAFE_T_EMAIL_REPLY',$engine->nextAction($case,[$event('INFO_REQUESTED','RESPOND_EMAIL')],$policy)['action'],'Verified information request may create one email reply.');
rdSame('BLOCKED_REVIEW',$engine->nextAction($case,[$event('DENIED_FINAL','HUMAN_REVIEW')],$policy)['action'],'Final language alone cannot auto-close without terminal gate.');
rdSame('CLOSE_LOSS',$engine->nextAction($case,[$event('DENIED_FINAL','CLOSED_LOSS')],$policy)['action'],'Explicit terminal approval may close documented loss.');

echo "review-reply-decision-test: OK\n";
