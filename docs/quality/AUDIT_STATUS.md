# Estado da Auditoria

**Status:** NÃO APTO

Nova auditoria formal executada em 2026-09-16 segundo `EXTREME_AUDIT_PROTOCOL.md`, `AUDIT_RUNTIME_PARITY_V1.md`, matriz de transições/dados históricos e overlay específico do projeto.

## Última auditoria válida
- Data: 2026-09-16.
- Commit/SHA auditado: `6edd00cfb41b3e92722fd00404829fa2b66587b0`.
- Release observado: `/home/ubuntu/amazon-returns-deploy/current -> releases/20260916T142606Z-6edd00cfb41b`.
- Veredito: **NÃO APTO PARA CERTIFICAÇÃO COMPLETA**.
- Confiança: muito alta.
- Stop-the-line: embora SHA e deploy estejam alinhados e o pós-deploy Seller Central tenha recuperado auth/contrato de lookup, o caminho crítico `SELLER_SUPPORT_OPEN` que falhava com `UI_DRIFT` não foi reexecutado ponta a ponta no novo release e a verificação direta do banco/outbox continua bloqueada por privilégio.

## Evidência fresca desta auditoria
- `Amazon Returns CI` do SHA `6edd00cf...` concluiu com sucesso.
- O release produtivo corresponde exatamente ao SHA auditado.
- `amazon-returns-safet.service` foi observado ativo, com working directory no symlink de release atual.
- Antes do deploy do `6edd00cf...`, ciclos Seller Central autenticavam e passavam no `support_lookup_probe`, mas vários jobs `SELLER_SUPPORT_OPEN` terminavam `UI_DRIFT`, `SUPPORT_CASE_LOOKUP_UNAVAILABLE`, `DETAIL_LOOKUP_FAILED` ou retry de reconciliação.
- O SHA `6edd00cf...` adicionou pacing/retry para `ViewCase` e testes focais para 429/rate limit.
- Após o deploy, o ciclo de 14:28 UTC terminou `success`: `AUTHENTICATED` e `SEARCH_AND_DETAIL_CONTRACT_OK` com HTTP 200 para search/detail. Esse ciclo não processou um job `SELLER_SUPPORT_OPEN`, portanto a correção ainda não está validada no caminho de negócio que escapava.
- `amazon-returns-prod-readonly-audit.service` e `amazon-returns-prod-healthcheck.service` registram último resultado `success`.
- A tentativa de executar a verificação direta tenant/outbox com `sudo` foi bloqueada pelo `NoNewPrivileges`; acesso MySQL como usuário `ubuntu` também foi negado. Logo contagens atuais de casos, `PROCESSING`, outbox e dead letters não foram novamente comprovadas diretamente.

## Matriz de operação e paridade
| Operação | Local/CI | Runtime no mesmo SHA | Resultado |
| --- | --- | --- | --- |
| daemon/read loop | CI/testes | serviço ativo no `6edd00cf...` | PASS operacional |
| Seller Central auth/search/detail | testes + contrato | pós-deploy 14:28: HTTP 200/OK | PASS |
| `SELLER_SUPPORT_OPEN` | testes/worker | falhava no release anterior; não houve job real pós-fix | **NÃO REVALIDADO** |
| SAFE-T submit/appeal | cobertura e flags do sistema | não disparado nesta auditoria para evitar efeito real | NÃO REVALIDADO E2E |
| email review/reply | implementação/flags | não disparado | NÃO REVALIDADO E2E |
| Finances/reconciliação | testes/rotinas | estado live direto não lido | NÃO COMPROVADO NESTA EXECUÇÃO |
| outbox/idempotência/dead letters | cobertura de código | acesso DB direto bloqueado | NÃO REVALIDADO LIVE |
| SHA -> deploy | n/a | `6edd00cf...` == release | **PASS** |

## Achados
### P1 — correção de Seller Support ainda não provada no job de negócio
**COMPROVADO como dívida de evidência.** O probe read-only pós-deploy está verde, mas o defeito anterior ocorria durante jobs `SELLER_SUPPORT_OPEN`. `AUDIT_RUNTIME_PARITY_V1` não permite substituir a operação crítica por um probe representativo.

### P1 — estado live de outbox/dead letters/PROCESSING não revalidado diretamente
A verificação preparada é read-only, porém exige root. O ambiente de execução impôs `NoNewPrivileges`; não foi possível provar novamente zero stale `PROCESSING`, dead letters e pendências sem próximo passo.

### P2 — histórico recente contém repetidos `UI_DRIFT`
Mesmo com o novo fix, a classe de falha deve permanecer aberta até um ciclo com job equivalente demonstrar `efeito -> confirmação/read-back -> reconciliação`, não apenas search/detail.

