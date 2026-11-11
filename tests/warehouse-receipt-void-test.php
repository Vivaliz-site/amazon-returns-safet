<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Projector.php';

$errors=[];
function vrSame(mixed $want,mixed $got,string $label):void {
    global $errors;
    if($want!==$got)$errors[]=$label.' expected='.json_encode($want).' got='.json_encode($got);
}

$case=[
    'id'=>101669,
    'quantity_ordered'=>1,
    'quantity_received'=>1,
    'physical_status'=>'RECEIVED_OK',
    'state'=>'RECEIVED_OK',
    'terminal_reason'=>'PHYSICAL_RETURN_RECEIVED',
    'closed_at'=>'2026-11-11 00:48:01',
    'expected_reimbursement_amount'=>'50.94',
    'reconciled_credit_amount'=>'0.00',
    'safe_t_id'=>'89790-48319-8106439',
];
$refund=[
    'id'=>100,'case_id'=>101669,'event_type'=>'REFUND_CONFIRMED',
    'source'=>'SP_API_FINANCES','occurred_at'=>'2026-09-18 08:15:44',
    'payload'=>['refund_at'=>'2026-09-18 08:15:44','quantity_refunded'=>1,'refund_amount'=>'50.94'],
];
$receipt=[
    'id'=>1267182,'case_id'=>101669,'event_type'=>'PHYSICAL_RECEIVED',
    'source'=>'WAREHOUSE','source_event_id'=>'d3ca914b-9672-44d7-adf9-db6525ef8dd5',
    'occurred_at'=>'2026-11-11 00:48:01',
    'payload'=>['quantity'=>1,'condition'=>'OK','operator_id'=>2585484896],
];
$void=[
    'id'=>1269999,'case_id'=>101669,'event_type'=>'PHYSICAL_RECEIPT_VOIDED',
    'source'=>'WAREHOUSE','source_event_id'=>'correct-101669-receipt-1267182',
    'occurred_at'=>'2026-11-11 02:30:00',
    'payload'=>['receipt_event_id'=>1267182,'operator_id'=>123,'reason'=>'WRONG_ADMIN_INTAKE'],
];
$before=SvAmazonReturnProjector::projectFrom($case,[$refund,$receipt]);
vrSame('RECEIVED_OK',$before['physical_status'],'real receipt before compensation');
vrSame(1,$before['quantity_received'],'original quantity before compensation');

$after=SvAmazonReturnProjector::projectFrom($case,[$refund,$receipt,$void]);
vrSame('NOT_RECEIVED',$after['physical_status'],'void restores not received');
vrSame(0,$after['quantity_received'],'void excludes precise warehouse intake');
vrSame('AWAITING_RETURN',$after['state'],'state no longer asserts receipt');
vrSame(null,$after['closed_at'],'physical closure is undone');
vrSame(null,$after['terminal_reason'],'physical terminal reason is undone');
vrSame('89790-48319-8106439',$after['safe_t_id'],'existing SAFE-T retained');
vrSame('0.00',$after['reconciled_credit_amount'],'financial balance preserved');
vrSame('50.94',$after['refund_amount'],'refund evidence preserved');

$duplicate=$void;$duplicate['id']=1270000;
$twice=SvAmazonReturnProjector::projectFrom($case,[$refund,$receipt,$void,$duplicate]);
vrSame(0,$twice['quantity_received'],'two corrections are idempotent');

$invalid=$void;$invalid['payload']['receipt_event_id']=1267183;
vrSame(1,SvAmazonReturnProjector::projectFrom($case,[$refund,$receipt,$invalid])['quantity_received'],'unrelated receipt ID cannot be voided');
$foreign=$void;$foreign['case_id']=101670;
vrSame(1,SvAmazonReturnProjector::projectFrom($case,[$refund,$receipt,$foreign])['quantity_received'],'foreign case cannot void receipt');
$otherSource=$void;$otherSource['source']='GMAIL';
vrSame(1,SvAmazonReturnProjector::projectFrom($case,[$refund,$receipt,$otherSource])['quantity_received'],'other source cannot void warehouse receipt');
$missingActor=$void;unset($missingActor['payload']['operator_id']);
vrSame(1,SvAmazonReturnProjector::projectFrom($case,[$refund,$receipt,$missingActor])['quantity_received'],'unattributed correction cannot void receipt');
$beforeReceipt=$void;$beforeReceipt['occurred_at']='2026-11-10 00:00:00';
vrSame(1,SvAmazonReturnProjector::projectFrom($case,[$refund,$receipt,$beforeReceipt])['quantity_received'],'correction earlier than intake ignored');
$otherReceipt=$receipt;$otherReceipt['id']=1268000;$otherReceipt['source_event_id']='ef1830a9-0f6d-4678-95ca-d20bdba3bcec';$otherReceipt['occurred_at']='2026-11-11 03:00:00';
$newReceipt=SvAmazonReturnProjector::projectFrom($case,[$refund,$receipt,$void,$otherReceipt]);
vrSame(1,$newReceipt['quantity_received'],'later legitimate physical receipt not voided');
vrSame('RECEIVED_OK',$newReceipt['physical_status'],'later physical receipt is valid');

if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}
echo "warehouse-receipt-void-test: OK\n";
