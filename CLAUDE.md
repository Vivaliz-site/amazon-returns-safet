<!-- SHOPVIVALIZ_PR_CONDICIONAL_PTBR_20261108 -->
## Governança das PRs ShopVivaliz — regra transversal (08/11/2026)
- **Idioma:** toda PR criada por agentes deve ter título, descrição, justificativas, pedidos de revisão e comentários operacionais em **português brasileiro (pt-BR)**; manter nomes técnicos, caminhos, código e comandos no idioma original.
- **Aprovação dupla somente quando o diff INTRODUZIR, RESTAURAR ou AGRAVAR bloqueio, trava, restrição, permissão obrigatória, gate ou configuração que possa impedir a execução de comandos, operações, automações, deploys ou merge.** Nesses casos, exigir consentimento expresso do proprietário e revisões GitHub `APPROVED` das duas contas `shopvivaliz-human` e `fredmourao-ai`, no commit HEAD exato. PR protegida deve ser criada por terceira identidade autorizada (nenhuma das duas pode aprovar a própria PR).
- **PR comum:** documentação, correção técnica, teste, refatoração e melhoria que NÃO adicione nem agrave bloqueio não requerem dupla revisão por esta política; seguem o fluxo automático de correção, validação e integração. Outras revisões de segurança/comerciais já exigidas permanecem válidas.
- **Sem contorno:** nenhum agente/bot pode simular ou dispensar revisões, introduzir a restrição por outra rota, dividir mudança para escapar da regra, realizar push direto ou usar privilégios administrativos para desativar as proteções. Proteções obrigatórias do provedor, MFA e RBAC permanecem válidas.
- **Escopo:** todos os repositórios e agentes da organização Vivaliz-site, inclusive Claude, GPT, Codex, Gemini, ações automatizadas e MCPs. A classificação considera o efeito real da alteração; editar `AGENTS.md` ou workflow sem acrescentar restrição não aciona aprovação dupla.
<!-- /SHOPVIVALIZ_PR_CONDICIONAL_PTBR_20261108 -->

<!-- MANDATORY_AGENT_CHECKLIST_V1 -->
## Checklist proporcional: sem bloqueio global para tarefas rotineiras

**Revisao por determinacao do proprietario (06/11/2026):** foi revogada a obrigatoriedade universal de planejar e certificar um checklist a cada etapa, introduzida em 31/10/2026. A ausencia de `plan-checklist` nao pode impedir `record_progress`, retomada, correcao, teste ou conclusao de tarefas P0/P1 que ja possuam autorizacao. Um checkpoint duravel e uma proxima acao concreta continuam suficientes para registrar o trabalho; nao criar processo de aprovacao por subcomando.

`scripts/agent_task_state.py` deixa de injetar automaticamente um checklist exigivel em novos checkpoints do runtime canonico. Checkpoints anteriores com lista **vazia** nao ficam presos por um plano nunca iniciado. Para trabalhos que precisam de checklist formal, o executor pode ativar `SHOPVIVALIZ_REQUIRE_AGENT_CHECKLIST=1` no escopo do fluxo e usar `plan-checklist`, `next-step`, `check-step` e `add-checklist-step`. Se ja existem etapas planejadas, suas evidencias e integridade continuam obrigatorias para encerrar; nao apagar nem certificar etapas sem comprovacao.

Essa desburocratizacao nao autoriza falso sucesso nem remove controles independentes: identidade, MFA, RBAC, segredos, lease/fencing, aprovacoes humanas formalmente necessarias, branch protection, gates de operacoes comerciais, CI e validacao funcional/E2E pertinente. Nenhum controle novo deve ser imposto sem aprovacao do proprietario.
<!-- /MANDATORY_AGENT_CHECKLIST_V1 -->

<!-- GEPETO_EVERY_TASK_V2 -->
## Gepeto e Superpowers: leitura e evidencia em toda tarefa
Antes de executar ou retomar qualquer tarefa, consultar a politica [GEPETO-POLICY.md](GEPETO-POLICY.md) e as instrucoes atuais do repositorio. Acionar Gepeto e Superpowers quando disponiveis no runtime; se ausentes, registrar GEPETO_UNAVAILABLE/SUPERPOWERS_UNAVAILABLE e aplicar o metodo equivalente sem simular ferramenta, agente extra, permissao ou revisao independente. Preservar gates de seguranca, identidade, lease/fencing e evidencias de validacao.
<!-- /GEPETO_EVERY_TASK_V2 -->

<!-- EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->
> **AUDITORIA EXTREMA V5 = UI REAL OBRIGATORIA.** Antes de declarar `APTO` ou `CONCLUIDO`, o proprio agente percorre cada jornada material na interface grafica real (navegacao, cliques, preenchimento, resultado visivel, reload/revisita, persistencia e evidencia visual com ambiente/release). API, terminal, SQL, codigo, screenshot isolado, mocks e testes apenas headless nao substituem prova pela UI. A auditoria inclui investigacao, correcao e reauditoria visual apos reparo. Nao ocultar uma UI marcando `browser.required=false` ou `journey.ui=false`. Somente componentes comprovadamente sem UI podem usar excecao com inventario negativo documentado e evidencias. Em bloqueio de sessao, esgotar rotas autorizadas; nao burlar MFA/permissaos. Leia e obedeça a secao `EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1` de `AGENTS.md` antes da tarefa.
<!-- /EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->

<!-- AUDIT_EXTERNAL_REMEDIATION_V1 -->
> **AUDITORIA EXTERNA:** também é corretiva. Se houver autorização de escrita, corrigir + testar + reauditar. Se o auditor for somente leitura/independente, o relatório não é estado terminal: encaminhar os achados a executor autorizado e manter a tarefa aberta até correção e revalidação independente ou bloqueio externo comprovado.
<!-- /AUDIT_EXTERNAL_REMEDIATION_V1 -->

