# Acesso MCP dos agentes aos hosts ShopVivaliz

**Inventário operacional atualizado em 11/11/2026.** Este runbook substitui instruções de acesso remoto anteriores ao cutover e utiliza como fonte vigente o `main` de `Vivaliz-site/site-shopvivaliz`: `docs/knowledge/atendimento-chatgpt-bootstrap.md`, `docs/knowledge/host-access.md`, `docs/knowledge/agent-rules.md` e `docs/ops/mcp-first-agent-execution-2026-11-05.md`.

## Hosts operacionais

| Host | Função | Rota |
| --- | --- | --- |
| `marina-a1-4c24g` | Produção web, Amazon Returns, bancos, workers, releases e runners | **ShopVivaliz Remote Control MCP** com grant válido, identidade e lease verificados |
| `always-free-arm-1787907847-26` | Backend, controlador, MCP, navegador corporativo e integrações | **ShopVivaliz Remote Control MCP** |
| `Fred-Win` | Estação Windows de apoio | MCP central, conforme autenticação e escopo da sessão |
| `KOCEPSV` | Estação Windows auxiliar | MCP central somente quando o relay estiver efetivamente conectado |

A VM de origem `shopvivaliz-free-a1` foi excluída. Seus aliases, comandos de bootstrap, IPs e perfis operacionais de setembro de 2026 são apenas históricos e não devem ser reaproveitados.

## Evidências recentes (11/11/2026)

- MCP central `admin_command_run` para Marina: `hostname -s; id -un` devolveu `marina-a1-4c24g` / `ubuntu`, exit code 0.
- MCP central para backend: `hostname -s` confirmou `always-free-arm-1787907847-26`, serviço da controladora ativo.
- MCP central para Fred-Win: `hostname; whoami` retornou `LAPTOP-NIG4IFUU` e a identidade local `fred`, exit code 0.
- A tentativa MCP para KOCEPSV encontrou `127.0.0.1:2223 connection refused`. O catálogo anunciar o host não prova transporte funcional; esta rota permanece sem certificação até novo readback.

Esses testes comprovam execução dos comandos citados, **não** habilitam escrita SAFE-T, acesso total, navegador autenticado ou conclusão do controller `continuity_ready`.

## Procedimento MCP

1. Descobrir o host e a operação permitidos no **schema vigente** do conector ShopVivaliz Remote Control; confirmar identidade real e autorização efetiva, não apenas `hosts_list`.
2. Para comando curto e inócuo, utilizar `admin_command_run` com timeout de até cinco segundos. Para duração maior, utilizar a fila oficial `task_submit` com `request_id` estável, consultar `task_status` até estado terminal e exigir leitura posterior do efeito.
3. Antes de mutação, confirmar ownership, lease/fencing, regras do serviço, branch protection, revisão formal de grants quando aplicável e ausência de executor concorrente. Não liberar lock de outro operador.
4. Em produção, preservar releases imutáveis e realizar deploy somente pelo mecanismo canônico. Para SAFE-T, consultar Seller Central/SP-API, financeiro e warehouse, verificar elegibilidade/idempotência e usar grants específicos.
5. Para UI, usar as ferramentas MCP de navegador e desktop publicadas para o host e perfil autorizado, com validação visual, reload e persistência; shell e HTTP 200 não substituem E2E de interface.
6. Em erro de transporte, diagnosticar causa e verificar rotas de manutenção **independentemente autorizadas**. Uma recusa de MFA, RBAC, segurança do provedor, lease ou grant não pode ser contornada por outro canal.

Nunca imprimir, versionar ou compartilhar em logs/prompts chaves privadas, cookies, senhas, tokens, OTPs, cabeçalhos de autenticação ou segredos de configuração. Operações de recuperação OCI/SSH exigem provas de identidade, host key e escopo próprios; não utilizar aliases históricos como destino.

## Rastreabilidade

Instruções substituídas de setembro/outubro permanecem no histórico Git para auditoria. Esta revisão é documental: não altera serviços dos hosts, não cria permissões e não prova disponibilidade de sessão gráfica em todos os destinos.
