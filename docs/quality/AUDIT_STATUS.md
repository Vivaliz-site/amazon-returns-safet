# Estado da Auditoria

**Status corrente:** NÃO APTO — auditoria extrema V5 em andamento em 2026-09-24.

## 2026-11-12 — Auditoria V5 (UI real): lista de casos contradizia o resumo de decisões humanas

- **Achado (P2 / DEFECT / SAFE):** na UI real do release `2494429`, o resumo dizia "Nenhuma ação sua é necessária" e a aba Revisões tinha 0, mas a lista marcava 2 casos como "Precisa da sua decisão / Você" e o filtro "Precisa da minha atenção" retornava 2. Os dados live mostram duas revisões OPEN operacionais (`SELLER_SUPPORT_CHAT_READ_BACK_NOT_CONFIRMED`, `SUPPORT_CASE_NOT_REOPENABLE`), que não exigem decisão do proprietário.
- **Causa raiz:** o commit `6a23fc5` separou revisões humanas e operacionais no resumo e na API de revisões, mas `api/cases.php`, `CockpitFilters::acceptsDecision` e `operatorResponsibility` continuaram tratando qualquer revisão OPEN como decisão humana.
- **Classe de falha / busca sistêmica:** mesma regra de negócio aplicada em três superfícies com critérios diferentes. A fonte única passou a ser `SvAmazonReviewOperations::requiresManualDecision`, exposta como `requires_human_decision` na lista.
- **Correção/prevenção:** testes `cockpit-human-decision-filter-test.php` e `cockpit-human-decision-alignment.test.mjs` (RED antes, GREEN depois) e contrato em `cockpit-api-contract-test.php`. Nenhuma escrita externa nem controle novo.
- **Pendências:** deploy do SHA da correção, reauditoria visual da lista/filtro/resumo no release, demais jornadas, certifier oficial e reauditoria independente. Estado: NAO_APTO.

## 2026-11-12 — #532: cache dos assets e instruções do usuário de agente integrados

- **Achado:** a correção do rótulo `CLOSED_LOSS` e da URL da aba `Revisões` ficou inicialmente fora do `main`; mesmo após a integração, tags estáticas de assets permitiam JavaScript antigo em sessões com cache.
- **Correção integrada:** PR #667 levou a correção funcional; PR #668 (`827a175`) passou a gerar a versão dos assets a partir do hash SHA-256 do próprio arquivo e documentou o usuário canônico `returns-agent`, sem expor credenciais nem habilitar escritas externas. PR #670 (`8b2cc52`) alinhou o contrato do helper de revisões ao mesmo mecanismo.
- **Prevenção/regressão:** contratos Node/PHP do cockpit exigem URLs versionadas por conteúdo e rejeitam versões legadas; a reauditoria deve verificar HTML, conteúdo servido e jornada real em nova sessão.
- **Validação local desta rodada:** 9/9 testes focais do cockpit e contratos PHP relacionados passaram; a suíte Node no host Windows permaneceu 294/297, com as três falhas preexistentes de `seller-central-managed-lock` (2) e `seller-support-hill-expression` (1).
- **Pendências de aptidão:** deploy imutável do SHA `8b2cc52`, reauditoria visual publicada do #1263 e da aba `Revisões`, reconciliação do runtime e certifier oficial para o mesmo release. Nenhuma escrita Amazon foi executada.
- **Estado:** NAO_APTO até concluir os gates de deploy, pós-deploy e certificação.

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

## 2026-11-08 — #532 / Seller Central browser cycle timed out after 170 read-only jobs

- **RED em release real:** `amazon-returns-seller-central-browser.service` na Marina iniciou em 08/11 04:05:24 UTC, atingiu `Result=timeout` em 04:35:25 (30min) e reiniciou automaticamente. No mesmo ciclo o journal continha 68 SAFE_T_READ, 12 SELLER_SUPPORT_READ e 90 SAFE_T_DISCOVERY, as 90 sem prova de cobertura de lista (`SAFE_T_DISCOVERY_LIST_COVERAGE_UNVERIFIED`, 1 pagina). MCP de producao antes do reparo: audit `62989c95-592d-401d-8527-e754b7ede08b`. Nenhuma ausencia de claim foi afirmada nem escrita foi acionada.
- **Causa raiz:** os dois executores Node chamados pela rotina diaria faziam `--drain` sem limite temporal, com backlog maior do que `TimeoutStartSec=30min`. O restart automatico era efeito secundario, nao necessariamente falha de autenticacao.
- **Correcao:** preservar a rotina single-writer existente e adicionar um unico deadline absoluto de 20min ao shell, compartilhado pelos drains bridge/leitura/bridge; cada processo para cooperativamente ANTES de reclamar o proximo job, completa o job atual e preserva o resultado e o estado da fila. Invalidade do deadline falha fechado; sem novo timer, worker, renderer, runner ou alteracao de perfil/segredos/kill switch.
- **TDD/regressao:** RED 3/3 checks negativos no commit `be37b7559ff5ae3e674c38973c076bed12584975`, task MCP `1995860e-07b4-4923-a971-544befdafc4f`. GREEN dirigido no commit `aa2702b06217688db0ff05ef749b0e2096578926`, 3/3, task `ca3caa01-e25a-47fa-8770-ca3c4b806751` (exit0), e readback direto no HEAD posterior audit `d8314e06-f580-4260-8747-dc7f199f24b1` exit0. A suite ampliada de Node no checkout isolado HEAD `816a5066c3154d0ff18e232c56ce6f929a2cb9ef` registrou 205/205 PASS, 0 fails, 7.55s; o wrapper da task terminou com exit1 apesar de `FULL_NODE_EXIT=0`, de modo que a CI oficial continua como fonte necessaria da suite completa. Bash syntax, Node syntax e diff sem erros no checkout isolado.
- **Pendencias/gates:** nenhum deploy ou fechamento integral presumido; exigir CI oficial green no SHA exato, merge main protegido, release imutavel Marina ativa e um ciclo cronologico completo retornando `Result=success` sem restart por timeout. A UI Drift de descoberta externa e a inexistencia de operador SAFE-T one-use ainda sao bloqueios independentes; #8803 tem claim preexistente, #42471 fora do grant. `AUDIT_VERDICT=NAO_APTO` para submissions.

