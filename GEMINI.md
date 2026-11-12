<!-- OWNER_ONLY_RESTRICTIONS_20261110 -->
## Exclusividade do proprietário para criar ou agravar restrições (10/11/2026)

**Determinação mais recente do proprietário, válida para TODOS os repositórios, agentes e ambientes ShopVivaliz. Esta regra substitui, neste assunto, a política de dupla aprovação de 08/11/2026.**

- SOMENTE o próprio proprietário pode executar a inclusão, restauração ou ampliação de bloqueios, restrições, travas, gates, exigências de aprovação, negações de acesso ou qualquer regra/configuração que impeça, condicione ou atrase execuções, acessos, operações, comandos, automações, CI/CD, PRs, merge ou deploy.
- NENHUM agente, bot ou automação (GPT, Claude, Codex, Gemini, Copilot, Ollama, MCP, GitHub Actions, subagentes) pode introduzir tais mudanças, nem por commit, pull request, edição direta, workflow, ruleset, código, infraestrutura, configuração, políticas de acesso ou documento de governança. A mera aprovação, revisão dupla ou autorização de uma PR NÃO transfere ao agente o poder de implementar.
- Ao identificar uma restrição que considere necessária, o agente deve apresentar ao proprietário justificativa, impactos, riscos, alternativas, **comandos completos para execução pessoal**, validação e reversão. O agente não deve implementar, publicar ou preparar uma PR que contenha a inclusão restritiva.
- Correções técnicas, refatorações, ajustes de observabilidade e remoções/reduções de travas indevidas seguem o fluxo normal autorizado, desde que não introduzam outra restrição no mesmo diff.
- Esta decisão não desativa automaticamente MFA, RBAC, regras de plataforma, proteções GitHub, mecanismos de segurança exigidos por terceiros ou aprovações independentes anteriormente existentes.
- As PRs e comunicações de agentes continuam obrigatoriamente em **português brasileiro (pt-BR)**, preservando termos técnicos, código e caminhos.
- **Exceção única de implantação:** em 10/11/2026 o proprietário determinou expressamente que GPT aplicasse esta atualização inicial. Isso não autoriza agentes a criar futuras restrições, mesmo se aprovadas.
<!-- /OWNER_ONLY_RESTRICTIONS_20261110 -->

<!-- MANDATORY_AGENT_CHECKLIST_V1 -->
## Checklist proporcional: sem bloqueio global para tarefas rotineiras

**Revisao por determinacao do proprietario (06/11/2026):** foi revogada a obrigatoriedade universal de planejar e certificar um checklist a cada etapa, introduzida em 31/10/2026. A ausencia de `plan-checklist` nao pode impedir `record_progress`, retomada, correcao, teste ou conclusao de tarefas P0/P1 que ja possuam autorizacao. Um checkpoint duravel e uma proxima acao concreta continuam suficientes para registrar o trabalho; nao criar processo de aprovacao por subcomando.

`scripts/agent_task_state.py` deixa de injetar automaticamente um checklist exigivel em novos checkpoints do runtime canonico. Checkpoints anteriores com lista **vazia** nao ficam presos por um plano nunca iniciado. Para trabalhos que precisam de checklist formal, o executor pode ativar `SHOPVIVALIZ_REQUIRE_AGENT_CHECKLIST=1` no escopo do fluxo e usar `plan-checklist`, `next-step`, `check-step` e `add-checklist-step`. Se ja existem etapas planejadas, suas evidencias e integridade continuam obrigatorias para encerrar; nao apagar nem certificar etapas sem comprovacao.

Essa desburocratizacao nao autoriza falso sucesso nem remove controles independentes: identidade, MFA, RBAC, segredos, lease/fencing, aprovacoes humanas formalmente necessarias, branch protection, gates de operacoes comerciais, CI e validacao funcional/E2E pertinente. Nenhum controle novo deve ser imposto sem aprovacao do proprietario.
<!-- /MANDATORY_AGENT_CHECKLIST_V1 -->

<!-- EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->
> **AUDITORIA EXTREMA V5 = UI REAL OBRIGATORIA.** Antes de declarar `APTO` ou `CONCLUIDO`, o proprio agente percorre cada jornada material na interface grafica real (navegacao, cliques, preenchimento, resultado visivel, reload/revisita, persistencia e evidencia visual com ambiente/release). API, terminal, SQL, codigo, screenshot isolado, mocks e testes apenas headless nao substituem prova pela UI. A auditoria inclui investigacao, correcao e reauditoria visual apos reparo. Nao ocultar uma UI marcando `browser.required=false` ou `journey.ui=false`. Somente componentes comprovadamente sem UI podem usar excecao com inventario negativo documentado e evidencias. Em bloqueio de sessao, esgotar rotas autorizadas; nao burlar MFA/permissaos. Leia e obedeça a secao `EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1` de `AGENTS.md` antes da tarefa.
<!-- /EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->

<!-- AUDIT_EXTERNAL_REMEDIATION_V1 -->
> **AUDITORIA EXTERNA:** também é corretiva. Auditor com acesso autorizado corrige, testa e reaudita; auditor read-only não encerra em relatório, devendo encaminhar achados a executor autorizado até correção + revalidação independente ou bloqueio externo comprovado.
<!-- /AUDIT_EXTERNAL_REMEDIATION_V1 -->

