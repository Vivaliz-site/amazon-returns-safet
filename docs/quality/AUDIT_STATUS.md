# Estado da Auditoria

**Status corrente:** NÃO APTO — auditoria extrema V5 em andamento em 2026-09-24.

O SHA atualmente implantado é `069fdf712498a5eca81870eeaeee6a4d4a4396ef`. A evidência live de 2026-09-24 mostra health `DEGRADED`, com falha de Gmail OAuth/refund reconciliation e outbox SAFE-T em `UI_DRIFT: SAFE_T_SUBREASON_OPTION_MISSING`. A certificação histórica abaixo não cobre este SHA nem este estado operacional e não pode ser reutilizada como prova de aptidão corrente.

## Última auditoria historicamente válida
- Data: 2026-09-19.
- Commit/SHA auditado e implantado: `0c37b423eec3a67f2b00f1a52e811537edafa274`.
- Release: `/home/ubuntu/amazon-returns-deploy/releases/20260919T054547Z-0c37b423eec3`.
- Veredito: **APTO**.
- Confiança: muito alta.
- Stop-the-line: nenhum blocker interno aberto na conclusão desta auditoria.

## Evidência fresca
- `Amazon Returns CI` do SHA implantado concluiu com sucesso.
- Gate completo executado dentro do próprio release terminou com `FINAL_RELEASE_GATE_OK 2026-09-19T12:21:13Z`.
- Serviços críticos ativos: `amazon-returns-safet.service`, `amazon-returns-deploy.timer`, `amazon-returns-seller-central-browser.timer` e `amazon-returns-olist-erp-browser.service`.
- Diagnóstico temporário `seller-central-diag2.service` permanece inativo.
- Banco live: 347 casos; 0 dead letters; 0 `PROCESSING`; 0 workflows ERP em status incompleto; 0 colisões de `support_case_id` entre pedidos.
- Outbox live sem pendências: somente `SUCCEEDED` e `SUPERSEDED`.
- Health canônico retornou `status=OK`, `blockers=[]`, `manual_reviews_open=0`, `pending_outbox=0`, `dead_letters=0`, `processing_jobs=0`, `erp_sales_return_incomplete=0`.
- ERP: 119 devoluções em `RETURN_CREATED_WAITING_INVOICE`; a mais antiga foi criada em 2026-09-13 e todas foram rechecadas entre 2026-09-18 22:58 UTC e 2026-09-19 05:49 UTC. Cursor operacional ERP: `status=OK`.
- Seller Central live em 2026-09-19: `AUTHENTICATED`, search/detail HTTP 200 e `SEARCH_AND_DETAIL_CONTRACT_OK`, sem `UI_DRIFT`, `UNHANDLED` ou mismatch no ciclo final.
- O worker Seller Support do release atual tem SHA-256 idêntico ao worker já validado no ciclo real pós-correção.
- Prova live pós-fix: outbox `342171 SELLER_SUPPORT_OPEN SUCCEEDED` às 11:05:01 UTC, caso 13248 / pedido `701-0518683-6584219`, read-back autoritativo `external_id=22144885901`; evento reconciliado como `ALREADY_EXISTS` / `SUPPORT_RETRY_RECONCILED_TERMINAL_CASE`, sem nova submissão e sem duplicidade.
- O read imediatamente posterior `659928 SELLER_SUPPORT_READ SUCCEEDED` observou o mesmo case ID `22144885901` como `RESOLVED`.
- Nenhum PR permanece aberto após o fechamento do #280, identificado como fixture efêmero de piloto já supersedido.
- Worktrees históricas foram inventariadas. As duas worktrees sujas continham mudanças já presentes no `main` atual; branches antigos com patches equivalentes/supersedidos não representam dívida funcional do release.

## Matriz de operação e paridade
| Operação | Evidência | Resultado |
| --- | --- | --- |
| SHA -> deploy | `.release-sha` e symlink current apontam para `0c37b423...` | PASS |
| CI / gate do release | CI remoto verde + `FINAL_RELEASE_GATE_OK` | PASS |
| daemon / scheduler / health | serviços ativos, health canônico sem blockers | PASS |
| Seller Central auth/search/detail | sessão autenticada + HTTP 200 + contrato live | PASS |
| `SELLER_SUPPORT_OPEN` | job real 342171 + external ID + read-back 659928 | PASS |
| SAFE-T / Seller Support outbox | sem PENDING/PROCESSING/dead letters | PASS |
| ERP devoluções | 0 incompletas; 119 aguardando NF sob recheck recente | PASS |
| idempotência Seller Support | 0 support_case_id compartilhado entre pedidos | PASS |
| auditoria temporária | serviços diagnósticos inativos | PASS |
| PRs abertos | 0 | PASS |

## Encerramento dos blockers anteriores
### Seller Support `UI_DRIFT`
Encerrado. O defeito anterior foi seguido por reconciliação real no mesmo caminho de negócio, com ID externo autoritativo e leitura subsequente do caso. O ciclo final do release atual também passou auth/search/detail sem recorrência.

### Estado live de filas
Encerrado. A leitura direta do banco foi executada com privilégio read-only operacional suficiente: 0 `PROCESSING`, 0 dead letters, 0 pendências e 0 workflows ERP incompletos.

### ERP false-green
Encerrado para o estado atual. O health não classifica as 119 devoluções já criadas aguardando NF como falha de criação; elas permanecem sob reconciliação e foram rechecadas recentemente. Qualquer workflow realmente incompleto volta a bloquear o health.

### Worktrees/branches históricos
Inventariados. Não há alteração suja exclusiva que precise ser resgatada para o release atual. O inventário permanece histórico; remoção física de worktrees não é requisito funcional e não deve apagar evidência sem necessidade.

## Risco residual
Baixo e operacional. Estados externos legítimos continuam existindo — por exemplo, devoluções ERP aguardando emissão/associação de NF e casos Amazon já resolvidos/terminais — mas estão representados explicitamente, sob recheck e sem falso-verde de fila ou workflow incompleto.

## Regra de validade
Esta certificação cobre exatamente o SHA `0c37b423eec3a67f2b00f1a52e811537edafa274` e o runtime observado em 2026-09-19. Mudanças em bridges, write profile, OAuth, políticas, workers, esquema, filas, regras de decisão ou integrações externas críticas exigem nova auditoria contraditória antes de reutilizar o veredito `APTO`.
