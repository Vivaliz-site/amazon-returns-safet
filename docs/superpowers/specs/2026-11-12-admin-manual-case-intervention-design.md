# Intervenção administrativa manual em casos Amazon Returns

O sistema permanece autônomo por padrão. O administrador pode intervir em qualquer caso, independentemente do estado.

Objetivos: ações em qualquer estado; encerramento, reabertura, classificação de recuperação, alteração de estado e edição operacional; auditoria before/after, usuário, data e justificativa; API tenant-scoped com CSRF, versão e idempotência.

Não objetivos: não enviar automaticamente SAFE-T, recurso, e-mail ou chamado Amazon; não alterar reconciled_credit_amount; não apagar histórico.

Abordagem escolhida: endpoint administrativo dedicado + serviço transacional + tabela amazon_return_overrides já existente + evento idempotente ADMIN_CASE_OVERRIDE.

`RECOVERED` é estado terminal financeiro e exige crédito real conciliado suficiente para cobrir `expected_reimbursement_amount` positivo. Se o administrador indicar recuperação antes da conciliação, `MARK_RECOVERED` e `SET_STATE: RECOVERED` registram uma indicação administrativa **somente na trilha de auditoria**, mantendo estado, prazo e automação inalterados; não fecham o caso. Os campos de enum são normalizados antes da gravação. Escritas externas continuam sujeitas aos fluxos e verificações existentes.

Critérios: API e UI mostram ações em qualquer estado; concorrência e replay seguros; histórico persistido; crédito reconciliado preservado; testes e UI real pós-deploy.
