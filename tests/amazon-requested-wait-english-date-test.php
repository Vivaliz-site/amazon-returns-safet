<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/AmazonRequestedWait.php';
require_once __DIR__.'/../includes/amazon-returns/ReturnActionRouter.php';

$errors=[];
function engDateEq(mixed $want,mixed $got,string $why):void{global $errors;if($want!==$got)$errors[]=$why.' expected='.json_encode($want).' actual='.json_encode($got);}

$examples=[
    [
        'text'=>'Nenhuma ação é necessária da nossa parte neste momento. Se a devolução ainda não estiver marcada como entregue, você será reembolsado proativamente até September'."\u{200E}".' '."\u{200E}".'10'."\u{200E}".', '."\u{200E}".'2026',
        'wait_expected'=>'2026-09-10 03:00:00',
        'router_expected'=>'2026-09-11 03:00:00',
        'label'=>'Seller Central long English month with direction marks',
    ],
    [
        'text'=>'Se a devolução ainda não estiver marcada como entregue, você será reembolsado proativamente até Friday'."\u{200E}".', '."\u{200E}".'September'."\u{200E}".' '."\u{200E}".'4'."\u{200E}".', '."\u{200E}".'2026',
        'wait_expected'=>'2026-09-04 03:00:00',
        'router_expected'=>'2026-09-05 03:00:00',
        'label'=>'Seller Central weekday plus English month',
    ],
    [
        'text'=>'Se a devolução ainda não estiver marcada como entregue, você será reembolsado proativamente até Jun 24 2026',
        'wait_expected'=>'2026-06-24 03:00:00',
        'router_expected'=>'2026-06-25 03:00:00',
        'label'=>'Seller Central abbreviated English month',
    ],
    [
        'text'=>'Se a devolução ainda não estiver marcada como entregue, você será reembolsado proativamente até 12 August 2026',
        'wait_expected'=>'2026-08-12 03:00:00',
        'router_expected'=>'2026-08-13 03:00:00',
        'label'=>'Seller Central day-first English month',
    ],
];

foreach($examples as $example){
    $wait=SvAmazonRequestedWait::parse($example['text'],'2026-09-01 12:00:00');
    engDateEq($example['wait_expected'],$wait['next_action_at']??null,$example['label'].' requested-wait parser');
    $deadline=SvAmazonReturnActionRouter::promiseDeadline($example['text']);
    engDateEq($example['router_expected'],$deadline?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),$example['label'].' action-router parser');
}

$pt=SvAmazonRequestedWait::parse('Você será reembolsado proativamente até 10 de setembro de 2026.','2026-09-01 12:00:00');
engDateEq('2026-09-10 03:00:00',$pt['next_action_at']??null,'owner-approved dated resumption must preserve the requested Brazil calendar date');

if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "amazon-requested-wait-english-date-test: OK\n";
