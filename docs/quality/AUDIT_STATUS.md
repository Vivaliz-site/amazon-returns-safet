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

## 2026-10-31 — #532: verificador de escrita falha fechado para env inacessivel

- RED: `scripts/write-profile-check.php --env-file=...` ignorava arquivo explicitamente inacessivel/ausente e gerava flags baseadas em defaults de producao; leitura pelo usuario `ubuntu` divergia do daemon `www-data`.
- Correcao SAFE proposta: exigir arquivo regular, legivel e validamente lido quando `--env-file` for fornecido; caso contrario retornar exit 2 e `WRITE_PROFILE_CHECK=FAIL reason=ENV_FILE_UNREADABLE`, sem imprimir URL, path, segredos ou flags. Nenhuma leitura de dados clinicos/financeiros ou operacao externa.
- Testes: RED sintetico mostrou resultado indevido; GREEN isolado com PHP lint e fixture de arquivo legivel/ilegivel e kill-switch; `tests/write-profile-test.php` cobre regressao de parametro vazio, arquivo ausente, diretorio e kill-switch. Gates oficiais e deploy ainda exigidos.
- P0 externo previamente contido na Marina com `AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH=1`, sete write gates false como `www-data`. Patch de diagnostico nao reabilita SAFE-T nem processa outbox, nao fecha elegibilidade/refunds/credito.
- Estado NAO_APTO ate checks exatos, merge/deploy canonicos e reconciliacao Seller Central/Financeiro.


## 2026-11-01 — #532: auto-deploy bloqueado por fixtures PHP executadas como root

- **RED real:** a PR #538 passou CI/governanca e main `5de68c831a3afc2379e82c0e869d149b76cdace9`. Timer oficial da Marina disparou 31/10 23:58:22 UTC e `amazon-returns-deploy.service` terminou com exit=1 antes da troca de `current` (continua na release `c76f9615397db9399c31bd54bd3736a86edbaa88`). O teste isolado `tests/email-executor-gates-test.php` falha como root em 3 cenarios `claimed=[]`; como `ubuntu`, passa. Nenhuma submissao externa nem outbox rearmada.
- **Causa raiz confirmada:** `provision-production.sh` roda todas fixtures PHP como root; `includes/Database.php` importa `config/bootstrap-env.php`, que como root le `shared/.env` contendo o **kill switch ON intencional** e o aplica corretamente a fixtures de perfil de escrita sintetico, invalidando as expectativas do teste. Nao desligar kill switch para corrigir teste.
- **GREEN focal do reparo:** `sudo -n runuser -u ubuntu -- env AMAZON_RETURNS_ENV_FILE=/dev/null php tests/email-executor-gates-test.php` no release candidato imutavel retornou `email-executor-gates-test: OK`. O provisionador passara a executar *todas* as fixtures PHP com usuario `ubuntu` e `AMAZON_RETURNS_ENV_FILE=/dev/null`, isolando secrets sem afetar daemon/service ou perfil de escrita. Contrato de regressao estatico em `tests/production-provision-test.php`.
- **Segundo RED preventivo:** `verify-live-tenant-foundation.sh` exigia as sete writes ON inclusive sob kill switch emergencial ON, portanto falharia depois do reparo de fixtures. Fix proposto: o checker publica somente `kill_switch_active` booleano efetivo; o verificador exige **sete false quando switch ON** e mantem **sete true quando OFF**, rejeitando JSON/flags ausentes ou invalidos (fail-closed). Emite `external_writes_contained=1` sem alegar aptidao financeira. Regressoes no checker e contrato de provisionamento; sem modificar env real nem rearmar SAFE-T.
- Necessarios CI exato, governanca, merge protegido, deploy canonico e validacao independente de checker + kill switch como `www-data`. Nao alterar `shared/.env`, flags, DB do usuario, outbox, credenciais ou qualquer claim. Estado: NAO_APTO.


## 2026-11-01 — #532: preservar modo executavel em scripts de deploy