## 2026-11-08 — #532: Amazon Returns DB runtime-context false outage diagnosis

- **Evidence on production Marina:** `api/health.php` source sha `18b88cdfd0dac7db449263a7e7fd25129eb7a957` calls `amazon_returns_require_pdo()` then resolves tenant via the current registry and persistence. On 08/11 at 05:43 UTC, the real HTTPS endpoint returned HTTP 200, `status=DEGRADED`, 17 blockers, **none database-related**; the MySQL and Amazon daemon units were active. Runtime release `205496eb207acd56074dc02e9cd559847ebd3428`, MCP audit `adaaf6c1-78e3-4969-999b-7700f6ad8fbd`. This proves the application's DB connection path, NOT global SAFE-T readiness.
- **Root cause of former false alarm:** ad hoc unconfigured `ubuntu` CLI cannot read the protected `root:www-data` application environment, so `amazon_returns_require_pdo()` under that principal returned generic unavailable without attempting a PDO connection; prior sudo noninteractive refusal was preserved, not bypassed. Existing service runs as `www-data`.
- **Fix:** `includes/Database.php` now logs a private single `DB_RUNTIME_CONTEXT_MISSING` for absent configured DB user/name **before any PDO attempt**, distinct from the existing genuine `connection unavailable` catch. Public exceptions remain generic; no credentials, permissions, configuration, service unit or external calls changed. New runbook documents the authorized health endpoint and service-principal case SELECT procedure.
- **RED/GREEN evidence:** isolated new test failed before code fix (backend MCP task `7d66515e-1ebf-42d9-becd-278348f74392`, exit 255). After patch SHA `23cde344e26df06b24dd78cf7cde863efcbd10e8`, test plus 5 operational/public-health contract tests, 2 PHP linters and git diff check passed (backend task `7906ea4e-4f9d-43d2-aa2d-0ab779898404`, exit 0). Require GitHub official CI and immutable Marina release parity before claiming deployed.
- **Remaining limits:** individual case SELECT still requires genuinely authorized service execution; public health endpoint deliberately exposes no order IDs or financial data. SAFE-T grant #3556 expires 09/11 and #8803 already has an external claim, #42471 not in #3556. P0 remains `NAO_APTO` for external submissions; kill switch ON, seven flags OFF.


## 2026-11-08 — #532: legacy scoped SAFE-T D+45 carrier-only intake must not postpone provider check

