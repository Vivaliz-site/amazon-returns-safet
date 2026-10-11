<!-- RESUME_EXECUTION_GATE_V1 -->
## Retome / Continue / Prossiga / Siga: execucao obrigatoria ate verificacao

Toda ordem Retome, Continue, Prossiga ou Siga e uma tarefa operacional de execucao, nao pedido de plano, status ou confirmacao generica. O agente principal, Claude, Gemini, Copilot e qualquer subagente devem ler as regras atuais do AGENTS.md no main e registrar prova nao secreta rules_loaded (fonte, ref, SHA, data) antes de mutacoes. Recuperar task_id, ultimo checkpoint e next_action com origem real; nao criar identidades de conversa ou sessoes ficticias.
Executar a proxima acao permitida; investigar erro, corrigir a causa, testar, fazer merge/deploy quando autorizado e validar o objetivo original no runtime. Persistir RUNNING com next_action real enquanto houver caminho seguro viavel; erro de ferramenta, timeout, status Pensando, PR ou ACK nao autorizam encerrar. CONCLUIDO exige evidencia fresca ponta a ponta; BLOCKED_EXTERNAL exige impedimento externo objetivo, alternativas seguras esgotadas e condicao exata de retomada. Quando disponivel, comprovar terminalidade com agent_task_state.py terminal --task <task_id>.
Nao contornar MFA, RBAC, aprovacao sensivel, branch protection ou direitos de outra sessao. Evitar mensagens intermediarias desnecessarias quando o proprietario pedir para nao postar; se limite externo interromper a execucao, salvar checkpoint verdadeiro e nao prometer trabalho de fundo sem mecanismo realmente ativo. Encaminhar as mesmas obrigacoes ao agente delegado.
<!-- /RESUME_EXECUTION_GATE_V1 -->

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
