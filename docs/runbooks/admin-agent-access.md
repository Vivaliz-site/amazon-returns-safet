# Acesso administrativo de agentes

O cockpit aceita a conta administrativa humana existente e contas de agente configuradas fora do repositório.

## Configuração protegida

No arquivo de ambiente protegido da aplicação, configure `AMAZON_RETURNS_ADMIN_USERS_JSON` como um objeto JSON de nomes de usuário para hashes produzidos por `password_hash()`. Exemplo estrutural, com valores fictícios:

```dotenv
AMAZON_RETURNS_ADMIN_USERS_JSON={"Fred":"$2y$EXEMPLO_HASH_FICTICIO","returns-agent":"$2y$OUTRO_HASH_FICTICIO"}
```

O hash da senha de cada conta deve ser gerado e instalado pelo operador diretamente no cofre/arquivo de ambiente protegido. Não use a senha em texto no arquivo, no repositório, em logs ou no chat. Permissões do arquivo devem permanecer restritas ao usuário do serviço.

Quando essa variável contém JSON válido, ela substitui a configuração administrativa única antiga. Se não estiver definida, permanecem válidos `AMAZON_RETURNS_ADMIN_USERNAME` e `AMAZON_RETURNS_ADMIN_PASSWORD_HASH` para compatibilidade.

O identificador de sessão usa o nome canônico definido no JSON. A comparação do nome de usuário ignora maiúsculas/minúsculas. JSON inválido falha fechado e não recorre às credenciais legadas.

## Permissões e limites

A conta autenticada recebe o mesmo acesso administrativo ao cockpit que a conta humana existente. Isso não altera grants do sistema operacional, segredos da Amazon, controles de lease/fencing, aprovações nem gates de escrita externa. A sessão mantém expiração por inatividade de 1 hora e limite absoluto de 12 horas.

## Uso pelos agentes
- Identidade do cockpit: usuário `returns-agent`.
- Cofre protegido na Marina: `/home/ubuntu/.shopvivaliz/secrets/amazon-returns-agent.password`, modo `0600`. O agente deve acessá-lo pelo MCP autorizado da Marina apenas no momento do login e usá-lo dentro do processo; nunca imprimir, colocar em argumentos, logs, issues ou chat.
- Não solicitar essa credencial ao proprietário enquanto o arquivo estiver disponível. Não copiar cookies entre perfis; cada identidade deve usar sua própria sessão.
- A conta tem acesso administrativo ao cockpit e continua sujeita aos gates de escrita externa já existentes. Ela não concede permissões de sistema operacional nem acesso a credenciais da Amazon.
