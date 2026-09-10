# Regras obrigatórias de entrega e operação dos agentes

**Origem:** instruções expressas de Frederico em 05/09/2026, no projeto Amazon Returns / SAFE-T.
**Escopo:** `Vivaliz-site/amazon-returns-safet`, incluindo seus worktrees, testes, documentação, PRs, Actions e implantação. A referência aos outros repositórios define o padrão de qualidade; não autoriza modificá-los nesta tarefa.
**Memória persistente:** [MEMORIA-DO-PROJETO.md](MEMORIA-DO-PROJETO.md). Entrada obrigatória para agentes: [AGENTS.md](../AGENTS.md).

## 1. Responsabilidade de ponta a ponta
O agente executa a tarefa; não se limita a diagnóstico, plano, sugestão ou alteração parcial. Depois de cada etapa, identifica e executa a próxima ação necessária. Mantém um checklist com pendências, dependências, validações e evidências até o objetivo original ser atendido.

O ciclo obrigatório é **inspecionar → reproduzir → corrigir → testar → revisar → commitar → publicar → conferir CI → fazer merge → acompanhar auto gate → validar produção**. Etapas sem aplicação devem ter justificativa concreta. Uma atualização exclusivamente documental não dispensa revisão, CI, merge e verificação do release implantado.

Não declarar conclusão por ter criado PR, obtido teste verde, feito merge ou recebido HTTP 200. A conclusão técnica exige o código pretendido realmente implantado. A homologação funcional exige, separadamente, evidência do comportamento solicitado.

## 2. Nenhuma alteração abandonada
No início e antes da entrega, inventariar `git status`, diferenças staged/unstaged, arquivos não rastreados, stashes, commits não publicados, branches e worktrees deste repositório. Registrar a origem e a finalidade de cada pendência relevante.

Toda alteração válida do trabalho — código, teste, documentação, configuração não secreta e artefato pertinente — deve ser revisada, commitada, publicada e integrada. Deixar apenas em uma pasta local, stash, commit sem push ou branch sem integração não conclui a tarefa.

Mudanças antigas ou concorrentes devem ser analisadas, não sobrescritas. Trabalho superado permanece recuperável no histórico; registrar qual implementação o substituiu e demonstrar que os requisitos aplicáveis foram absorvidos. Não restaurar uma regra obsoleta apenas para obter um merge. Um arquivo de preservação não substitui a conclusão da funcionalidade ainda necessária.

Não apagar arquivos, executar limpeza destrutiva, forçar checkout ou remover worktrees com dados únicos para produzir artificialmente um status limpo. Não versionar `.env`, tokens, chaves privadas, cookies, bancos de produção, dados pessoais ou logs privados; proteger esses dados fora dos arquivos de entrega e registrar somente referências seguras.

## 3. Testes, revisão e integração
Para defeitos, criar primeiro o teste que reproduz a falha e observar o resultado negativo; corrigir e observar o resultado positivo. Executar a suíte completa, lint, build quando existir, integração, smoke test e validação funcional aplicáveis. Revisões independentes não substituem a inspeção do diff e a execução real dos testes pelo agente responsável.

Uma alteração funcional **não pode ser considerada concluída** sem **teste funcional de ponta a ponta** do fluxo afetado e confirmação do efeito persistido/externo esperado. Teste unitário, CI verde, deploy, health ou smoke isolado não substituem essa homologação. Se a execução real for insegura ou impossível por bloqueio externo comprovado, registrar o bloqueio e manter a alteração como não concluída.

Antes do merge, conferir novamente o HEAD do PR, a base atual e as verificações exigidas para **aquele SHA**. Uma aprovação de versão antiga não autoriza uma nova alteração não revisada. Resolver conflitos preservando mudanças válidas; repetir os testes depois da integração. Não usar força administrativa para ignorar checks ou revisão obrigatória.

## 4. PRs e Actions sem abandono
Verificar a lista completa de PRs e execuções pendentes do repositório, e não apenas o PR criado na sessão. Não encerrar a tarefa deixando seus PRs abertos, em rascunho, em conflito, com checks falhos ou aguardando merge. Trabalho concorrente ativo deve ter responsável, objetivo e estado registrados, sem ser confundido com abandono ou cancelado arbitrariamente.

Corrigir imediatamente falhas das execuções necessárias, publicar a correção e acompanhar a nova execução. Reexecutar um job só resolve quando a causa é transitória e o resultado é verificado. Não cancelar Actions necessárias, dispensar checks ou fechar PRs para esconder pendências. Um PR realmente substituído pode ser encerrado com referência ao substituto e evidência da incorporação de todos os requisitos ainda aplicáveis.