<!-- DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->
> **DIAGNÓSTICO CORRETIVO GLOBAL:** diagnóstico confirmado obriga correção segura, prevenção pertinente, testes e validação real/E2E aplicável antes de `CONCLUIDO`; enquanto houver ação executável, permanecer `RUNNING`.
<!-- /DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->

<!-- GEMINI_READ_AGENTS_FIRST_V1 -->
> **BOOTSTRAP OBRIGATÓRIO DO GEMINI:** antes de analisar, planejar, editar, executar comandos, usar navegador, abrir PR, fazer merge, deploy ou validar qualquer tarefa, leia integralmente o `AGENTS.md` da raiz deste repositório e siga suas regras. Releia em retomadas ou quando o arquivo mudar. Em conflito, `AGENTS.md` prevalece.

# Protocolo IA-to-CLI obrigatório

<!-- GLOBAL_BROWSER_VM_POLICY_V2 -->
> **NAVEGAÇÃO GLOBAL — VM OBRIGATÓRIA; WINDOWS PROIBIDO PARA BROWSER:** qualquer navegador, automação browser, sessão gráfica, Playwright/Selenium/CDP, Chrome/Chromium/Edge/Opera, CAPTCHA, MFA, consentimento ou validação visual deve usar por padrão e obrigatoriamente a VM backend `always-free-arm-1787907847-26` (`10.0.1.38`) e o Browser Worker privado. Para intervenção humana, usar `https://shopvivaliz.com.br/admin/browser-worker.php`. **Fred-Win (`LAPTOP-NIG4IFUU`) e `DESKTOP-KOCEPSV` não são destinos nem fallback para navegação.** Não perguntar qual máquina usar para browser: use a VM. Exceção somente se o proprietário ordenar explicitamente, na tarefa atual, o uso de um Windows específico para aquela navegação. Se a VM estiver indisponível, reparar o caminho VM/OCI Bastion/túnel privado ou registrar bloqueio real; nunca migrar silenciosamente para Windows. Workflows/relays Windows de browser são legado e não devem ser executados até serem migrados. A regra não proíbe Windows para tarefas não-browser que realmente dependam dele.


Antes de qualquer alteração, carregue e siga integralmente o protocolo canônico:

@./AI-TO-CLI-PROTOCOL.md

Ele complementa as regras específicas do projeto. Nenhuma alteração válida da tarefa pode ser abandonada sem merge validado na branch de destino.

## Auditoria Extrema — leitura obrigatória

Em qualquer Auditoria Extrema, leia primeiro `AUDIT_POLICY.md` e todo o conjunto em `docs/quality/`: `EXTREME_AUDIT_PROTOCOL.md`, `AUDIT_RUNTIME_PARITY_V1.md`, `AUDIT_UNIVERSAL_COVERAGE_V1.md`, `AUDIT_SELF_TEST_V1.md` quando aplicável e `AUDIT_OVERLAY.md`. A regra vale para investigação, correção, melhorias, reauditoria e caça a unknown unknowns.

<!-- EXECUTION_PROVENANCE_POLICY_V1 -->
Leia e cumpra EXECUTION-PROVENANCE-POLICY.md antes de qualquer execucao material.

<!-- GLOBAL_TASK_CONTINUITY_V8 -->
## Global task continuity V8

Every repository task that can mutate code, infrastructure, data, CI, or deployment MUST create and maintain a durable checkpoint through `python3 scripts/agent_task_state.py`. This adapter targets the canonical A1 controller and stamps `repository=Vivaliz-site/amazon-returns-safet`.

Required lifecycle: `start` before substantive work; `progress` after material progress or before handing off; `ready` + `complete` only after fresh verification. A recoverable error remains `RUNNING`. `BLOCKED_EXTERNAL` is allowed only with objective external evidence and exhausted safe alternatives.

If the canonical controller is unavailable, the adapter fails closed. Do not create an unmonitored local substitute. Restore/use the A1 control plane before continuing work that depends on interruption recovery. Detached recovery does not reopen the same ChatGPT conversation.
<!-- /GLOBAL_TASK_CONTINUITY_V8 -->

<!-- MARINA_MCP_AS_USER_20261112 -->
> **MCP Marina: executar como `ubuntu` e root do backend (2026-11-12):** `marina_admin_shell` aceita o argumento opcional `as_user="ubuntu"` (sudo -n -u ubuntu, auditado por hash; exclusivo com `as_root`; qualquer outro usuario e recusado). Ativo na Marina desde a release `eb81e37c` (grant PR #3918 do `site-shopvivaliz`). No backend, o usuario `root` tambem tem os MCPs `shopvivaliz-marina-admin` e `shopvivaliz-remote-control` e a credencial do Marina MCP ja provisionada. Fonte de verdade e procedimento: `Vivaliz-site/site-shopvivaliz` em `docs/knowledge/marina-mcp-admin-agent-access.md`. Credenciais continuam so em arquivos protegidos (0600), sem expor valores.
