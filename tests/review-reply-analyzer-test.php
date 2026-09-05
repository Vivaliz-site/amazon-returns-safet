<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/amazon-returns/ReviewReplyAnalyzer.php';

function rrSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) throw new RuntimeException($message . '\nExpected: ' . var_export($expected,true) . '\nActual: ' . var_export($actual,true));
}
$analyzer = new SvAmazonSafeTReviewReplyAnalyzer();
$base = ['from'=>'Safe-T Review <Safe-T-Review@amazon.com>','subject'=>'Re: Revisão SAFE-T 12472-25597-6629839','body_text'=>''];

$msg=$base; $msg['body_text']='Após análise, sua solicitação foi aprovada e o reembolso será processado.';
rrSame('APPROVED',$analyzer->analyze($msg,[])['outcome'],'Approval email must route to credit monitoring.');
$msg=$base; $msg['body_text']='Para continuar a análise, envie o comprovante de rastreio e fotos do item.';
rrSame('INFO_REQUESTED',$analyzer->analyze($msg,['requested_evidence_available'=>true])['outcome'],'Specific evidence request must be recognized.');
$msg=$base; $msg['body_text']='Nenhuma ação é necessária agora. Você será reembolsado proativamente até 10 de setembro de 2026.';
rrSame('WAIT',$analyzer->analyze($msg,[])['outcome'],'Promised future reimbursement must wait.');
$msg=$base; $msg['body_text']='Após análise manual, negamos a solicitação de reembolso.';
rrSame('DENIED_ACTIONABLE',$analyzer->analyze($msg,[])['outcome'],'Plain denial must be recognized but remain non-terminal without case context.');
$msg=$base; $msg['body_text']='Entendemos sua posição, mas reafirmamos nossa decisão. Não podemos fornecer mais detalhes e não responderemos a outras comunicações sobre esta reivindicação.';
rrSame('DENIED_FINAL',$analyzer->analyze($msg,['new_material_fact'=>false,'financial_inconsistency'=>false,'open_channel'=>false])['outcome'],'Explicit final denial without unresolved fact may close.');
rrSame('HUMAN_REVIEW',$analyzer->analyze($msg,['new_material_fact'=>false,'financial_inconsistency'=>false,'open_channel'=>false])['suggested_action'],'Terminal loss closure must remain gated without explicit approval.');
rrSame('CLOSED_LOSS',$analyzer->analyze($msg,['new_material_fact'=>false,'financial_inconsistency'=>false,'open_channel'=>false,'terminal_close_allowed'=>true])['suggested_action'],'Explicit terminal approval may authorize a documented loss closure.');
rrSame('DENIED_ACTIONABLE',$analyzer->analyze($msg,['new_material_fact'=>false,'financial_inconsistency'=>true,'open_channel'=>false])['outcome'],'Financial inconsistency prevents final closure.');
$msg=$base; $msg['body_text']='Analisamos sua solicitação. Obrigado por entrar em contato.';
rrSame('UNKNOWN_AMBIGUOUS',$analyzer->analyze($msg,[])['outcome'],'Ambiguous reply must fail closed.');

echo "review-reply-analyzer-test: OK\n";
