# Instrucoes para Claude

Antes de qualquer trabalho neste repositorio, leia e cumpra `AGENTS.md` e `AI-TO-CLI-PROTOCOL.md`. A regra `Isolamento obrigatorio de sessao CLI por chat` e vinculante: este chat nao pode reutilizar sessao CLI pertencente a outro chat.

## Auditoria Extrema obrigatória
Antes de auditoria completa/extrema, validação de release/apto ou mudança material definida em `AUDIT_POLICY.md`, leia e execute integralmente `AUDIT_POLICY.md`, `docs/quality/EXTREME_AUDIT_PROTOCOL.md`, `docs/quality/AUDIT_RUNTIME_PARITY_V1.md`, `docs/quality/AUDIT_UNIVERSAL_COVERAGE_V1.md`, `docs/quality/ARCHITECTURE_DEPLOY_AUDIT_V1.md`, `docs/quality/AUDIT_SELF_TEST_V1.md` quando aplicável e `docs/quality/AUDIT_OVERLAY.md`. A auditoria inclui correção SAFE, arquitetura/deploy/código, reconciliação, falhas silenciosas, negativos/boundaries, unknown unknowns e reauditoria.
