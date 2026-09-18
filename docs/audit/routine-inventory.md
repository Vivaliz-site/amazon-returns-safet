# Amazon Returns / SAFE-T — Inventario de Rotinas para Auditoria Extrema

Este inventario e o mapa obrigatorio de superficies que precisam de prova real antes de o projeto ser classificado como `APTO`. Ele foi derivado das entradas de UI/API, `SvAmazonReturnsRuntime::cadences()`, `workers/amazon-returns/daemon.php`, bridges, outbox, write profile e rotinas de deploy.

## Regras do inventario

- `MANDATORY`: faz parte do comportamento atual prometido ao operador ou da autonomia necessaria para entregar esse comportamento.
- `OUT_OF_SCOPE`: nao integra o caminho de producao atual; a justificativa deve permanecer explicita.
- Cada rotina `MANDATORY` exige entrada real, processamento interno, efeito final, readback/reconciliacao e evidencia identificavel.
- Escritas externas exigem idempotencia e confirmacao no destino.
- Interfaces usadas pelo operador exigem pelo menos uma execucao pela UI real.
- Rotinas autonomas exigem prova pelo scheduler/daemon/bridge real, nao apenas chamada direta de classe ou script.

## Inventario — superficie do operador

| ID | Rotina | Escopo | Entrada real | Caminho interno | Dependencias/gates | Efeito final e readback |
|---|---|---|---|---|---|---|
| UI-01 | Login e sessao administrativa | MANDATORY | `login.php` -> painel Amazon Returns | `AdminAuth` + sessao | credencial admin, cookie/sessao | painel autenticado acessivel; readback pela propria sessao e rotas protegidas |
| UI-02 | Cockpit/resumo operacional | MANDATORY | `admin/amazon-returns/index.php` | `api/summary.php`, health/cockpit services | DB, tenant atual | totais/saude/pendencias coerentes com snapshot do DB |
| UI-03 | Consulta e filtro de casos | MANDATORY | cockpit -> `api/cases.php` | projector + policy + decision engine | DB, tenant | lista consistente; readback por reconsulta/reload |
| UI-04 | Detalhe e linha do tempo do caso | MANDATORY | cockpit -> `api/case.php` | events, outbox, projector, timeline | DB, tenant | estado/acoes/evidencias visiveis e persistentes apos reload |
| UI-05 | Busca de devolucao por pedido/NF/TBR | MANDATORY | `intake.php` -> `api/intake-lookup.php` | busca local -> SP-API/invoice/Gmail fallback -> persistencia | CSRF, DB, SP-API/Gmail quando necessario | caso localizado/sincronizado; readback pela propria busca e caso projetado |
| UI-06 | Registro de recebimento fisico | MANDATORY | formulario de recebimento -> `api/intake.php` | valida quantidade/condicao/fotos -> evento `PHYSICAL_RECEIVED` | login, CSRF, DB, evidence dir | evento e evidencias persistidos; readback por detalhe/reconsulta; operation UUID impede duplicata |
| UI-07 | Lista/detalhe de revisoes | MANDATORY | cockpit de revisoes -> `api/reviews.php`/`review.php` | review repository + timeline | DB, tenant | revisao atual e contexto legivel; reload conserva estado |
| UI-08 | Preview da decisao de revisao | MANDATORY | UI -> `api/review-preview.php` | `ReviewService` + coordinator | login, CSRF, DB | preview coerente sem mutacao externa; comparavel com decisao final |
| UI-09 | Sugestao de IA para revisao | MANDATORY quando revisao requer IA | UI -> `api/review-suggest.php` | advisor multi-provider -> `saveSuggestion` | provedor AI configurado, review version | sugestao/version persistida ou falha explicitamente registrada; readback em `review.php` |
| UI-10 | Confirmacao de decisao humana | MANDATORY | UI -> `api/review-decision.php` | `ReviewService::submit` -> coordinator/learned rule/outbox | login, CSRF, version lock, write gates | revisao resolvida e acao correspondente persistida/enfileirada; readback por review/case/outbox |
| UI-11 | Gestao de regras aprendidas | MANDATORY quando regras aprendidas estao ativas | UI/APIs `rules.php` e `rule-status.php` | learned-rule repositories/outcomes | login, CSRF para mutacao, tenant | status de regra persistido; nova decisao respeita revisao da regra |

## Inventario — ingestao, reconciliacao e decisao autonoma