- **RED live**: apos PR #540 integrada em main `b57c13885b57a4c5840cb0cfa98c59d31d31e792`, auto-deploy oficial das 00:08:22 UTC terminou `status=126/Permission denied`, sem trocar release ativo `c76f9615397db9399c31bd54bd3736a86edbaa88`; daemon preservado ativo e kill switch ON. `stat` no checkout auto-deploy confirmou `scripts/provision-production.sh=0644` e `scripts/verify-live-tenant-foundation.sh=0644`, enquanto `scripts/auto-deploy.sh=0755`.
- **Causa raiz interna**: o commit via Git Data API da PR #540 inadvertidamente redefiniu ambos os modos de arquivos shell de `100755` para `100644`. O wrapper invoca o provisionador diretamente; falta de bit executavel bloqueia execucao antes dos testes. Nao houve mudanca de config/SAFE-T.
- **Correção**: restaurar modos Git `100755` para ambos os scripts SEM alterar os bytes, com contrato RED/GREEN `is_executable` em `tests/production-provision-test.php`. Validar no CI e em `stat` do SHA publicado antes de declarar deploy. Permanecem 7 gates efetivos OFF sob kill switch; nao desligar containment para obter GREEN.
- NAO_APTO ate main CI, deploy canônico e readback independente do checker, tenant e filas.


## 2026-11-01 — #532: D+45 first SAFE-T opening; no later hardcoded first-attempt gate

- Base SHA: 5bbf846be081f9d867d16b88821ccdfc637043bc.
- Impacto: Owner's first official Seller Central eligibility/opening attempt is D+45 after confirmed Amazon refund for FBA Onsite and DBA. Both server and Node hardcoded later preflights previously postponed it. Replaced by D+45; live Amazon eligibility, tenant write flags, global kill switch, existing-claim checks, claim reason, readback ID, and idempotency remain mandatory. No production write profile or pending claim changed.
- Testes: RED previously observable from the original PHP/Node timestamp gates for September refunds and later-opening outbox. D+45 exact-boundary tests added for missing order date, malformed refund, conflicting evidence, unknown program, and UI eligibility-check ordering. New GREEN on PR SHA and affected CI required before merge; proposed test code is not execution evidence.
- Evidencias: SafeTExternalWindow.php, safe-t-external-window.mjs, BridgeService.php, seller-central-bridge-worker.mjs, backfill.php, regression tests and canonical D+45 runbook/AGENTS/MEMORIA. Production baseline is release 5bbf846b with kill switch ON and seven writes OFF. Cases #8803 and #1263 have PENDING outbox attempts=0 and future available_at from the obsolete guard.
- Pendencias: owner's draft-PR approval and personal merge as a compliance-related guard change; full CI, migration of old outbox deferrals by authorized tenant-scoped CAS, canonical deploy and UI evidence on published SHA. Never enable writes, bypass Amazon eligibility or resubmit duplicate claims by virtue of D+45 alone.
- Estado: NAO_APTO / AWAITING_OWNER_PR_APPROVAL; this row is not proof of production deploy, external acceptance, or recovered credit.


## 2026-11-02 — #532: recuperação segura de marcador de agendamento inválido no futuro

- Base / impacto: a função `SvAmazonReturnsRuntime::dueTasks()` considera um timestamp no futuro válido e deixa Gmail/SP-API/financeiro/reconciliação bloqueados até a data arbitrária. Contraprova de logs na Marina publicada `d0ed27ef...`: entre 26/10 e 02/11, **9.607 ciclos** de daemon registraram `due` sem Gmail, SP-API, financeiro, scheduler nem ERP, apesar da cadência contratada de 12h; houve tarefas de health/review/seller em separado. O arquivo de estado pertence a `root:www-data` com acesso negado ao operador `ubuntu`; o valor exato de cada timestamp NÃO foi lido, portanto a causa do ambiente ainda é hipótese, não diagnóstico conclusivo.
- Correção preventiva proposta: quando o marcador salvo estiver **mais de 300 s no futuro** (situação que o writer `taskScheduleMarker` não deve gerar), agendar o task para uma execução canônica, sem alterar diretamente o estado, saltar locks, manipular credenciais ou alterar write flags. Os marcadores atuais, passados e com desvio <=300 s preservam a cadência. O daemon persiste um marcador atual somente após executar o ciclo conforme fluxo original.
- Testes: regressões PHP para 11 tarefas com marcadores sintéticos distantes no futuro, tolerância de 300 s e manutenção do gatilho 12h. Necessário obter RED real antes da correção e GREEN nos testes direcionados + CI oficial no SHA exato e post-deploy no processo real. Nenhuma operação externa, SAFE-T, ERP ou credencial foi executada na preparação da PR.
- Segurança: kill switch de escritas continua ON, sete canais OFF, nenhuma alteração em outbox, casos, preço, timer, perfil ou serviço. A correção de relógio NÃO certifica elegibilidade D+45, crédito nem UI; se o serviço continuar omitindo as tarefas após deploy, investigar outros escritores/ownership sem contornar o controle de acesso do state file.
- Estado: `NAO_APTO` até integração, readback do runtime e validação E2E.
