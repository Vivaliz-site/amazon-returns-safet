# Instrucoes para Claude


<!-- SUPERPOWERS_EVERY_STAGE_V1 -->
> **@Superpowers CONTÍNUO E OBRIGATÓRIO:** toda conversa, sessão, agente e retomada de tarefa ShopVivaliz deve usar @Superpowers **em cada etapa material**, não apenas no início. Reaplique a disciplina adequada ao passar por bootstrap/contexto, planejamento, investigação, coleta de evidências, implementação, debugging, TDD/testes, revisão, correção, PR/checks/merge, deploy, pós-deploy, auditoria e encerramento. Em `retome/continue/prossiga`, continue do último checkpoint comprovado sob @Superpowers. Se o runtime não expuser @Superpowers, registre `SUPERPOWERS_UNAVAILABLE` e aplique a metodologia equivalente sem fingir a chamada. Fonte local: `REGRAS-AGENTES-CENTRALIZADAS.md`; fonte canônica: `Vivaliz-site/site-shopvivaliz`.

Antes de qualquer trabalho neste repositorio, leia e cumpra `AGENTS.md` e `AI-TO-CLI-PROTOCOL.md`. A regra `Isolamento obrigatorio de sessao CLI por chat` e vinculante: este chat nao pode reutilizar sessao CLI pertencente a outro chat.

## Auditoria de casos — leitura obrigatória

Quando a tarefa pedir auditoria dos casos, análise caso a caso, revisão das decisões tomadas ou intenção equivalente, leia e execute `docs/quality/CASE_BY_CASE_AUDIT_PROTOCOL.md` antes de concluir qualquer julgamento. A auditoria deve reconstruir cada caso individualmente, derivar decisão esperada de forma independente, comparar com decisão/execução reais e remediar achados seguros. Resumo agregado, status do motor ou REVIEW genérico não substituem essa análise.

## Auditoria Extrema — leitura obrigatória

Antes de executar ou revisar Auditoria Extrema, leia e cumpra `AUDIT_POLICY.md`, `docs/quality/EXTREME_AUDIT_PROTOCOL.md`, `docs/quality/AUDIT_RUNTIME_PARITY_V1.md`, `docs/quality/AUDIT_UNIVERSAL_COVERAGE_V1.md`, `docs/quality/AUDIT_SELF_TEST_V1.md` quando aplicável e `docs/quality/AUDIT_OVERLAY.md`. Corrija achados SAFE executáveis, inclua melhorias obrigatórias e não encerre com classe material `NÃO VALIDADO`.

## Auditoria Extrema obrigatoria
Antes de auditoria completa/extrema, validacao de release/apto ou mudanca material definida em `AUDIT_POLICY.md`, leia e execute integralmente `AUDIT_POLICY.md`, `docs/quality/EXTREME_AUDIT_PROTOCOL.md`, `docs/quality/AUDIT_RUNTIME_PARITY_V1.md`, `docs/quality/AUDIT_UNIVERSAL_COVERAGE_V1.md`, `docs/quality/ARCHITECTURE_DEPLOY_AUDIT_V1.md`, `docs/quality/AUDIT_SELF_TEST_V1.md` quando aplicavel e `docs/quality/AUDIT_OVERLAY.md`. Inclua arquitetura/deploy/codigo, reconciliacao, falhas silenciosas, negativos/boundaries, unknown unknowns e reauditoria.