| ID | Rotina | Escopo | Entrada/cadencia | Caminho interno | Dependencias/gates | Efeito final e readback |
|---|---|---|---|---|---|---|
| AUTO-01 | Daemon/orquestrador | MANDATORY | `amazon-returns-safet.service`; loop ~30s | `SvAmazonReturnsDaemon::runOnce` -> `dueTasks` -> `decisionSafeOrder` | DB, tenant, runtime state file | tasks devidas executadas, estado/cursors atualizados; readback por runtime-state e `OPERATIONAL` cursors |
| AUTO-02 | Gmail incremental ingest | MANDATORY | task `gmail`, 12h + revision/wake triggers | Gmail API -> parser -> `GmailEventSink` | `AMAZON_RETURNS_GMAIL_INGEST`, Gmail OAuth | mensagens viram eventos/casos; cursor avanca; readback por events/cases/cursor |
| AUTO-03 | Gmail history recovery probe | MANDATORY para continuidade da ingestao | revision-triggered `gmail_history_probe` | Gmail history pull de 1 item sem avancar cursor | Gmail ingest + OAuth | prova que cursor/historico ainda e recuperavel; metadata em cursor operacional |
| AUTO-04 | Reconciliacao Gmail de reembolso | MANDATORY | `gmail_refund_reconciliation`, 12h/revision | busca `newer_than:90d reembolso iniciado` -> ingestao | Gmail ingest + OAuth | evidencias de reembolso persistidas; nao e verdade financeira por si so |
| AUTO-05 | SP-API orders/finances/SAFE-T read | MANDATORY | `sp_api`, 12h + wakes/revisions | sync order -> transactions -> SAFE-T reimbursements -> event sink | LWA credentials, throttle | dados Amazon persistidos e cursor de scan atualizado; readback por events/cases/cursors |
| AUTO-06 | Amazon Returns Reports | MANDATORY | `returns_report`, 12h | request/poll/download report -> parse -> persist | SP-API credentials | status fisico/retorno persistido; high-water/readback por cursor + casos |
| AUTO-07 | Reconciliacao financeira | MANDATORY | `financial`, 12h apos refresh aceito | events -> transactions -> reconciler -> case update | evidence de refresh SP-API, DB | credito reconciliado; `RECOVERED` somente com evidencia financeira; readback por case/events |
| AUTO-08 | Motor de decisao/scheduler | MANDATORY | `scheduler`, 12h + wakes e revisoes de stack/profile/rules | projector -> policy -> coordinator -> outbox | evidencias atuais, write gates, canary, readiness | proxima acao/next_action e outbox corretos; readback por case/outbox/decision audit |
| AUTO-09 | Wake por data conhecida | MANDATORY | `next_known_action_at` vencido | força Gmail + SP-API + financial + scheduler + Seller Central | runtime state + casos abertos | caso retoma no prazo sem intervencao; readback por cursors/outbox/state |
| AUTO-10 | Operacoes de revisao/reminders | MANDATORY | `review_operations`, 2h | `SvAmazonReviewOperations` | DB, config de notificacao/AI conforme operacao | revisoes pendentes tratadas/notificadas; readback por review/outbox/eventos |

## Inventario — escritas externas e bridges

| ID | Rotina | Escopo | Entrada/cadencia | Gates/dependencias | Destino | Readback obrigatorio |
|---|---|---|---|---|---|---|
| WRITE-01 | Abrir SAFE-T | MANDATORY quando decisao `SAFE_T_SUBMIT` e elegivel | scheduler -> outbox -> Seller Central bridge | global enabled + production + kill switch OFF + `SAFE_T_SUBMIT=true` + canary + bridge ready | Seller Central SAFE-T | claim/SAFE-T ID retornado e consulta posterior do claim/status |
| WRITE-02 | Recorrer SAFE-T | MANDATORY quando decisao `SAFE_T_APPEAL` | scheduler -> outbox -> Seller Central bridge | mesmos gates + `SAFE_T_APPEAL=true` | claim SAFE-T existente | appeal aceito/ja existente + status/mensagem posterior no claim |
| WRITE-03 | Abrir Seller Support | MANDATORY quando decisao `SELLER_SUPPORT_OPEN` | scheduler -> outbox -> bridge | `SELLER_SUPPORT_OPEN=true`, canary, bridge/auth | Seller Support | case ID externo e leitura posterior do case |
| WRITE-04 | Atualizar Seller Support | MANDATORY quando decisao `SELLER_SUPPORT_UPDATE` | scheduler -> outbox -> bridge | `SELLER_SUPPORT_UPDATE=true`, canary, bridge/auth | Seller Support | mesmo case ID com resposta/status lido novamente |
| WRITE-05 | E-mail de revisao SAFE-T | MANDATORY quando requerido | scheduler -> outbox -> task `gmail` | `SAFE_T_EMAIL_REVIEW=true`, Gmail OAuth | Gmail/Amazon thread | Gmail message/thread ID persistido e thread consultavel |
| WRITE-06 | Resposta por e-mail SAFE-T | MANDATORY quando requerido | scheduler -> outbox -> task `gmail` | `SAFE_T_EMAIL_REPLY=true`, Gmail OAuth | thread Gmail existente | message/thread ID e evento `SAFE_T_EMAIL_REPLY_SENT` |
| WRITE-07 | Devolucao de venda no ERP | MANDATORY para todo pedido com quantidade reembolsada > 0 no escopo do ERP, incluindo aumento de quantidade reembolsada apos a primeira devolucao/NF | task `erp_sales_returns`, 12h | `AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED=true` E profile `ERP_SALES_RETURN_CREATE=true`, ERP auth/browser ready | Olist/Tiny `Devolucoes de venda` | devolucao existente ou criada; external return ID + leitura posterior vinculada a NF de venda/pedido; quantidade reembolsada reconciliada e comparada a cada ciclo — aumento sem cobertura bloqueia o workflow (`ERP_SALES_RETURN_ADDITIONAL_QUANTITY_PENDING`) em vez de ser tratado como concluido |