## Risco residual
Moderado/alto. A proveniência está correta e a camada de lookup melhorou, mas uma ação crítica que falhou horas antes ainda carece de prova produção-equivalente pós-correção, e o estado de filas não pôde ser confrontado diretamente.

## Saída do NO-GO
1. observar/processar de forma autorizada um próximo `SELLER_SUPPORT_OPEN` elegível no SHA `6edd00cf...` e confirmar read-back/reconciliação sem duplicidade;
2. executar `verify-live-tenant-foundation.sh` em contexto root autorizado/read-only e registrar casos/outbox/dead letters/stale processing;
3. revalidar SAFE-T/Finances/email pelas interfaces canônicas sem provocar ação indevida;
4. auditar casos ativos dentro do limite operacional e classes históricas relevantes;
5. executar reauditoria contraditória antes de mudar para `APTO`.

## Regra de validade
Esta auditoria cobre `6edd00cfb41b3e92722fd00404829fa2b66587b0` e o runtime observado em 2026-09-16. Mudança em bridges, write profile, OAuth, políticas, workers, filas ou regras de decisão exige reauditoria.

## Achado de código — 2026-09-18 — reembolso adicional sem devolução/NF (WRITE-07)
- **Classe:** invariante de negócio (`todo reembolso deve possuir devolução gerada ou NF de devolução`) violável silenciosamente.
- **Evidência (código, não live):** `amazon_return_erp_sales_returns` tem uma linha por pedido (`UNIQUE KEY` em `amazon_order_id`); `SvAmazonErpSalesReturnService::reconcileOrder()` retornava a workflow existente imediatamente quando o status já era `RETURN_CREATED_WAITING_INVOICE`/`RETURN_INVOICE_EXISTS`, sem comparar a quantidade reembolsada atual com a quantidade efetivamente coberta pela devolução/NF já criada. Um segundo reembolso no mesmo pedido após a primeira devolução nunca era reconciliado, e `countIncomplete()` não detectava o caso porque a linha já estava em um status "completo".
- **Correção:** nova coluna `reconciled_quantity_refunded` (migração condicional em `SvAmazonReturnsSchema::ensure()`); o serviço agora registra a quantidade reconciliada a cada criação/vinculação bem-sucedida e, ao encontrar quantidade reembolsada maior que a reconciliada em um workflow já "completo", bloqueia com `ERP_SALES_RETURN_ADDITIONAL_QUANTITY_PENDING` em vez de aceitar silenciosamente — nunca tenta uma escrita adicional às cegas, pois a capacidade de emenda/segundo documento no Olist/Tiny não está verificada.
- **Guarda permanente:** `tests/erp-sales-return-workflow-test.php` (cenário de quantidade crescente) e `tests/operational-health-test.php`/`tests/erp-health-blocker-test.php`/`tests/erp-health-live-state-test.php` para a classe de health-false-green relacionada corrigida na mesma auditoria.
- **Escopo desta entrada:** auditoria estática de código nesta sessão, sem acesso à VM de produção (não solicitado/autorizado nesta rodada). Não altera o veredito `NÃO APTO` abaixo, que continua exigindo a validação live descrita na Saída do NO-GO; esta correção deve ser incluída na próxima auditoria live antes de qualquer reclassificação para `APTO`.

## AUDIT_ESCAPE — 2026-09-17 — quiescência do Seller Central
- **Classe:** deploy/runtime parity; worker com drain contínuo mantendo `PROCESSING` vivo durante a janela de quiescência.
- **Evidência live:** o auto-deploy abortou com `worker_quiesce_timeout=180s`; o Seller Central concluiu um job longo e reivindicou imediatamente os jobs seguintes, sem janela `PROCESSING=0`.
- **Causa funcional:** o deploy parava o timer, mas o oneshot já ativo continuava em `--drain` e podia reivindicar novo trabalho.
- **Causa do falso negativo:** os testes simulavam corrida antes/depois do freeze, mas não cobriam um worker externo que terminava um job e reivindicava outro durante a espera.
- **Correção sistêmica:** marcador de quiescência compartilhado; workers de write e read terminam o job corrente e não puxam o próximo.
- **Guarda permanente:** `seller-central-quiesce-drain-test.php`, incluindo ausência de pull de rede sob marcador, mais regressões de quiescência existentes.
- **Certificação:** permanece NÃO APTO até merge, auto-deploy no mesmo SHA, prova live de quiescência e revalidação dos writes/readbacks Seller Support e ERP.
