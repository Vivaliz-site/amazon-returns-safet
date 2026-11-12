# Plano — intervenção administrativa manual

Tarefa 1: serviço transacional de override; testes RED/GREEN para ações, before/after, tenant scope, crédito protegido e conflito de versão. Inclui registro somente de auditoria para recuperação não conciliada, sem criar estado terminal, além de normalização de enums.

Tarefa 2: endpoint POST com autenticação admin, CSRF, request_id idempotente e ações CLOSE_LOSS, REOPEN, MARK_RECOVERED, SET_STATE, UPDATE_FIELDS.

Tarefa 3: UI de ações administrativas no detalhe de todos os casos; justificativa, confirmação, atualização da tela e aviso de que classificação não é resultado externo.

Tarefa 4: suíte completa, lint, revisão do diff, PR pt-BR, CI, merge, auto-deploy e validação UI sem mutar casos reais.
