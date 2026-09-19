# Regras de Agentes — política sincronizada ShopVivaliz

Fonte canônica global: `Vivaliz-site/site-shopvivaliz/REGRAS-AGENTES-CENTRALIZADAS.md`.

<!-- SUPERPOWERS_EVERY_STAGE_V1 -->
## @Superpowers obrigatório em cada etapa de toda conversa/agente

Esta regra é vinculante para **todos os agentes, chats, conversas, sessões e retomadas que operem projetos ShopVivaliz** (Claude, Codex, Gemini, GPT e demais agentes).

- Toda tarefa deve iniciar sob **@Superpowers** e permanecer sob essa metodologia até a conclusão validada.
- **Não basta invocar ou mencionar @Superpowers uma única vez.** Ao entrar em cada etapa material, o agente deve reaplicar o workflow/skill de Superpowers adequado à fase.
- As etapas materiais incluem, no mínimo: bootstrap/contexto, planejamento, investigação, coleta de evidências, implementação, debugging sistemático, TDD/testes, revisão, correções, PR/checks/merge, deploy, validação pós-deploy, auditoria caso a caso e encerramento.
- Comandos de continuidade como **“retome”, “continue”, “prossiga” ou equivalentes** devem recuperar o último checkpoint comprovado e continuar com @Superpowers; não recomeçar do zero nem abandonar a metodologia.
- Em transições de fase, selecionar explicitamente a disciplina Superpowers aplicável (por exemplo planejamento, TDD, debugging, revisão/validação) antes de executar a próxima ação.
- Se o runtime atual não expuser a capacidade @Superpowers, **não fingir que ela foi chamada**: registrar `SUPERPOWERS_UNAVAILABLE`, aplicar manualmente a mesma disciplina de planejamento/TDD/debugging/verificação e continuar.
- A resposta final só pode ser emitida depois que as etapas aplicáveis tiverem sido executadas e validadas com evidência independente.

Esta regra é contínua e prevalece sobre hábitos de sessão que tratem Superpowers apenas como bootstrap inicial.
