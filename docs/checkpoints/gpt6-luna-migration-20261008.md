# Checkpoint — migração Codex → GPT-6 Luna medium (2026-10-08)

**Tarefa:** `gpt6-luna-migration-20261008`
**Estado:** `RUNNING` (não terminal). Sub-gate de hosts: `BLOCKED_EXTERNAL` com evidência abaixo.
**Ação executável restante:** decisão do proprietário (merge do PR do site; sair do draft do buscador e do amazon) e validação nos hosts quando houver rota.

## Estado do controlador canônico
- `scripts/agent_task_state.py start` (adapter Amazon) → `global_continuity_controller_unavailable`, `ok:false`.
- Script do site fora do deploy root grava em `storage/private/agent-task-state` (local, não monitorado). Não foi usado para não criar substituto não monitorado.
- Por isso este arquivo é o checkpoint durável em repositório, no próprio branch do PR.

## Hosts (evidência de 2026-10-08)
- Remote Control: `LAPTOP-NIG4IFUU`, `DESKTOP-KOCEPSV`, `shopvivaliz-free-a1`, `always-free-arm-1787907847-26` — todos **Offline** (último contato 24h–139h). Sessão do MCP logada em conta diferente do proprietário.
- SSH a partir do sandbox: `10.0.1.38:22`, `10.0.1.112:22`, `100.66.174.74:22` — sem resposta.
- Não verificados: `~/.codex/config.toml`, autenticação ChatGPT, chamada real ao modelo, modelo efetivo, persistência após reinício.

## Modelo
- `gpt-6-luna` aparece como identificador em fontes públicas de terceiros (default de esforço `medium`). Sem confirmação oficial da OpenAI nesta sessão.

## Plugins obrigatórios
- `GEPETO_UNAVAILABLE`: plugin não exposto no runtime desta sessão.
- `SUPERPOWERS_UNAVAILABLE`: plugin não exposto no runtime desta sessão.

## Validações locais (este branch)
- Site: bridge Node, core PHP, 65 testes OKX, 35 testes Python, contratos bash, instalação do bridge — passam.
- Buscador: bridge Node, core PHP, contrato — passam.
- Amazon: advisor e provisionamento PHP — passam.

## Próximo passo
1. Proprietário decide merge do PR do site (dispara deploy automático em produção).
2. Proprietário decide tirar buscador/amazon do draft.
3. Quando houver rota para os hosts: verificar config, autenticação, chamada real ao modelo e persistência, e então atualizar este checkpoint.
