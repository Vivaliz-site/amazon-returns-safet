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


## 2026-11-02 — #532: observabilidade sanitizada do agendador após PR #554

- Base e achado: o deploy da PR #554 foi confirmado na Marina, release SHA `2f74cd105d0822351f8d3fd6368275e1454ce782`, source blob `f8b44cf4b4bc3d7fa18e6fe886f35c81b5dad041`, daemon reiniciado 00:51:32 UTC e sete canais externos de escrita desativados. **O ciclo completado às 00:53:03 UTC ainda registrou apenas `due=['bootstrap']`.** Portanto a correção de marcador no futuro não provou resolução da inanição de Gmail/SP-API/financeiro. Houve 260 linhas sanitizadas de auditoria de decisões no ciclo, porém isso não equivale a execução das rotinas `due` nem novas SAFE-T aceitas.
- Intervenção técnica de diagnóstico: registrar no próprio JSON de status do daemon um mapa de **categorias fixas** calculado pela identidade de serviço já autorizada em `loadState()`: `MISSING_OR_INVALID`, `FUTURE_INVALID`, `CLOCK_SKEW`, `RECENT`, `DUE`. A extensão publica somente as 11 chaves conhecidas de cadência e suas categorias; não publica caminho, timestamp bruto, conteúdo do estado, segredo, credencial, hash ou nome de cliente. Não altera política, agendamento, cursor, lease, acesso, serviço, perfil, DB, envio nem write gates.
- Regressão proposta para cada categoria e para ausência de vazamento de datas; saída do daemon permanece com `due` e `results` existentes. Após validação RED/GREEN e auto-merge protegido, verificar apenas categorias no log normal do serviço para localizar se o estado é recente, futuro, ausente ou vencido. Diagnosticar o escritor responsável somente por vias autorizadas; não ler o arquivo protegido como `ubuntu` nem fazer sudo para contornar a negativa.
- Estado: `NAO_APTO` até causa-raiz real, remediação, deploy e E2E de autenticação/financeiro, com sete writes OFF.


## 2026-11-02 — #532: journals truncados omitiam ciclos reais; resumo limitado por ciclo

- **RED / revisao de evidencia:** analise independente do `journalctl` na VM Marina desde 26/10 encontrou 10.244 ciclos, sendo 9.687 JSON completos e 557 linhas de resultado com truncagem pelo journald em cerca de 49 KiB. A analise anterior descartava essas linhas e alegava falsamente que nao havia execucao. A leitura correta do prefixo `due` identificou 9 Gmail, 99 reconciliações Gmail/refund, 427 SP-API, 427 financial, 557 scheduler e 14 ERP. Nos resultados observáveis, Gmail retornou FAILED 9/9, reconciliacao FAILED 99/99, SP-API OK 412/PARTIAL 15, financial OK 36/SKIPPED 391. O code fix #554 de marcador futuro e preventivo, nao causa-raiz do P0.
- **Correcao de observabilidade:** adicionar uma linha `cycle_summary` independente, ANTES das linhas maiores de `decision_audit_chunk` e de resultado, com somente nomes de tarefas allowlisted, status de formato restrito, `due`, status geral e timestamp novo. Sem copiar erro livre, secrets, CPF, case/order ID, cabecalhos, mensagem ou payload interno; tamanho conservador inferior a 2 KiB mesmo com centenas de decisões. Preserva a linha detalhada atual para compatibilidade e o `cadence_diagnostics` existente sem ampliar acesso.
- **Prova necessaria:** RED pelo novo teste contra `daemon.php` anterior, GREEN em teste de segredo sintético, rejeição de chaves/status não permitidos, linha <=2 KiB e regressao de testes PHP pertinentes, CI exato, auto-merge protegido, deploy canônico e leitura do evento em produção. Não alterar tarefas, frequências, flags, RBAC, outbox ou credenciais.
- **Estado**: projeto permanece NAO_APTO; causas materiais atuais são falhas internas da ingestão Gmail/reembolsos e reconciliação ERP/financeiro, além de elegibilidade/credito oficial. Nenhum envio externo.

## 2026-11-06 — #532: priorizar readback de SAFE-T/support existentes sobre descoberta sem prova de ausencia

