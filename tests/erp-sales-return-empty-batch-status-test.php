<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/ErpSalesReturnTask.php';

// Regressao: um lote sem pedidos novos nao elimina pendencias ERP preexistentes.
function verificar(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        throw new RuntimeException($mensagem);
    }
}

$pendencias=[
    ['status'=>'BLOCKED', 'last_error_code'=>'ERP_ORIGINAL_SALE_NOT_FOUND'],
    ['status'=>'RETURN_CREATED_WAITING_INVOICE', 'last_error_code'=>null],
];
$resumo=SvAmazonErpSalesReturnTask::summarizeEmptyBatch(2, $pendencias, true, 3);
verificar($resumo['status']==='PARTIAL', 'Lote vazio nao pode ser OK com workflows incompletos.');
verificar($resumo['orders']===0, 'Nao inventar pedidos processados.');
verificar($resumo['incomplete_workflows']===2, 'Contagem de pendencias divergente.');
verificar($resumo['incomplete_workflows_detail']===$pendencias, 'Detalhes de auditoria perdidos.');
verificar($resumo['resumed']===true && $resumo['skipped_processed']===3, 'Metadados de retomada perdidos.');
verificar($resumo['resume_pending']===false, 'Lote vazio nao deve indicar quota pendente.');

$limpo=SvAmazonErpSalesReturnTask::summarizeEmptyBatch(0, [], false, 0);
verificar($limpo['status']==='OK', 'Sem pendencias o lote vazio deve permanecer OK.');
verificar($limpo['incomplete_workflows']===0, 'Nao inventar pendencias.');
verificar($limpo['write_enabled']===false, 'Nao ativar escrita externa.');
echo "PASS: lote ERP vazio preserva pendencias historicas e nao cria escrita.\n";
