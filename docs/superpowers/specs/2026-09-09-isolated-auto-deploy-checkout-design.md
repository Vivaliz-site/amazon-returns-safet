# Isolated Auto-Deploy Checkout Design

## Objetivo

Separar completamente o checkout usado pelo auto-deploy do checkout usado por agentes e operadores, para que alterações locais, troca de branch, mudanças de permissão ou trabalho em andamento nunca bloqueiem o deploy automático da `main`.

## Problema confirmado

O timer `amazon-returns-deploy.timer` executa `scripts/auto-deploy.sh` apontando para `/home/ubuntu/amazon-returns-safet`. Esse mesmo checkout é usado por outros agentes. Em produção foi confirmado que ele estava na branch `fix/safet-expired-worker-fallback` e tinha duas alterações locais de modo de arquivo nos scripts do autenticador/browser. O auto-deploy detectou checkout sujo e registrou `auto_deploy_skipped=dirty_checkout`, impedindo a publicação da `main` mesmo com CI verde.

## Arquitetura aprovada

Criar um checkout exclusivo para deploy, por exemplo `/home/ubuntu/amazon-returns-deploy-source`, dedicado somente a acompanhar `origin/main`. Nenhum agente de desenvolvimento deve trabalhar nesse diretório.

O serviço `amazon-returns-deploy.service` continuará chamando o mesmo `scripts/auto-deploy.sh`, mas passará `AMAZON_RETURNS_REPO=/home/ubuntu/amazon-returns-deploy-source`. O script continuará exigindo checkout limpo, CI verde e fast-forward antes de chamar `provision-production.sh`.

O checkout de trabalho `/home/ubuntu/amazon-returns-safet` permanece intacto e pode conter branches ou alterações locais sem interferir no deploy.

## Provisionamento

O provisionamento de produção deve garantir que o checkout exclusivo exista, tenha `origin` apontando para `Vivaliz-site/amazon-returns-safet`, esteja sincronizado com `origin/main` e seja propriedade de `ubuntu`. A atualização do checkout exclusivo deve ser conservadora: fetch + checkout/reset somente dentro desse diretório dedicado, nunca no checkout de trabalho.

A unidade systemd deve receber explicitamente a variável `AMAZON_RETURNS_REPO` com o caminho do checkout exclusivo. O timer permanece com a nova cadência de 1 hora, eliminando o último ciclo de 5 minutos.

## Segurança e isolamento

- Nunca executar `reset --hard`, `clean` ou troca de branch no checkout de trabalho dos agentes.
- O checkout exclusivo não deve conter credenciais novas; reutiliza a autenticação Git já autorizada para o usuário `ubuntu`.
- O deploy continua bloqueado se o checkout exclusivo estiver inesperadamente sujo, evitando publicar estado não rastreado.
- O release ativo continua em `/home/ubuntu/amazon-returns-deploy/current` e não muda de responsabilidade.
- Não alterar a arquitetura TOTP/autenticador que está sendo trabalhada por outro agente.

## Comportamento esperado

1. Um agente pode deixar `/home/ubuntu/amazon-returns-safet` em qualquer branch e com alterações locais.
2. O auto-deploy usa apenas `/home/ubuntu/amazon-returns-deploy-source`.
3. Quando `main` tiver CI verde, o checkout exclusivo avança para esse SHA e o provisionamento publica a release.
4. Alterações locais no checkout de trabalho não produzem `auto_deploy_skipped=dirty_checkout`.
5. O timer efetivo em produção usa `OnUnitActiveSec=3600`, nunca `300`.

## Testes obrigatórios

### Testes automatizados

- Contrato do service exige `AMAZON_RETURNS_REPO=/home/ubuntu/amazon-returns-deploy-source`.
- Contrato do timer exige `OnUnitActiveSec=3600` e proíbe `OnUnitActiveSec=300`.
- Teste do script confirma que o caminho default/explícito do auto-deploy é o checkout dedicado.
- Testes existentes de CI, segurança, PHP, Node e TOTP permanecem verdes.

### Validação funcional em produção

- Confirmar que `/home/ubuntu/amazon-returns-safet` continua com o trabalho concorrente intacto.
- Confirmar que `/home/ubuntu/amazon-returns-deploy-source` está limpo e em `main`.
- Confirmar via `systemctl cat amazon-returns-deploy.service` que o serviço usa o checkout exclusivo.
- Confirmar via `systemctl cat amazon-returns-deploy.timer` que a cadência é 3600s.
- Executar o serviço de deploy uma vez e confirmar que não retorna `dirty_checkout` por alterações existentes no checkout de trabalho.
- Confirmar release ativo igual ao SHA esperado da `main` e health da aplicação `OK`.

## Critério de conclusão

A mudança só é concluída quando CI, merge, auto-deploy e validação funcional real em produção comprovarem simultaneamente que o checkout de trabalho pode estar sujo sem bloquear deploy, que o serviço usa o checkout dedicado e que produção não possui mais timer de 5 minutos.