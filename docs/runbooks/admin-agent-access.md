# Acesso administrativo de agentes

O cockpit aceita a conta administrativa humana existente e contas de agente configuradas fora do repositório.

## Configuração protegida

No arquivo de ambiente protegido da aplicação, configure `AMAZON_RETURNS_ADMIN_USERS_JSON` como um objeto JSON de nomes de usuário para hashes produzidos por `password_hash()`. Exemplo estrutural, com valores fictícios:

```dotenv
AMAZON_RETURNS_ADMIN_USERS_JSON={"Fred":"$2y$EXEMPLO_HASH_FICTICIO","returns-agent":"$2y$OUTRO_HASH_FICTICIO"}
```

O hash da senha de cada conta deve ser gerado e instalado pelo operador diretamente no cofre/arquivo de ambiente protegido. Não use a senha em texto no arquivo, no repositório, em logs ou no chat. Permissões do arquivo devem permanecer restritas ao usuário do serviço.

## Instruções para agentes

- Use o usuário canônico `returns-agent` para autenticação automatizada do cockpit quando essa conta estiver provisionada; não use a conta pessoal `Fred` para identificar uma execução de agente.
- O agente deve obter a senha somente pela rota segura provisionada no ambiente e nunca pedir, imprimir, copiar ou registrar o valor em prompt, log, issue, PR ou evidência.
- O agente deve validar apenas o resultado da autenticação e o escopo efetivo da sessão; o nome do usuário não concede permissões adicionais nem habilita escritas externas na Amazon.
- Se `returns-agent` não autenticar, preserve a evidência sanitizada do erro e siga o fluxo de descoberta de credenciais e rotas autorizado em `AGENTS.md`; não tente contornar MFA, RBAC ou controles de sessão.

Quando essa variável contém JSON válido, ela substitui a configuração administrativa única antiga. Se não estiver definida, permanecem válidos `AMAZON_RETURNS_ADMIN_USERNAME` e `AMAZON_RETURNS_ADMIN_PASSWORD_HASH` para compatibilidade.

O identificador de sessão usa o nome canônico definido no JSON. A comparação do nome de usuário ignora maiúsculas/minúsculas. JSON inválido falha fechado e não recorre às credenciais legadas.

## Permissões e limites

A conta autenticada recebe o mesmo acesso administrativo ao cockpit que a conta humana existente. Isso não altera grants do sistema operacional, segredos da Amazon, controles de lease/fencing, aprovações nem gates de escrita externa. A sessão mantém expiração por inatividade de 1 hora e limite absoluto de 12 horas.