- Base SHA: `3e209039aaaaf8680867ca0c4fb464b2cc561ac6` (GitHub main confirmado via ls-remote da Marina em 2026-11-08 14:01 UTC).
- Impacto e RED real: o operador one-use `scoped-safe-t-operator.mjs` (grants humanos #3556/#3673) exigia `physical_status=NOT_RECEIVED` ja no preflight local; um retorno `IN_TRANSIT` com `quantity_received=0` e estorno confirmado aos D+45 era incorretamente descartado com `WAREHOUSE_RECEIPT_NOT_CLEARED`, sem chegar a verificacao independente `verifyWarehouseNonreceipt`. RED na release Marina main (MCP audit `9081339f-77c5-4e73-82b8-d60d7cb818a6`, sem dados reais de pedido).
- Correcao tecnica limitada: aceitar no **preflight local somente** `NOT_RECEIVED`, `IN_TRANSIT` ou `CARRIER_DELIVERED_PENDING_PHYSICAL`, sempre com `quantity_received===0`. Status desconhecido, recepcao fisica ou quantidade recebida continuam bloqueados. NENHUMA abertura decorre do status da transportadora: prova **independente** Warehouse, debito Finances, iniciador Amazon, elegibilidade Seller Central, ausencia de claim/support, grant pessoal, lease/fencing e readback externos permanecem obrigatorios antes de qualquer escrita.
- Testes: teste principal ampliado com 2 cenarios positivos locais de transportadora e 5 negativos (UNKNOWN, recebimentos e quantidade>0), verificacao de bloqueio com callback externo falso, de rechecagem e de gates; primeira tentativa identificou erro de sintaxe na fixture e foi corrigida antes de GREEN. **22/22 Node testes PASS**, 2/2 `node --check`, `git diff --check` PASS em clone isolado, HEAD `6b9597829ce5dfe7c22f64d027b9e7beba9624b6`, task MCP `c5625b6a-4876-4ade-8ea5-5dabb5664892`, exit0. CI GitHub oficial e merge protegido ainda necessarios.
- Evidencias e seguranca: codigo e regressao em `scripts/amazon-returns/scoped-safe-t-operator.mjs` / `tests/scoped-safe-t-operator.test.mjs`. Somente clone descartavel e import-only core; **nao** cria adapter, executor, worker, timer, flag, token ou claim. A release Marina atual, no momento da prova, continua `3e209...`, daemon ativo, kill switch ON, escritas OFF. #8803 ja possui SAFE-T externa, #42471 nao integra #3556; D+90 e respostas oficiais da Amazon sao decisivos.
- Pendencias: CI e deploy imutavel, validador exato SHA, operador efetivo P2 certificado com principal restrito, ledger/quotas/lease/watchdog real, evidencias caso a caso na UI autentica e Financeiro. Sem submissao externa e sem restore financeiro; estado **NAO_APTO**.


## 2026-11-08 — #532: SAFE-T one-use legacy treats missing or negative financial credit as validated zero

- Base SHA: `faf486ffc88dfcb05a147702ac2921a4ae01e151` (active Marina immutable release / official GitHub main when RED was reproduced).
- Impacto / RED: `assessLocalCase` used `Number(reconciled_credit_amount)` without requiring a real value. JavaScript converts `null`, empty string and whitespace to 0 and accepted negative credits, so those unverified Finances snapshots passed local preflight as a genuine outstanding seller debit. In the live Marina release, synthetic case 1263 with credits `null`, `''`, spaces and `-1.00` incorrectly produced `PASS_LOCAL`; expected `OUTSTANDING_DEBIT_UNVERIFIED` (native MCP audit `9b15c8a7-c251-4a48-a1f2-af0e39262139`, task `47a9eb75-0a3c-4240-a8c1-690a951d24c9`, exit0; **no real claim/order used**).
- Causa raiz: implicit numeric coercion conflated absent/invalid financial evidence with an explicitly reconciled `0.00` credit. This is a P1/P0-protective safety fix, not a change to D+45/D+90, grants, provider policy or claim permissions.
- Correção: validate explicit finite decimal numeric values for refund and credit, reject missing/blank/non-decimal/negative credit and nonpositive refund, keep legitimate numeric/string zero credits eligible for independent Finances verification. Regressão negative + positive for both fields and full-flow no-consume/no-submit.
- Testes: RED confirmed on published pre-fix release; new Node regression plus established tests, GitHub official CI and post-merge immutable release parity required on final HEAD. Do not call passing synthetic tests a proof of Amazon eligibility.
- Evidencias: `scripts/amazon-returns/scoped-safe-t-operator.mjs`, `tests/scoped-safe-t-operator.test.mjs`, native MCP task/audit above, issue #532. No secrets, order IDs, real customer data, DB mutations, external calls, workers or timers introduced.
- Pendencias: official CI, protected merge, deploy immutable SHA, runtime test/readback; separately certified production adapter, principal, lease/fencing, one-use ledger, official Seller Central/Finances/WAREHOUSE proofs, Amazon claim readback. Kill switch ON, seven writes OFF, case 8803 existing claim never duplicated; #42471 not silently added to grant #3556.
- Estado: NAO_APTO


## 2026-11-08 — #532: sibling D+45 automatic P2 core requires explicit decimal Finances fields

- Base SHA: `00e6d3f5fb51d9e968b20d7518892f14f41bc7b1` (PR #617 merge/main, after scoped legacy credit guard fix).
- Impacto e RED no main real: `assessD45Candidate` in `d45-safe-t-auto-activation.mjs` rejected null credits but accepted empty string, whitespace and hex string `0x0` via implicit `Number()` coercion as verified zero. Synthetic read-only RED on the backend exact checkout/main with case fixture 1263, task `043e4ca4-4976-40dd-9ea7-0e6306816bad` exit0, no live case or Amazon provider called.
- Causa raiz: the second, separately granted D+45 core repeated the numeric-coercion ambiguity fixed by #617 in the one-use core. The audit of sister paths is required to prevent recurrence after activation.
- Correcao: require explicit finite decimal monetary evidence for refund and reconciled credits; deny blank, whitespace, hexadecimal, falses and nonsensical values, keep true numeric and decimal `0.00` behavior. Add negative/positive regression and full mocked activation proof: rejected candidate never reserves/submits, does not call Amazon official eligibility, and still restores kill switch/releases fenced lease. No adapter/daemon/flag/worker/claim activated.
- Testes: prior main synthetic RED; new focused Node suite, syntax, GitHub official CI, protected merge and immutable Marina release parity required before deployment certification. No live eligibility asserted by mock tests.
- Evidencias: `scripts/amazon-returns/d45-safe-t-auto-activation.mjs`; `tests/d45-safe-t-auto-activation.test.mjs`; GitHub PR and MCP task above, issue #532. Only code/test/ledger append.
- Pendencias: official checks, merge and runtime release GREEN, certified production adapter with principal/lease/watchdog, official Seller Central + Warehouse + Finances readback and safe provider claim ID. D+45 first check, D+90 terminal, #8803 excluded, seven writes OFF.
- Estado: NAO_APTO


## 2026-11-08 — #532: D+45 core rejects malformed physical status without delaying carrier/UNKNOWN eligibility check

- Base SHA: `0d3ebb1f046d5b6039adcf95c187b26f9f4c2646` (official Amazon Returns main and immutable Marina release at 18:26 UTC).
- Impacto: a funcao `assessD45Candidate` tratava como aptos ao preflight local estados fisicos `null`, vazios e desconhecidos como `SUSPECTED_RECEIVED` quando `quantity_received=0`. RED observado na propria release Marina por fixture sintetica de #1263, task MCP `fe601e92-74e2-4e01-b396-9b26ea2894d7`, exit0. Nenhum dado real de pedido, SAFE-T ou servico externo acessado.
- Causa raiz: blacklist incompleta de dois estados de recebimento fisico, sem politica explicita para valores malformados. O status `UNKNOWN` documentado e o transporte `RETURNING_TO_SELLER` devem continuar elegiveis para a primeira **checagem** D+45 sob verificacao WAREHOUSE independente; nunca implicam recebimento ausente certificado nem autorizam submissao.
- Correcao: allowlist local somente `NOT_RECEIVED`, `IN_TRANSIT`, `CARRIER_DELIVERED_PENDING_PHYSICAL`, `RETURNING_TO_SELLER` e `UNKNOWN`; rejeicao fail-closed de `null`, strings vazias, valores de recebimento inesperados ou quantidade recebida positiva. Mantidas callback independente `verifyWarehouseNonreceipt`, iniciador Amazon, financeiro, prova oficial Seller Central, deduplicacao, grant P2, lease/fencing, rollback de kill switch e limite D+90.
- Testes: a primeira versao da correcao revelou conflito com regressao existente que exige permitir os estados legitimos `RETURNING_TO_SELLER` e `UNKNOWN`; ajustados codigo e teste para preserva-los sem permitir estados malformados. GREEN final **42/42** casos Node (`tests/d45-safe-t-auto-activation.test.mjs` e `tests/scoped-safe-t-operator.test.mjs`), sintaxe `node --check` 2/2 no checkout descartavel isolado da Marina, task MCP `a2f1ee2d-886e-4d0e-bf86-9a65e70eb672`, exit0, HEAD inicial `e868ff9ea41e9be3bb0f121d6b87b8da19c7984a`. Required CI, merge protegido e teste no release imutavel final ainda devem ser validados.
- Evidencias: codigo `scripts/amazon-returns/d45-safe-t-auto-activation.mjs`, regressao `tests/d45-safe-t-auto-activation.test.mjs`, task RED e task GREEN acima, issue #532. Sem criacao de adaptador, runner, workflow, timer, flag de escrita, claim, suporte, reembolso ou alteracao de dados de clientes. `#8803` ja tem SAFE-T externa; `#42471` nao entra no grant #3556.
- Pendencias: CI GitHub no HEAD final, merge protegido, readback do SHA implantado e teste de regressao na release; adaptador produtivo certificado ausente, SP-API/WAREHOUSE/Finances/Seller Central e readback Amazon reais ainda nao comprovados por caso. Kill switch permanece ON; sete write gates OFF; `#532 NAO_APTO` para submissao.
- Estado: NAO_APTO

## 2026-11-08 — #532: search input cannot prove SAFE-T result identity

- Base SHA: `67f177e5b19d604f93f0130777ba961714ce32b0`.
- Impacto: a leitura read-only de elegibilidade usava o valor digitado no campo de busca como prova de que o checkbox de resultado pertencia ao pedido; uma pagina com resultado de pedido diferente e input refletindo o ID consultado podia gerar `orderMatched=true` e classificacao `ELIGIBLE`. Risco de falsa elegibilidade e submissao no pedido errado, mesmo antes de readback externo.
- Causa raiz: identidade derivada de `inputs.some(i=>i.value===id)` em vez de evidência visível do resultado no mesmo documento. O texto do corpo permanece necessario e a ausência da identidade continua `UI_DRIFT`.
- Correcao: remover unicamente a inferencia por echo de input da captura de elegibilidade, sem criar selecao automatica, submissao, alteracao de flag, worker, tenant, grant ou credencial. A elegibilidade oficial e deduplicacao independentes seguem obrigatorias.
- Testes: RED local sintetico pré-fix 1/2 (a reproduzir no checkout original) e GREEN focal 2/2 apos o fix; regression integrada em `tests/safe-t-eligibility-snapshot.test.mjs`. Checks oficiais, merge protegido e release imutavel devem ser comprovados antes de concluir deployment.
- Evidencias: `scripts/amazon-returns/safe-t-eligibility-snapshot.mjs`, `tests/safe-t-eligibility-snapshot.test.mjs`; issue #532, PR tecnica correspondente. Nenhuma SAFE-T externa foi aberta por esses testes.
- Pendencias: checks da PR, merge, release exata, CI/Node na release, identidade oficial por pedido e adaptador P2 certificado. D+45 primeira checagem; D+90 somente corte interno; #8803 possui claim externa; kill switch ON e sete write gates OFF.
- Estado: NAO_APTO

## 2026-11-08 — #532: inaccessible iframe must block SAFE-T identity certification

- Base SHA: `6ee859287f9df8aed1ba1dac7cc08fc76eb66829`.
- Impacto: a coleta read-only do Seller Central ignorava silenciosamente um iframe cross-origin inacessivel quando outro iframe legivel mostrava um pedido e um checkbox. A elegibilidade podia parecer certificada sem visibilidade de eventual claim ou contradicao existente na regiao inacessivel. Nenhuma abertura externa ocorreu na reproducao.
- Testes: RED sintetico na release Marina 6ee85928, task MCP audit `ea1b62db-8516-43b4-bbca-c4919efa381d`: `orderMatched=true, selectableCount=1, ambiguous=false` com iframe cross-origin adicional. GREEN em worktree descartavel da branch HEAD `671ed6786a34208883f8fb1eadb60a41d7c63f22`: `node --check` e 8/8 testes Node, incluidos cross-origin throw e contentDocument null, task `1f5cc84a-d471-4bb7-875d-a36c78eb17f8` exit0, audit `8047a2f0-6607-406e-b37a-df87263430f3`.
- Evidencias: `scripts/amazon-returns/safe-t-eligibility-snapshot.mjs`, `tests/safe-t-eligibility-snapshot.test.mjs`, issue #532; nao se tocou caso real, Seller Central autenticado, Financeiro, flags, kill switch, worker, DB ou cliente.
- Pendencias: checks oficiais, merge protegido, readback da release Marina e regressao posdeploy; compatibilidade de UI com frames cross-origin nao observados exige nova leitura grafica e caso a caso. Operador P2, lease, warehouse, Finances, iniciador Amazon, elegibilidade oficial e claim-readback externo ainda necessarios. D+45 primeiro checkpoint interno; case #8803 com claim existente; #42471 fora do grant #3556.
- Estado: NAO_APTO

## 2026-11-08 — #532: mixed visible order IDs must not certify a SAFE-T item

- Base SHA: `c85794f2c840af076d67d2938a9e8df14229a487` (official main and immutable Marina release before change).
- RED: read-only synthetic UI snapshot contained both requested Amazon order ID and a different result order ID plus selectable checkbox. Old parser `body.includes(id)` returned `orderMatched=true` despite contradictory visible identity. Native MCP audit `51a82ef4-77a2-4627-899a-d47ca6fe18a3`; integrated negative regression in isolated backend clone produced 9 PASS/1 FAIL before repair (task `4e529880-f77e-4ae2-a984-64fff7944b11`).
- Root cause: substring presence in an entire frame was treated as binding between the requested order and a selectable result; a search echo or mixed-order page can provide misleading matching text.
- Minimal fix: extract whole formatted Amazon order identifiers from the observed document text and allow local order match only if at least one ID exists AND every observed ID equals the requested order. Distinct IDs force `orderMatched=false`, preserving external evidence and fail-closed `SAFE_T_ELIGIBILITY_ORDER_UNVERIFIED`; duplicates of the same ID remain readable.
- Prevention: two regression fixtures for mixed orders and repeated exact order, plus existing iframe, input-echo and claim-read tests. No Seller Central submit, no flags, claim, worker, grants, secrets or production data changed by this code patch.
- Pending: official CI, protected merge, canonical deploy SHA and postdeploy tests; real provider UI validation per case, SP-API/warehouse/Finances, one-use P2 certified adapter/lease/watchdog, real claim readback. `8803` has an external claim and must never be duplicated; `42471` is outside #3556.
- Estado: NAO_APTO

## 2026-11-08 — #532 P0: post-click SAFE-T with no claim readback must not retry

- Base SHA: `f51da4a19f2590c3c79fe10850ba6e55cc104816`.
- Impacto: read-only Marina tenant 1 / connection 1 confirmed four SAFE_T_SUBMIT outcomes after actual Seller Central Submit clicks with no accepted external claim ID: 42471,101669,101670,101671 (all FAILED/SAFE_T_WRITE_WITHOUT_READBACK_ID, retry_safe=false, yet outbox PENDING for 09/11 due deadline-based RECOVERY_WINDOW_RETRY). 42471 is OUTSIDE owner grant #3556. Case 1263 has four FAILED/UNHANDLED_Error outcomes, retry_safe=true, also no readback; exceptions cannot prove no Submit click. A prior claim might exist at Amazon, requiring independent Seller Central reconciliation before ANY new opening. No claims were opened by the audit.
- Testes: RED with synthetic future deadline on active Marina release f51da4: retryDecision(5 attempts) returned RETRY instead of terminal (native MCP task 44d15287-84eb-4eff-8b00-ee2674764b38, exit0). GREEN on backend isolated GitHub branch checkout e5b5da50fb2087958141232d9db68249c115cdcf: PHP lint 5/5, tests/safe-t-ambiguous-outcome-no-retry-test.php PASS, tests/amazon-returns-remote-bridge-test.php PASS, git diff --check; MCP task 9ac19ae5-d0a4-46a1-a1bd-cee9725226ef exit0. Official PR/CI/merge/deploy NOT yet certified.
- Evidencias: release `f51da4a19f2590c3c79fe10850ba6e55cc104816`; native MCP readback e16e82ce-795b-4aea-8f9d-1080c2e00947, b79dce59-32c7-4949-bb65-7b8a58768723, b3ed8406-a29c-4ae6-81f7-f1e6569a673a; repairs in TenantOutbox.php, RemoteBridge.php, BridgeService.php, daemon.php and synthetic test. No customer order ID, credential, claim creation or external write issued.
- Pendencias: protected PR requires nominal owner approvals per current 08/11 policy and required CI; canonical immutable Marina release post-merge. Reconcile external Seller Central exact order and claim IDs for 42471,101669,101670,101671,1263 independently before any fresh submit; preserve existing kill switch and no worker/runner duplication. D+45 internal first check, external eligibility per claim and D+90 maximum internal, never assume D+90 official window. #8803 existing SAFE-T, #42471 excluded from grant #3556.
- Estado: NAO_APTO

## 2026-11-09 04:43 UTC — #532: reparação seletiva de fechamento D+90 com recebimento físico (preparada, NÃO executada em produção)

- Base SHA: `9fd5fe89481941d92a5090a5646c6d74d09e4b79`, release confirmada na Marina e `main` GitHub em 09/11.
- Impacto: classificação `CLOSED_LOSS/RECOVERY_WINDOW_EXPIRED_D90` contradiz evento `PHYSICAL_RECEIVED/WAREHOUSE` e `physical_status=RECEIVED_OK` para 97 registros históricos segundo auditoria SQL read-only anterior (#532, comentário 6731660787). Um dos casos tinha crédito real R$15,38. A rotina `AgeClosure` canônica jamais selecionaria caso fisicamente recebido, nem omitiria `CASE_CLOSED_ADMIN_90D`.
- Reparo técnico em branch isolada `fix/gpt-d90-warehouse-reconciliation-20261109`: classe `D90WarehouseClosureRepair` para correção **unitária, transacional e por tenant/conexão**, exigindo estado/razão antigos exatos, reembolso de fato D+90, evento WAREHOUSE, inexistência do evento administrativo legítimo e de outbox ativa; condicional CAS e evento de compensação com estado original preservado, sem gravar valor financeiro, enviar SAFE-T, suporte ou editar claim. Não há chamada da classe por timer/worker. Reclassificação preserva `closed_at` imutado e atualiza razão para `WAREHOUSE_RECEIVED_ADMIN_FINAL_D90`, mantendo `next_action_at=NULL`: o caso continua administrativamente ENCERRADO, não é reaberto após D+90 e não é marcado como recuperado. Invocação em produção ainda exige grant/lease/backup/revisão humana.
- Testes: tarefa MCP backend `54f6d4c6-b4c6-4132-853c-dd65acccff2d` GREEN no HEAD `dfdd0c7`; tarefa `ec4f5eeb-eda2-48e4-b926-d3fd2cbeeb5d` GREEN no HEAD `f4ab7ca`: PHP lint, teste sintético SQLite de tenant, prova WAREHOUSE, outbox, idempotência, crédito imutável e três regressões de encerramento financeiro; `git diff --check` sem erros. Não substituir CI oficial. Revisão posterior 09/11: RED real do reconcile worker audit 0064da2a-7ced-427d-9f53-145d68cf2db8; GREEN local task b5b283d8-2e38-4206-ab24-295bde02c9e1. Proteções adicionadas ao bootstrap, update CAS e worker para preservar encerramento administrativo D+90; regressão SQLite aguarda execução oficial.
- Evidências: issue #532 comentário 6731660787, SHA do branch GitHub e MCP tasks citadas. Readback em produção permaneceu exclusivamente de versão/serviço, sem alteração de dados.
- Pendências: CI e revisão da PR, demonstrar grant P2/lease/backup/rollback, e comprovar que reclassificação não reabre novas tentativas após D+90; somente depois considerar execução por operador canônico, caso a caso, com provas financeiras/warehouse. Sem UI Seller Central ou crédito conciliado novo.
- Estado: NAO_APTO; produção e kill switch não alterados.

## 2026-11-09 — #532: corregir divergencia do perfil PHP para renovacao SAFE-T #3846

- Base SHA: `f660d879501bf50fc7c314f9ce0e052ab75458c4`.
- Impacto: o verificador/core Node reconhece o grant #3846 aprovado no GitHub; o PHP rejeitava o perfil de renovacao nao cadastrado, inviabilizando o uso restrito do grant sem alterar sua canary.
- Testes: regressao sintetica RED por falta de perfil PHP; GREEN esperado em PHP com kill switch ON, acao unica, oito IDs exatos, negativos de perfil forjado e preservacao do vencimento do grant original.
- Evidencias: `includes/amazon-returns/Config.php`, `deploy/write-profile-safe-t-pr3846.json`, `tests/scoped-safe-t-pr3846-profile-test.php` e issue #532.
- Pendencias: CI GitHub oficial, merge protegido, deploy canonico e readback da release; falta adaptador produtivo com principal/lease/fencing/watchdog, prova oficial Amazon e comprovacao financeira. Nenhum SAFE-T ou suporte deve ser enviado apenas por esta correcao.
- Estado: NAO_APTO.


## 2026-11-09 — #532 P0: ledger do grant SAFE-T renovado #3846 (preparacao, nao ativacao)

- Base SHA: `1460f902c675b0448109e4c90f255d5b0a4e24d4` no main e release de producao Marina no inicio da correcao.
- RED: o grant oficial #3846 estava reconhecido pelo verificador Node e pelo Config.php (PRs #633 e #635), mas a classe persistente `SvAmazonScopedSafeTGrantConsumption` e o CHECK MySQL permitiam apenas #3556/#3673. Teste sintetico SQLite expandido para #3846 falhou inicialmente em `wrong grants in DDL` (MCP task df521a09-16b6-4db3-a578-ba0d049a5e36).
- Correcao: permitir explicitamente o grant renovado #3846 no allowlist PHP, DDL staged de novas instalacoes e migracao SQL staged da constraint para tabelas ja existentes; nenhuma reserva, consumo, exclusao ou mutacao de banco de producao. #3556 e #3673 permanecem identicos, #3846 tem consumo one-use proprio e nao reaproveita o registro original.
- GREEN: teste SQLite one-use/fencing/CAS e regressao do perfil PHP #3846 PASS; php -l e git diff --check PASS em branch isolada (MCP task 48929e0e-40ca-4250-89c3-f245e09b8fcb). Migracao DDL exige backup, restore isolado, exclusive lease e aprovacao P2 antes de execucao. CI oficial e gate aguardam PR.
- Escopo: apenas preparacao de compatibilidade para operador; nenhum adaptador de producao instalado, nenhuma verificacao Seller Central completa, nenhuma SAFE-T submetida, nenhum credito financeiro recebido, kill switch ON.
- Estado: NAO_APTO #532, ate operador efetivo certificado e provas independentes por caso (D+45..89, Amazon-initiated, WAREHOUSE, Finances, duplicidade, readback).

## 2026-11-10 — #532 P0: corrigir teste do canary apos expiracao do grant #3556

- Base SHA: `f469fdb7a8132872b5730cef49e3866d9127c98a` (PR #639 ja integrada; release Marina aguardando CI main).
- Impacto: o CI main falhou em `tests/write-profile-test.php` porque a verificacao sintetica ainda esperava `SAFE_T_SUBMIT=true` no perfil original #3556 apos seu vencimento em 09/11 23:59Z, embora `Config.php` o recusasse corretamente. O gate de deploy canônico registrou `ci_not_green` e manteve release anterior; NAO relaxar o vencimento.
- Testes RED: [Amazon Returns CI push 42724775599](https://github.com/Vivaliz-site/amazon-returns-safet/actions/runs/42724775599), job 129527488517, `exact scoped case canary complete expected=true actual=false`; MCP Marina `14a7fff7-cf4c-4251-938a-e35b1a7a829e` reproduziu `test_rc=1` no release anterior.
- Correcao: `tests/write-profile-test.php` valida a canary exata de oito casos pelo perfil renovado #3846 (ja aprovado pelo proprietario), mantendo os testes separados da expiracao #3556. Nenhum codigo da aplicacao, gatilho de escrita, grant, schema ou worker alterado.
- Testes GREEN: clone isolado Marina HEAD `0663c80a43f50d7b8d8fe1cdf2315a3b49374879` task `491eae37-fdfe-417c-98ec-b3fb6b1b7e30` exit0: `write-profile-test.php`, perfis #3556/#3673/#3846, consumo SQLite, PHP lint, `git diff --check`. Checks GitHub oficiais para o HEAD final da PR ainda exigidos.
- Evidencias: #532, #639, runs/job GitHub acima e MCP task/audits citados; sem dados de clientes e sem escrita Amazon.
- Pendencias: PR tecnica, CI obrigatoria, merge conforme gates, checks de push main GREEN, deploy canonico Marina e readback do SHA, verificacao de kill switch ON. Schema MySQL #3846 continua staged, sem backup+restore/lease; operador certificado, evidencias oficiais Amazon e conciliacao financeira continuam faltando.
- Estado: NAO_APTO #532 ate elegibilidade e claims externos comprovados por caso. Sem claim ou credito confirmado nesta correcao.

## 2026-11-11 — #532 P0: compensacao append-only de recebimento administrativo indevido #101669

- Base SHA: `fc9026e84fc304e5c1112fa9d80ca7df47d1116d` (main e release da Marina antes desta correcao).
- Impacto: pedido 701-6883818-0162606, caso #101669, marcado `RECEIVED_OK` sem comprovante de entrega fisica ao vendedor; evento administrativo WAREHOUSE #1267182 de 11/11 00:48 UTC, sem foto e nota vazia. Evidencia de rastreio traz `SHIPMENT_RETURNED_TO_AMAZON`, nao recebimento na ShopVivaliz. Claim existente 89790-48319-8106439 negada e recurso negado; reconciliacao financeira R$ 0,00.
- RED: fixture sintetica da mesma topologia falhou `corrected intake must not invent warehouse quantity want=0 got=1` antes do patch (task MCP backend `936ceb18-2620-4752-a569-3aa0d57a2dbb`).
- Correcao: evento compensatorio imutavel `PHYSICAL_RECEIPT_VOIDED`, vinculado a evento original e pedido/tenant/conexao; projecao, decisao SAFE-T, revisao contextual e consultas SQL de prova fisica desconsideram somente o recebimento efetivamente cancelado. Ferramenta operacional de escopo unico, idempotente, default `DRY_RUN`, com readback e lock, sem apagar provas, sem alterar estoque ERP, credito, claim ou perfis de escrita. Compatibilidade preservada com a PR concorrente #655, ja mesclada em main e adotada como evento canonico.
- Testes: GREEN nos direcionados `warehouse-receipt-void-test`, `warehouse-receipt-correction-db-test`, `seller-receipt-authority-test`, `review-context-signature-test`, `d90-warehouse-closure-repair-test` e `automatic-90-day-closure-test`, task `c97e5d8e-4fc7-45e3-98ea-1f74cccb5641`. Suite ampla no backend: 392/397 passaram; cinco contratos SQLite nao executaram porque runtime PHP CLI tem `PDO` mas nao `pdo_sqlite` compativel (API 20240924 vs modulo 20230831). CI oficial no SHA da PR e execucao em ambiente PHP+SQLite exigidas para validacao.
- Evidencias: issue #532 comentario #6785130867 e MCP tasks citadas. Nenhuma mutacao em producao ou acao externa nesta preparacao.
- Pendencias: CI/GitHub, merge canonico, deploy SHA exato Marina, preflight/dry-run tenant 1/1 sem concorrente, aplicacao da compensacao real sob autorizacao do owner e readback WAREHOUSE/financeiro/claim. Contestacao da negacao exclusivamente na reivindicacao existente se o Seller Central oferecer canal autorizado. Estado: NAO_APTO ate comprovacao real.

## 2026-11-12 — #532 P0: checagem oficial de elegibilidade SAFE-T D+45 nunca verificava identidade (shadow DOM)

- Base SHA: `f6256a85c4396d5bc4ae867ceec5dc8a5f4153a5` (main e release da Marina antes desta correcao).
- Impacto: desde a PR #620 (08/11) toda leitura `--eligibility-check-only` terminava em `UI_DRIFT / SAFE_T_ELIGIBILITY_ORDER_UNVERIFIED` (ou `RESULT_AMBIGUOUS`), inclusive para o controle #8803, que possui claim externa `64204-51742-6364222`. Nenhum caso D+45 podia ser classificado como elegivel, inelegivel ou ja reivindicado pela UI oficial. Probes reais em 12/11 (MCP task `254cf546-1570-40ac-9493-f64137637fc0`): 5 de 6 pedidos `ORDER_UNVERIFIED` e 1 `AMBIGUOUS`.
- Causa raiz: a identidade era derivada so de `body.innerText`. O Seller Central desenha o link do pedido dentro do shadow root de `kat-link`, que `innerText` nao alcanca; o texto do pedido nunca entrava na prova. A PR #620 removeu corretamente a inferencia por eco do campo de busca, mas deixou o snapshot sem outra fonte de identidade. Diagnostico estrutural mascarado (MCP task `cf691463-af6a-42c8-b30e-5550b7d18cd9`): `innerText` sem IDs de pedido, ID do pedido presente apenas no shadow DOM/atributos do `kat-link`, e um segundo ID nao renderizado (filhos light DOM sem `<slot>`) que envenenaria uma varredura ingenua.
- Correcao: `safe-t-eligibility-snapshot.mjs` passa a ler o texto renderizado pela arvore composta (shadow roots abertos e `<slot>`; filhos light DOM de um host so contam se atribuidos a um slot), ignorando `SCRIPT/STYLE/TEMPLATE/NOSCRIPT/HEAD`, `hidden`, `display:none` e `visibility:hidden`; `display:contents` continua renderizando. Valores de `input` nao sao nos de texto, entao o eco do campo de busca continua sem provar identidade. Exigencia inalterada: todo ID de pedido visivel deve ser igual ao consultado e deve existir ao menos um; dois documentos candidatos, iframe inacessivel ou dois pedidos visiveis continuam `UI_DRIFT`/ambiguo. Nenhum clique de escrita, flag, grant, worker ou restricao foi tocado.
- Testes: RED no codigo original: os 2 testes positivos novos falham (`shadow root` e `claim com link em shadow root`); GREEN: 33/33 nas suites `safe-t-eligibility-*`, incluindo 11 novos (negativos: pedido diferente em shadow, dois pedidos, no nao renderizado, filho light sem slot, slot atribuido com outro pedido, shadow ilegivel sem excecao). Suite `node --test tests/*.test.mjs`: 286/288; as 2 falhas (`seller-central-managed-lock` FLOCK FD9 e `seller-support-hill-expression`) tambem falham no main sem alteracoes neste host Windows e nao tocam o diff.
- Validacao real somente-leitura na pagina publicada do Seller Central, copia temporaria da release na Marina, sem alterar a release ativa (MCP task `5572bbe5-dfa8-4ca0-b337-00876ccc812e`): #8803 -> `ALREADY_EXISTS / SAFE_T_EXISTING_CLAIM_FOUND / 64204-51742-6364222` (igual ao banco); #1263 -> `ELIGIBLE / SAFE_T_OFFICIAL_ITEM_PRESENT` com identidade `702-***-4621` verificada. Isso e observacao oficial de elegibilidade; **nao** abre SAFE-T, nao autoriza escrita e nao prova credito.
- Pendencias: CI GitHub, merge, deploy canonico da release, repeticao do mesmo probe na release implantada, grant/operador/lease para qualquer escrita (kill switch e sete write gates continuam OFF), backlog de 373 `SAFE_T_DISCOVERY` pendentes com timer diario, E2E na UI autenticada do cockpit.
- Estado: NAO_APTO.

## 2026-11-12 — #532: aviso falso de dados desatualizados no cockpit (E2E real na UI)

- Base SHA: `4876c35b13e3072d067e3f3109a08986797dde88` (main e release da Marina).
- Como foi achado: E2E somente leitura na UI real publicada, sessao autenticada do operador na janela grafica auxiliar da VM backend. Detalhe do #8803 (screenshot sha256 `06119ea92ff5bd4fff97806a2c78bd868484f3e5ebdfa528cc7c52cadce22d1a`) e do #1263 encerrado como perda (`efcff006a0ae3e9c7201eec1d91da0998eb0c680be74c94e7aaf1ac5288c429f`) exibem "Dados podem estar desatualizados: a ultima verificacao ocorreu ha mais de 36 horas".
- Causa raiz: `last_read_back` vinha so do ultimo evento de observacao (`SAFE_T_STATUS_OBSERVED` etc.). Um status que nao muda nao gera evento novo; no #8803 o ultimo evento e de 07/11 01:00 enquanto o `SAFE_T_READ` terminou com sucesso hoje 00:20 UTC (MCP audit `0c98e91d-be75-47e4-8d59-530e346f1c7b`). Alem disso, casos terminais (`CLOSED_LOSS`, `RECOVERED`, `APPEAL_DENIED_FINAL`) nunca sao relidos, entao o aviso sempre aparecia neles depois de 36 h.
- Correcao: `SvAmazonCockpitReadFreshness::latest()` combina o evento mais recente com o ultimo job de leitura bem-sucedido (`SAFE_T_READ`, `SAFE_T_DISCOVERY`, `SELLER_SUPPORT_READ`, `ORDER_DELIVERY_READ`), usado em `api/case.php` e `api/cases.php`; falhas, retries, escritas e datas invalidas nao contam (o teste pegou `createFromFormat` aceitando mes 13/hora 99; corrigido por ida e volta do formato). `operatorDataIsStale()` no JS deixa de avisar para casos terminais ou encerrados. Nenhum valor, estado, politica, flag ou grant alterado; o aviso continua para casos ativos realmente sem leitura ha mais de 36 h.
- Testes: RED no codigo original (4/4 testes JS novos falham; contrato PHP exige o helper); GREEN: `tests/cockpit-read-freshness-test.php` e `tests/cockpit-stale-data-note.test.mjs` 4/4. Node completo 290/292 e PHP relacionado: as falhas restantes (`seller-central-managed-lock`, `seller-support-hill-expression`, `safe-t-discovery-pagination-contract-test` sem driver PDO) tambem falham no main sem alteracoes neste host Windows.
- Pendencias: CI GitHub, merge, deploy canonico e repeticao do mesmo E2E na release implantada (o aviso nao deve mais aparecer no #8803 nem no #1263).
- Estado: NAO_APTO.

## 2026-11-12 — #532: rotulo financeiro de caso encerrado como perda e URL da aba Revisoes (E2E real na UI)

- Base SHA: `6a8fc41d8cc3f604e2292bece9f9003788f1a5be` (main e release da Marina).
- Achados no E2E somente leitura na UI real publicada (sessao do operador): (1) o #1263, encerrado como perda, exibia "saldo ainda a recuperar R$ 36,38" (screenshot `5d812c967ac2c1b36808c6b8e4d5c3ac2bb490058121fc5bbaae092f9ae5824e`), contradizendo "Encerrado sem recuperacao"; (2) a aba Revisoes nao atualizava a URL, entao recarregar voltava para Casos.
- Correcao: `operatorFinancialSummary()` passa a rotular `CLOSED_LOSS` com saldo como "valor nao recuperado; caso encerrado como perda"; `selectView()` chama `syncUrl()` ao trocar de aba. Nenhum valor, estado, politica, flag ou grant alterado.
- Testes: RED no codigo original (3/3 novos falham); GREEN 10/10 nas suites cockpit; Node completo 294/296 e PHP cockpit sem falhas; as 2 falhas Node (`seller-central-managed-lock`, `seller-support-hill-expression`) tambem falham no main sem alteracoes neste host Windows.
- Observacao nao corrigida: a lista leva ~14 s para popular numa busca por um unico pedido (investigar custo de `api/cases.php` por caso candidato).
- Pendencias: CI GitHub, merge, deploy canonico e repeticao do E2E na release implantada.
- Estado: NAO_APTO.

## 2026-11-12 — #532: selecao de categoria Seller Support para FBA Onsite

- Base SHA: d30c08abc2c366faa7d2423004ab17568e14a553.
- Defeito: openGeneralSupportRoute() oferecia categorias FBA somente quando case.program era FBA, mas o programa efetivo de Seller Flex e FBA_ONSITE. Isso excluia os rotulos FBA e mantinha apenas categorias A-to-z; o health da release de 12/11 registrou OUTBOX_UI_DRIFT_REASON_SUPPORT_GENERAL_CATEGORY_MISSING. A falha explica uma causa reproduzivel de selecao, sem provar que o layout externo da Amazon permaneça igual.
- RED: teste tests/support-program-category-contract.test.mjs executado contra main original no clone isolado do backend — 2/3 PASS, 1/3 FAIL exatamente para FBA_ONSITE (task MCP 71bf9dde-ccea-4095-84a4-427329c15961).
- Correcao: incluir FBA_ONSITE no mesmo conjunto explicito de rotulos FBA ja existente, preservando a categoria DBA e o comportamento fail-closed quando nenhum rotulo oficial e encontrado. Sem ativacao de escrita, alteracao de fila, claims ou configuracao do Seller Central.
- GREEN: contra a alteracao isolada no backend, node --check PASS, node --test tests/support-program-category-contract.test.mjs tests/seller-support-asin-expression-syntax.test.mjs 4/4 PASS, git diff --check PASS (tasks MCP d1aca647-c6a7-4ca7-87b3-eeb53c67222d e 39c54b53-a2fc-4da0-b1eb-31ccea6f0025). Conferir CI oficial no HEAD exato da PR.
- Risco e pendencias: configuracao efetiva da Marina ainda foi lida com kill_switch_active=false, sete flags externas true e unscoped_write_profile_risk=true (task MCP 52f6c64f-8340-4dcc-91c6-8b42c95708df). Pelo controle owner-only de 10/11, so o proprietario pode restaurar essas restricoes. A alteracao funcional fica em PR Draft sem merge/deploy, para nao facilitar novo suporte externo sob perfil amplo; apos restauracao pessoal e readback, finalizar gates, merge e E2E grafico oficial com deduplicacao e autorizacao por caso.
- Estado: NAO_APTO; nenhuma mensagem ou SAFE-T foi enviada por este reparo.