## 5. Deploy exclusivamente pelo auto gate existente
O caminho de implantação é `scripts/auto-deploy.sh`, acionado pelo `amazon-returns-deploy.service` e pelo timer instalado. O agente faz as alterações no repositório e acompanha o gate; não publica arquivos avulsos em produção nem força o symlink do release para contornar o processo.

O gate verifica o checkout e os checks de `origin/main` antes da implantação. `auto_deploy_skipped=dirty_checkout` e `auto_deploy_skipped=ci_not_green` não são sucesso: identificar a causa, corrigir o que estiver no escopo autorizado e acompanhar novamente. `already_current` só é suficiente quando o SHA em produção é o pretendido e as verificações funcionais passam.

Confirmar o SHA do merge e o conteúdo de `/home/ubuntu/amazon-returns-deploy/current/.release-sha`; acompanhar o journal do deploy e o serviço da aplicação; conferir saúde, logs e comportamento real. Se outra sessão avançar `main`, verificar a ancestralidade do commit pretendido e testar o release sucessor efetivamente implantado. Registrar ambos os SHAs; não afirmar igualdade quando existe apenas ancestralidade.

Se o deploy falhar, o agente investiga e corrige na mesma execução, repete testes e CI, publica o ajuste e acompanha o auto gate até sucesso comprovado. Não transferir ao usuário um erro operacional solucionável. Não encerrar apenas porque um comando terminou com código zero.

## 6. Segurança e writes externos
Não confundir homologação técnica com autorização para disparar reivindicações, recursos, e-mails ou chamados. Cada canal exige dados reais, ausência de duplicidade, prazo e destinatário/canal válidos, histórico consolidado e aceitação verificável antes da ativação controlada.

A primeira tentativa operacional ShopVivaliz é D+45, conforme a instrução vigente registrada no projeto; a elegibilidade efetiva da Amazon continua obrigatória. Se a Amazon pedir espera, retomar no canal correto na data solicitada, com nova verificação financeira. Não reintroduzir D+75 nem tratar a aprovação do pedido como crédito recebido.

Somente crédito financeiro real reconciliado autoriza `RECOVERED`. Não desligar validações, ignorar autenticação, ultrapassar limites de permissão nem remover proteções para aparentar conclusão. Manter o runtime antigo desabilitado e preservado até a homologação completa prevista no projeto.

## 7. Bloqueio externo real
Interromper apenas diante de impedimento externo comprovado que exija atuação do usuário ou do provedor: por exemplo, MFA, CAPTCHA, autorização negada ou indisponibilidade fora do controle do agente. Tentar alternativas legítimas e proporcionais; uma rota técnica alternativa não autoriza contornar uma negação de acesso.

Nesse caso, registrar causa, evidência, tentativas, SHAs, alterações protegidas e ponto exato de retomada. Publicar um checkpoint seguro e completo do trabalho realizado quando permitido; marcar a entrega como **bloqueada**, não concluída. Não prometer trabalho assíncrono nem afirmar que a memória pessoal de uma ferramenta foi atualizada sem confirmação.

## 8. Evidências mínimas da entrega
Registrar escopo, arquivos integrados, commit de implementação, SHA do merge, SHA implantado, resultado das Actions correspondentes, testes executados e horário absoluto da validação. Registrar também o estado da fila, dos canais externos e das pendências funcionais quando relevantes.

Comandos de referência; executar no checkout correto e nunca imprimir segredos:
```bash
git status --porcelain=v1 -uall
git diff --check
git worktree list --porcelain
git stash list
set -e
for test in tests/*.php; do php "$test"; done
php scripts/audit-tenant-sql.php
find includes api admin workers scripts -name '*.php' -print0 | xargs -0 -n1 php -l
node --check scripts/amazon-returns/safe-t-status-parser.mjs
node --check scripts/amazon-returns/seller-central-safe-t-read-worker.mjs
node --check scripts/amazon-returns/seller-central-bridge-worker.mjs
bash -n scripts/auto-deploy.sh scripts/provision-production.sh scripts/verify-live-tenant-foundation.sh
gh pr list --repo Vivaliz-site/amazon-returns-safet --state open
gh run list --repo Vivaliz-site/amazon-returns-safet --limit 20
cat /home/ubuntu/amazon-returns-deploy/current/.release-sha
systemctl show amazon-returns-safet.service -p ActiveState -p SubState -p UnitFileState
journalctl -u amazon-returns-deploy.service -n 30 --no-pager
curl --fail --silent --show-error https://returns.shopvivaliz.com.br/api/health.php
```
O status de cada worktree e o resultado de cada check obrigatório devem ser inspecionados. As listas acima não conferem sucesso automaticamente; usar os resultados reais para decidir se a tarefa pode ser encerrada.