<!-- AUDIT_REMEDIATE_VALIDATE_GLOBAL_V1 -->
> **AUDITORIA CORRETIVA OBRIGATÓRIA:** qualquer auditoria deve investigar causa raiz, corrigir achados materiais seguros/autorizados, prevenir recorrência quando pertinente, testar e reauditar. Não encerrar em relatório de erros. APTO/CONCLUIDO exige evidência fresca pós-correção/E2E aplicável. Fonte: `REGRAS-AGENTES-CENTRALIZADAS.md`.
<!-- /AUDIT_REMEDIATE_VALIDATE_GLOBAL_V1 -->

<!-- DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->
> **DIAGNÓSTICO CORRETIVO GLOBAL:** diagnóstico confirmado obriga correção segura, prevenção pertinente, testes e validação real/E2E aplicável antes de `CONCLUIDO`; enquanto houver ação executável, permanecer `RUNNING`.
<!-- /DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->
<!-- CLAUDE_READ_AGENTS_FIRST_V1 -->
> **BOOTSTRAP OBRIGATORIO DO CLAUDE:** antes de analisar, planejar, editar, executar comandos, usar navegador, abrir PR, fazer merge, deploy ou validar qualquer tarefa, leia **integralmente** o `AGENTS.md` da raiz deste repositorio e cumpra todas as instrucoes vigentes nele. Releia o `AGENTS.md` em retomadas de conversa ou quando o arquivo mudar. Se houver divergencia entre este `CLAUDE.md` e o `AGENTS.md`, o `AGENTS.md` prevalece. Nao prossiga assumindo memoria de uma leitura antiga.

@AGENTS.md

<!-- GLOBAL_BROWSER_VM_POLICY_V2 -->
> **NAVEGAÇÃO GLOBAL — VM OBRIGATÓRIA; WINDOWS PROIBIDO PARA BROWSER:** qualquer navegador, automação browser, sessão gráfica, Playwright/Selenium/CDP, Chrome/Chromium/Edge/Opera, CAPTCHA, MFA, consentimento ou validação visual deve usar por padrão e obrigatoriamente a VM backend `always-free-arm-1787907847-26` (`10.0.1.38`) e o Browser Worker privado. Para intervenção humana, usar `https://shopvivaliz.com.br/admin/browser-worker.php`. **Fred-Win (`LAPTOP-NIG4IFUU`) e `DESKTOP-KOCEPSV` não são destinos nem fallback para navegação.** Não perguntar qual máquina usar para browser: use a VM. Exceção somente se o proprietário ordenar explicitamente, na tarefa atual, o uso de um Windows específico para aquela navegação. Se a VM estiver indisponível, reparar o caminho VM/OCI Bastion/túnel privado ou registrar bloqueio real; nunca migrar silenciosamente para Windows. Workflows/relays Windows de browser são legado e não devem ser executados até serem migrados. A regra não proíbe Windows para tarefas não-browser que realmente dependam dele.

<!-- audit-refs: AUDIT_POLICY.md docs/quality/EXTREME_AUDIT_PROTOCOL.md docs/quality/AUDIT_RUNTIME_PARITY_V1.md docs/quality/AUDIT_UNIVERSAL_COVERAGE_V1.md docs/quality/AUDIT_SELF_TEST_V1.md docs/quality/ARCHITECTURE_DEPLOY_AUDIT_V1.md -->
<!-- gate: FINAL_RESPONSE_DEPLOY_GATE_V1 — resposta final só após validação pós-deploy completa -->

<!-- EXECUTION_PROVENANCE_POLICY_V1 -->
@EXECUTION-PROVENANCE-POLICY.md


<!-- BROWSER_SESSION_POLICY_V1 -->
Leia e cumpra AGENTS.md e a secao BROWSER_SESSION_POLICY_V1 de REGRAS-AGENTES-CENTRALIZADAS.md antes de qualquer uso de navegador.


<!-- AUDIT_ABSOLUTE_V5_ENTRYPOINT -->
Leia primeiro `AGENTS.md`. Para auditoria/aptidão, cumpra `AUDIT_ABSOLUTE_GATE_V1.md`, `AUDIT_BROWSER_E2E_REAL_V1.md`, `AUDIT_AUTH_CREDENTIAL_DISCOVERY_V1.md`, `AUDIT_PROJECT_REQUIREMENTS_V1.md` e o manifesto local de requisitos. Somente o certifier pode autorizar APTO.

<!-- merge-enforcement: docs/quality/AUDIT_MERGE_ENFORCEMENT_V1.md -->

<!-- GLOBAL_TASK_CONTINUITY_V8 -->
## Global task continuity V8

Every repository task that can mutate code, infrastructure, data, CI, or deployment MUST create and maintain a durable checkpoint through `python3 scripts/agent_task_state.py`. This adapter targets the canonical A1 controller and stamps `repository=Vivaliz-site/amazon-returns-safet`.

Required lifecycle: `start` before substantive work; `progress` after material progress or before handing off; `ready` + `complete` only after fresh verification. A recoverable error remains `RUNNING`. `BLOCKED_EXTERNAL` is allowed only with objective external evidence and exhausted safe alternatives.

If the canonical controller is unavailable, the adapter fails closed. Do not create an unmonitored local substitute. Restore/use the A1 control plane before continuing work that depends on interruption recovery. Detached recovery does not reopen the same ChatGPT conversation.
<!-- /GLOBAL_TASK_CONTINUITY_V8 -->