- **RED em runtime (somente leitura, MCP Marina):** a outbox do tenant/conexao 1/1 tinha 245 `SAFE_T_DISCOVERY PENDING`, 1 `PROCESSING`, 68 `SAFE_T_READ PENDING` e 12 `SELLER_SUPPORT_READ PENDING`. O `StatusBridgeService::pull()` usava um unico `claimBatch` ordenado por `available_at,id`, permitindo que buscas sem reivindicacao confirmada ocupassem o leitor antes do readback de claims existentes e seus prazos de resposta/recurso.
- **Causa confirmada:** o lookup nativo retorna `UI_DRIFT/SAFE_T_EXACT_SEARCH_ABSENCE_UNVERIFIED` (seguro e fail-closed) em vez de prova de inexistencia; reprocessamentos consumiam o ciclo antes de `SAFE_T_READ`. Contagem de triagem: 110 discovery pendentes associados a recebimento fisico confirmado por evento `WAREHOUSE/PHYSICAL_RECEIVED`; as buscas **nao** foram apagadas, mascaradas nem classificadas como ausencia externa.
- **Correcao tecnica P1:** reservar o primeiro `claimBatch(1)` para `SAFE_T_READ` e `SELLER_SUPPORT_READ`; somente quando nao houver leitura prioritária reivindicar `SAFE_T_DISCOVERY` com os mesmos controles de transacao, lease, escopo tenant/conexao e SKIP LOCKED existentes. Preserva a descoberta integral, o contrato de casos, os resultados `UI_DRIFT` e todos os controles de escrita; nenhum claim, suporte, ERP ou credito foi criado.
- **Prevenção:** novo teste `tests/safe-t-claim-read-priority-test.php` provou RED antes do patch (exit 255), GREEN apos o patch; testes direcionados de outbox, read scheduling e discovery 5/5 PASS, regressao adicional de 9 PHP + 5 Node 14/14 PASS no checkout isolado. O diff passou `git diff --check`.
- **Limite do veredito:** esta correção protege a prioridade de verificacao, **nao certifica** o Seller Central/E2E, a inexistencia de SAFE-T externa, o prazo da claim 64204-51742-6364222 nem o credito ao vendedor. O projeto permanece **P0/NAO_APTO** ate deploy/release e auditoria UI + Finances/warehouse + operador oficialmente habilitado, com idempotencia.

## 2026-11-06 — #532: UNKNOWN em leitura de claim SAFE-T nao comprova status externo