## Inventario — infraestrutura operacional e autonomia

| ID | Rotina | Escopo | Entrada/cadencia | Dependencias | Efeito esperado | Prova/readback |
|---|---|---|---|---|---|---|
| OPS-01 | Bridge de escrita Seller Central | MANDATORY | `api/amazon-returns/bridge.php` + browser worker | token, worker primario, browser/auth | pull/execucao/result de writes do outbox | heartbeat + job/result + external ID/readback do destino |
| OPS-02 | Bridge de leitura/status | MANDATORY | `status-bridge.php` + SAFE-T read worker | token, worker de status, browser/auth | atualizar mensagens/status SAFE-T e Seller Support | heartbeat + result persistido + caso/timeline atualizado |
| OPS-03 | Autenticacao Seller Central | MANDATORY | auth check + ciclos browser | usuario/senha, perfil persistente, TOTP remoto | sessao autenticada sem intervencao local | auth_status/heartbeat atual e probe autenticado |
| OPS-04 | Cofre TOTP remoto | MANDATORY para login autonomo | SSH forced-command | host TOTP separado, chave/known_hosts, seed valida | somente OTP atual de 6 digitos | auth real Seller Central usando OTP sem expor seed |
| OPS-05 | Browser Seller Central | MANDATORY | systemd service/timer e runner | Chromium nao-Snap, perfil, CDP | executar bridge/status reads/writes | timer/service + heartbeat + acao/readback real |
| OPS-06 | Browser ERP Olist | MANDATORY para WRITE-07 | `amazon-returns-olist-erp-browser.service` persistente | Chromium, Playwright, perfil autenticado, CDP loopback 9226 | pagina `devolucoes_vendas` pronta para read/write | CDP readiness + lookup/readback real no ERP |
| OPS-07 | Health funcional | MANDATORY | API health + task `health` 15min | DB, readiness, workers, gates e SLAs obrigatorios | nunca `OK` com capacidade obrigatoria indisponivel | resposta health com blockers especificos e freshness |
| OPS-08 | Daemon principal | MANDATORY | `amazon-returns-safet.service`, loop 30s | DB, tenant/context, runtime state | disparar todas as cadencias autonomamente | cycle_attempt/success, tasks dentro do SLA e runtime state |
| OPS-09 | Auto-deploy/auto-gate | MANDATORY | `amazon-returns-deploy.timer/service` | Git/CI, root service, quiesce seguro | promover exatamente SHA validado e reiniciar runtime | deployed SHA + servicos/timers + smoke funcional |
| OPS-10 | Quiesce e recuperacao de jobs | MANDATORY | deploy/rollback | daemon/browser/outbox | zero PROCESSING ou recuperacao segura sem perda/duplicata | contagens antes/depois + release de stale jobs + restart |
| OPS-11 | Isolamento tenant/connection | MANDATORY | toda UI/API/worker/repository | tenant registry/context | nenhuma leitura/escrita cruza seller/connection | testes + consultas reais tenant-scoped |
| OPS-12 | Independencia Fred-Win/KOCEPSV | MANDATORY | operacao normal em VM | VM principal + TOTP remoto; PCs apenas fallback | sistema opera com ambos PCs desligados | ciclos reais daemon/browser/bridges sem dependencia desses hosts |

## Rotinas adicionais descobertas pela varredura cruzada

| ID | Rotina | Escopo | Entrada/cadencia | Dependencias | Estado esperado/readback |
|---|---|---|---|---|---|
| AUTO-11 | Ciclo `seller_central` do daemon | MANDATORY | task `seller_central`, 12h + wake/revision | bridge mode/readiness + write flags + outbox | em polling, bridge assume outbox; em direct, processa writes; provar jobs e resultados/readback no destino |
| AUTO-12 | `policy_monitor` | OUT_OF_SCOPE na producao atual enquanto `AMAZON_RETURNS_POLICY_MONITOR=0` e nao existe observation provider | task 12h | flag + provider futuro | nao pode ser usado como evidencia de prontidao; se ativado, passa imediatamente a MANDATORY e precisa probe real |
