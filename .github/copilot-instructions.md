<!-- MANDATORY_AGENT_CHECKLIST_V1 -->
## Checklist proporcional — sem exigencia universal

Por autorizacao expressa do proprietario (06/11/2026), nenhuma tarefa P0/P1 previamente autorizada fica bloqueada por falta de checklist transacional formal, `plan-checklist` ou certificacao de cada subcomando. Registrar evidencias reais e `next_action` no checkpoint. O checklist pode ser ativado quando necessario, e seus itens ja planejados continuam exigindo provas verdadeiras para encerrar.

Preservar continuidade ate concluir com verificacao, testes e CI pertinentes. Nao diminuir MFA, RBAC, sigilo, protecao de branch, revisao humana de grants, leases/fencing, protecoes comerciais e recusas de provedores. E proibido reintroduzir burocracia geral sem consentimento do proprietario.
<!-- /MANDATORY_AGENT_CHECKLIST_V1 -->

# Agent completion protocol
Read and obey `AI-TO-CLI-PROTOCOL.md` before executing repository work. The section `PROTOCOLO OBRIGATORIO DE CONCLUSAO DE TAREFAS` is binding: do not stop at partial progress or a recoverable tool failure; continue until CONCLUIDO or a genuine external BLOQUEADO state.

<!-- MARINA_MCP_AS_USER_20261112 -->
> **MCP Marina: executar como `ubuntu` e root do backend (2026-11-12):** `marina_admin_shell` aceita o argumento opcional `as_user="ubuntu"` (sudo -n -u ubuntu, auditado por hash; exclusivo com `as_root`; qualquer outro usuario e recusado). Ativo na Marina desde a release `eb81e37c` (grant PR #3918 do `site-shopvivaliz`). No backend, o usuario `root` tambem tem os MCPs `shopvivaliz-marina-admin` e `shopvivaliz-remote-control` e a credencial do Marina MCP ja provisionada. Fonte de verdade e procedimento: `Vivaliz-site/site-shopvivaliz` em `docs/knowledge/marina-mcp-admin-agent-access.md`. Credenciais continuam so em arquivos protegidos (0600), sem expor valores.