- **Achado de producao pos-deploy (#593):** o leitor oficial Seller Central processou com sucesso a `SAFE_T_READ` do caso #8803, claim `64204-51742-6364222`, em 06/11 06:34:40 UTC, mas gravou `SAFE_T_STATUS_OBSERVED.claim_status=UNKNOWN`, `appeal_deadline_at=null`, e marcou outbox `SUCCEEDED`. Havia mensagem Amazon de mudanca de status em 05/11, sem decisao nem prazo no email; logo `SUCCEEDED` e falso indicador de leitura conclusiva. A reivindicacao externa existe, nenhum novo claim deve ser criado.
- **RED isolado:** `tests/safe-t-unknown-readback-failclosed-test.php` falhou (exit 255) porque o handler aceitava `ACCEPTED/UNKNOWN` como reconciliado.
- **Correcao P1 sem alteracao de canais externos:** somente `SAFE_T_READ` com retorno `ACCEPTED` e `claim_status=UNKNOWN` mantem o job em `PENDING` para novo readback em uma hora, com `UI_DRIFT: SAFE_T_STATUS_UNRECOGNIZED` identificado no health; nao grava mais observacao desconhecida como comprovada, nao reclassifica para aprovado/negado e nao envia pedido, recurso, email, suporte nem credito. Respeita tenant, connection, idempotencia, lock/lease e o status anterior do caso; reintento so por worker canonico.
- **GREEN:** RED reproduzido antes do patch, 6 testes focados PASS, mais 13 testes de regressao PHP relevantes PASS; lint 2/2 e `git diff --check` aprovados. Pending real de #8803 registrado continua com status inconclusivo ate novo readback oficial.
- **Criterio de fechamento:** E2E em UI grafica autenticada da Amazon, readback literal de status e `Reply by/Appeal by`, Finances, warehouse e certificador V5 no mesmo SHA de producao; esta remediacao e de integridade de evidencia, **nao APTO global**.

## 2026-11-07 — SAFE-T STANDARD fail-closed D+0/D+128 — #532

- Fonte e RED: o `safeTExternalWindow` de `main=8ee1f540c5a395bbb2faaa2d4784f0d6a8afe404` permitia programas nao suportados: `STANDARD` D+0 e D+128 retornavam `allowed=true`, evitando inteiramente a janela D+45/D+90. A leitura da UI oficial de elegibilidade da Amazon e o kill switch sao controles adicionais, **nao justificam** liberar o preflight JS.
- Fix minimo: para qualquer programa diferente de `FBA_ONSITE` e `DELIVERY_BY_AMAZON`, `allowed:false`; `STANDARD`/`FBM` recebem `PROGRAM_UNSUPPORTED`; `UNKNOWN`/vazio `PROGRAM_UNVERIFIED`. Os programas suportados continuam usando D+45 primeira tentativa e bloqueio D+90 sem reenvio.
- Testes: regressao com 4 programas x 6 checkpoints (D+0,44,45,89,90,128), caso ausente, e testes historicos FBA Onsite/Delivery. RED com novo teste contra codigo `main` em checkout temporario, `STANDARD D+0 must fail closed`; GREEN no branch `b8abecc34386332dc0f5052f4ee3bf7842275a1e`, `node --check`, `node tests/safe-t-external-window.test.mjs`, suite `node --test tests/*.test.mjs` e PHP `tests/scoped-safe-t-pr3556-profile-test.php` no backend MCP (task `5c29c642-9388-436d-aeaf-2c45791cc6bd`, exit0). Testes sinteticos nao provam habilitacao de escrita, claim externa ou creditos.
- Controle externo: PR security/owner-approvals #3556 ja esta merged, mas o runbook `scoped-safe-t-pr3556.md` ainda declara `PREPARATION ONLY` e nenhum operador certificado `activate_scoped_safe_t_opening` com principal/lease/fencing. Portanto NAO mudar kill switch ou flags, NAO enviar SAFE-T. O case #8803 ja tem a claim externa `64204-51742-6364222` retirada; jamais abrir duplicata. A lista atual observada de oito casos sem SAFE-T inclui #42471, que esta FORA da allowlist do grant antigo e exige decisao P2 propria.
- Validacoes ainda obrigatorias: CI oficial e merge protegido, readback do commit no `main` e na release Marina imutavel, repetir preflight na versao efetivamente publicada, reconciliar Amazon/warehouse/Finances e elegibilidade por caso, checar prazo de recurso #8803 e suporte #13210. Estado geral: `P0/NAO_APTO` ate recuperacao ou bloqueio oficial comprovado, nao alegar abertura/creditos.


## 2026-11-07 — #532: scoped SAFE-T executor core (no production activation)

- **Root cause:** owner grants #3556 and #3673, write profiles and synthetic tests were present, but there was no certified execution boundary enforcing real-time owner provenance, one-use fencing, external proof, crash rollback and accepted claim readback together. Re-enabling a global switch would risk unintended external submissions.
- **Implemented:** `scripts/amazon-returns/scoped-safe-t-operator.mjs` enforces two immutable non-interchangeable canaries, expiration, exact one-action scope, the pre-submit D+45..89 guard, referrer/receipt/credit constraints, unknown initiator/existing claim suppression, official evidence callback contract, single-writer fencing, atomic one-use consumption and final readback; always verifies rollback and releases its lease. It requires a trusted, production-certified adapter and cannot write by itself.
- **RED/GREEN and regression:** directed Node suite 19/19 PASS on isolated Marina Git worktree SHA `0e8448ed8be5d2969eefe917ad98da7e66c0e90f`; `php tests/scoped-safe-t-pr3556-profile-test.php` plus #3673 included GREEN; git diff --check PASS, MCP task `6e2ee8bc-8de7-444b-b2ff-d1a5af7feb99` exit 0. Test covers expired grant, mixed canary, duplicate/UNKNOWN, stale evidence and no readback. Current new HEAD may change after documentation-only commits; rerun official CI on final SHA.
- **Certification gap:** no production adapter yet for authenticated GitHub review/merge evidence, canonical source attestations SP-API/warehouse/Seller Central, durable once-only lease, crash-safe rollback and existing-worker write isolation. No login, payment, claim, appeal, support, write-gate, service, timer, credential or production case changed. **NOT CERTIFIED / NO EXTERNAL WRITES** pending protected CI, deployment and real provider/E2E validation.


## 2026-11-07 — autenticação independente do grant humano SAFE-T (#532)

- **Causa da lacuna de certificação:** o core `activateScopedSafeTOpening` da PR #602 dependia de `adapter.verifyCanonicalOwnerGrant` sem verificador autenticado implementado; aceitar um `verifiedBy` fornecido por um caller não é autenticação de produção.
- **Correção independente e fail-closed:** novo `scripts/amazon-returns/scoped-safe-t-github-grant-verifier.mjs` consulta GitHub REST autenticado e valida PR real merged, HEAD SHA, último review `shopvivaliz-human` APPROVED no HEAD, blob exato do grant obtido no commit de merge, expiração exclusiva e todas as restrições aprovadas para #3556 e #3673. Resposta de login/timeout, falta de token, revisão revogada ou canary alterado resulta em DENY. Não envia SAFE-T, não escreve banco e não relaxa kill switch.
- **GREEN isolado na Marina:** `node --test tests/scoped-safe-t-github-grant-verifier.test.mjs` terminou 11/11 PASS, exit 0 (audit MCP `a4f05fc2-8049-4d88-9c15-0d01f15e39e0`); fixture HTTP é sintética, não prova instalação de token de leitura nem ativação efetiva. PR técnica sujeita aos checks oficiais.
- **Próximo bloqueio real:** provisionar credencial GitHub *somente leitura* pelo canal oficial no principal `amazon-returns-safet-service` e conectar o adaptador real ao core. Ainda faltam single-writer, one-use durável, crash rollback, SP-API/warehouse/Finances e Seller Central UI/readback. *NÃO APTO para escrita externa*. Os grants #3556/#3673 não autorizam bypass de controles, nem reivindicação duplicada #8803.

## 2026-11-07 — #532: SAFE-T executor rollback/quarantine contract hardening

- RED found during defensive review of the new inactive executor core: a `finally` block released the single-writer lease before checking whether the kill switch had actually returned to ON. A failure in rollback could admit another writer during unsafe state.
- Fix: if restore throws or the OFF-to-ON readback cannot be proven, the operator throws `KILL_SWITCH_RESTORE_UNVERIFIED` **without releasing its fenced lease**; only a verified-off global writes state permits `releaseSingleWriterLease`. This keeps an independent crash recovery/lease reconciliation mandatory.
- Prevention: negative regression for both failed readback and restore exception. No runtime activation, external claims, credentials, worker changes or flags. Operational certification remains **NOT APTO** without production adapter and live UI/Finances/warehouse proof.


## 2026-11-07 — #532 / atomico one-use ledger (INERTE)

- **Causa:** grants humanos #3556/#3673 tinham core/verificador GitHub, mas nenhum registro transacional reutilizavel para impedimento permanente de segunda execucao e controle de fencing. Reprocessamento apos crash ou resultado Amazon ambiguo permaneceria risco P0.
- **Correcao tecnica proposta:** `includes/amazon-returns/ScopedSafeTGrantConsumption.php` com reserva INSERT protegida por UNIQUE por tenant/conexao/grant, token auto-increment monotono, consumo once-only CAS e quarentena terminal. Nenhum mecanismo de reset ou reaproveitamento; grants restritos a #3556/#3673 e tenant/conexao 1/1. DDL InnoDB `deploy/sql/20261107-scoped-safe-t-grant-consumption.sql` fica **staged, nao aplicada automaticamente**.
- **Prevencao:** teste de SQLite descartavel `tests/scoped-safe-t-grant-consumption-test.php` verifica dupla reserva, dupla consumacao, fencing velho, grant e tenant alheios, reinicio e quarentena. Aguardam checks oficiais no HEAD final; nao declarar verde sem provas.
- **Escopo limitado:** a tabela one-use nao e lease global e nao faz a integracao de principal/rollback/Seller Central/Finances. Nenhum claim/support/pagamento, schema de producao, flag ou kill switch alterado por este codigo. APTO externo continua **NAO**.
