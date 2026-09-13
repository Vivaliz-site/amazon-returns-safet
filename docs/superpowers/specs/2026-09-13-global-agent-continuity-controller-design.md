# Global Agent Continuity Controller Design

## Objetivo

Criar um mecanismo global de continuidade para impedir que trabalho de agentes fique órfão quando uma sessão ChatGPT/Codex/Claude/Gemini, processo remoto, terminal ou conexão é interrompida. A conclusão de uma tarefa deve deixar de depender da vida útil de uma conversa e passar a depender de estado persistente, verificável e retomável.

O sistema deve também executar uma auditoria recorrente dos repositórios e hosts autorizados para localizar trabalho já abandonado: alterações locais sem commit, commits não enviados, branches à frente da `main`, branches sem PR, PRs sem merge, operações Git interrompidas, worktrees órfãs e tarefas sem heartbeat.

## Princípio operacional

Uma tarefa não termina quando o agente para. Ela termina somente quando atinge `MERGED + VERIFIED` ou quando existe um bloqueio externo explícito, persistido e acionável.

Nenhuma alteração pode existir somente na memória ou conversa de um agente. Todo trabalho de implementação deve possuir identidade, checkout isolado e estado persistente antes da primeira edição.

## Escopo

O controlador é global e deve suportar múltiplos repositórios e múltiplos hosts, começando pelo `Vivaliz-site/amazon-returns-safet` e pelos hosts autorizados já usados pelo projeto. A arquitetura não deve depender de detalhes específicos do SAFE-T; os adaptadores de repositório e host devem ser genéricos.

O primeiro rollout deve incluir:

1. ledger persistente de tarefas;
2. worktree isolada por tarefa;
3. heartbeat e lease de agente;
4. checkpoints seguros;
5. scanner Git local;
6. reconciliação com GitHub;
7. classificação automática de pendências;
8. Resume Packet determinístico;
9. fila `NEEDS_RESUME`;
10. retomada automática por Codex quando permitido;
11. fallback para outros agentes sem sobrescrever trabalho existente;
12. auditoria inicial de todas as pendências encontradas;
13. relatório de conclusão somente quando `MERGED + VERIFIED`.

## Componentes

### 1. Task Ledger

Fonte persistente de verdade sobre cada unidade de trabalho. Cada tarefa recebe um `TASK_ID` imutável, por exemplo `TASK-20260913-001`.

Campos mínimos:

- `task_id`;
- `repository`;
- `host`;
- `worktree_path`;
- `branch`;
- `base_sha`;
- `agent_type`;
- `agent_session_id` quando disponível;
- `objective`;
- `status`;
- `last_heartbeat_at`;
- `lease_expires_at`;
- `last_checkpoint_sha`;
- `dirty_files`;
- `untracked_files`;
- `ahead_count`;
- `behind_count`;
- `pull_request`;
- `ci_state`;
- `verification_state`;
- `blocker`;
- `next_action`;
- `created_at`;
- `updated_at`.

O ledger deve usar armazenamento local simples e transacional. SQLite é o padrão recomendado para o controlador, com exportação JSON somente para diagnóstico e interoperabilidade.

### 2. Isolamento por worktree

Cada tarefa de implementação trabalha em uma `git worktree` dedicada. Dois agentes não devem editar o mesmo checkout de trabalho simultaneamente.

Convenção:

```text
<controller-root>/worktrees/<repo>/<TASK_ID>/
```

Convenção de branch:

```text
agent/<TASK_ID>-<slug>
```

Checkouts exclusivos de deploy existentes não devem ser reutilizados por agentes. Checkouts compartilhados antigos continuam somente como fontes auditadas até migração completa.

### 3. Heartbeat e lease

Agentes ativos renovam um heartbeat periódico. O ledger associa um lease à tarefa.

Estados principais:

```text
DISCOVERED
QUEUED
CLAIMED
IMPLEMENTING
TESTING
READY_FOR_PR
PR_OPEN
MERGING
MERGED
VERIFYING
VERIFIED
DONE
BLOCKED
AGENT_LOST
NEEDS_RESUME
SUPERSEDED
```

Quando `lease_expires_at` passa sem heartbeat, a tarefa não é encerrada. Ela muda para `AGENT_LOST`, recebe snapshot, é classificada e entra em `NEEDS_RESUME` se houver trabalho recuperável.

### 4. Checkpoint seguro

O sistema não deve executar auto-commit cego.

Antes de criar checkpoint recuperável deve:

1. registrar `git status --porcelain=v2`;
2. capturar branch, HEAD, upstream e worktree;
3. verificar operação Git em andamento;
4. executar secret scan sobre arquivos novos/modificados;
5. excluir padrões de artefatos temporários configurados;
6. preservar patch/diff mesmo quando commit automático não for seguro;
7. somente criar commit de checkpoint quando a política da tarefa permitir e o secret scan estiver limpo.

Nunca executar `git reset --hard`, `git clean`, troca forçada de branch ou sobrescrita de worktree com alterações não classificadas.

### 5. Repository Scanner

O scanner local deve detectar por repositório/worktree:

- arquivos modified sem commit;
- staged sem commit;
- untracked;
- stashes;
- commits locais não enviados;
- branch sem upstream;
- branch à frente da branch base;
- branch atrás/divergente;
- detached HEAD;
- conflitos;
- merge interrompido;
- rebase interrompido;
- cherry-pick interrompido;
- revert interrompido;
- bisect ativo;
- worktrees bloqueadas ou órfãs;
- arquivos de lock Git abandonados, sem removê-los automaticamente;
- branch aparentemente incorporada à `main`;
- branch não incorporada sem tarefa correspondente.

### 6. GitHub Reconciler

Para cada repositório configurado, reconciliar estado local com GitHub:

- branches remotas;
- diferença contra `main` ou branch base;
- PR aberto/fechado/merged;
- checks/Actions;
- branch com commits mas sem PR;
- PR pronto mas não merged;
- PR com merge bloqueado;
- PR fechado sem merge e commits ainda exclusivos;
- commit presente em `main` por squash/cherry-pick;
- branches já totalmente incorporadas e candidatas a limpeza posterior.

O reconciliador não deve inferir que ausência de PR significa ausência de trabalho.

### 7. Classificador de pendências

Cada achado recebe exatamente uma classificação operacional:

- `ACTIVE`: agente saudável e lease válido;
- `NEEDS_RESUME`: existe trabalho recuperável que ainda não atingiu conclusão;
- `READY_FOR_PR`: código commitado/pushed e validações locais necessárias concluídas;
- `PR_BLOCKED`: PR existe, mas há check/conflito/review impeditivo;
- `BLOCKED_EXTERNAL`: depende de credencial, autorização, serviço externo ou ação humana inevitável;
- `SUPERSEDED`: substituído por trabalho posterior confirmado;
- `MERGED_UNVERIFIED`: merge realizado, validação final pendente;
- `DONE`: merged e verificado;
- `ORPHAN_UNKNOWN`: há alteração, mas não há evidência suficiente para associá-la a uma tarefa; requer triagem antes de edição.

A classificação deve ser idempotente e recalculável a partir de evidências.

### 8. Resume Packet

Toda tarefa `NEEDS_RESUME` deve produzir um pacote completo e determinístico para qualquer agente autorizado continuar sem reconstruir contexto pela conversa.

Conteúdo obrigatório:

```text
TASK_ID
repository
host
worktree_path
branch
base_sha
current_head
objective
status
last_agent
last_heartbeat
commits_since_base
changed_files
untracked_files
staged_files
patch_summary
operation_in_progress
local_test_results
CI_state
PR_state
known_failures
blockers
next_action
safety_constraints
completion_criteria
```

O prompt final deve ordenar explicitamente:

- continuar exatamente da worktree existente;
- não recriar a implementação do zero;
- não descartar alterações;
- não sobrescrever mudanças de outro agente;
- validar antes de commit/push;
- finalizar commit -> push -> PR -> checks -> merge -> verificação;
- registrar novo checkpoint antes de encerrar.

### 9. Resume Queue

A fila é persistente e priorizada.

Ordem padrão:

1. alteração local não commitada com risco de perda;
2. operação Git interrompida;
3. branch com trabalho completo sem push;
4. branch pushed sem PR;
5. PR bloqueado por falha corrigível;
6. merge realizado sem verificação;
7. tarefas antigas parcialmente implementadas.

Uma tarefa só pode ser claimed por um agente por vez. O claim usa lease transacional para evitar dois agentes retomando o mesmo trabalho.

### 10. Dispatcher de agentes

Ordem preferencial inicial:

1. Codex autenticado pelo fluxo ChatGPT Business configurado no ambiente;
2. sessão ChatGPT com acesso ao host/repositório;
3. Claude;
4. Gemini.

O dispatcher deve selecionar apenas agentes realmente disponíveis no host e nunca expor segredos no Resume Packet.

Falha por limite, expiração de sessão ou indisponibilidade devolve a tarefa para `NEEDS_RESUME` e tenta o próximo agente permitido. O fallback não cria nova branch nem nova worktree: continua na mesma unidade de trabalho.

### 11. Controlador persistente

O processo de continuidade deve executar fora da conversa do agente. Em Linux, usar serviço systemd; em Windows autorizado, serviço/tarefa persistente equivalente apenas quando necessário.

Cadência padrão de reconciliação: 30 minutos, além de eventos explícitos de início/fim/checkpoint. Não usar cadência de 5 minutos.

O controlador deve sobreviver a logout, encerramento de terminal e reinício do host.

### 12. Auditoria inicial

Antes de assumir controle preventivo, executar inventário somente leitura de todos os repositórios e worktrees configurados.

A auditoria inicial produz, para cada achado:

- repositório;
- host;
- caminho;
- branch;
- HEAD;
- arquivos alterados;
- commits exclusivos;
- upstream;
- PR relacionado;
- CI;
- idade aproximada da última atividade;
- provável agente/origem quando houver evidência;
- classificação;
- risco;
- ação recomendada.

Nenhuma pendência encontrada nessa primeira varredura pode ser descartada automaticamente.

## Relação com GitHub

O GitHub é a fonte remota de commits, PRs e CI, mas não enxerga mudanças ainda locais. Portanto, a auditoria correta é sempre a união:

```text
estado local dos hosts
+
estado Git/GitHub remoto
+
ledger de tarefas
=
estado operacional real
```

A ausência de PR aberto não é suficiente para marcar um repositório como limpo.

## Proteção contra concorrência

- exatamente um lease ativo por `TASK_ID`;
- worktree exclusiva por tarefa;
- lock transacional no ledger durante claim/checkpoint;
- comparação de `base_sha` e `HEAD` antes de qualquer escrita;
- abortar e reclassificar quando outra alteração concorrente aparecer no mesmo trecho;
- nunca aplicar patch destrutivo sobre checkout compartilhado;
- preservar branches e commits até confirmação de incorporação à base.

## Segurança

- nenhum token, cookie, senha ou segredo no ledger, logs ou Resume Packets;
- secret scan antes de checkpoint automatizado;
- logs com redaction;
- comandos destrutivos Git proibidos no modo automático;
- limpeza de branch/worktree somente depois de `DONE`, confirmação de incorporação e período de retenção;
- nenhum processo oculto de navegador é requisito do controlador;
- controlador deve funcionar API/CLI-first.

## Observabilidade

Métricas mínimas:

- tarefas por status;
- tarefas `NEEDS_RESUME`;
- idade da pendência mais antiga;
- worktrees sujas;
- branches ahead sem PR;
- PRs bloqueados;
- `MERGED_UNVERIFIED`;
- leases expirados;
- retomadas bem-sucedidas;
- fallbacks de agente;
- tempo entre `AGENT_LOST` e novo claim;
- tarefas concluídas `MERGED + VERIFIED`.

Logs devem ser estruturados e conter `task_id`, `repo`, `host`, `agent` e `event`.

## Regras de conclusão

Uma tarefa pode ser `DONE` apenas quando:

1. não há operação Git interrompida;
2. mudanças intencionais estão commitadas;
3. commits necessários estão pushed;
4. existe PR quando a política do repositório exigir;
5. checks obrigatórios passam;
6. mudança está integrada à branch base;
7. validação pós-merge específica do projeto passou;
8. ledger registra evidência de verificação.

`AGENT_LOST`, sessão encerrada, limite de uso ou conversa interrompida nunca são estados finais.

## Testes obrigatórios

### Unitários

- transições válidas e inválidas do state machine;
- expiração/renovação de lease;
- claim concorrente;
- parsing de status Git;
- detecção de operações interrompidas;
- classificação de ahead/behind;
- geração determinística de Resume Packet;
- redaction de segredos;
- prioridade da fila;
- idempotência do reconciliador.

### Integração

Criar repositórios Git temporários representando:

- arquivo dirty sem commit;
- staged sem commit;
- untracked;
- commit local não pushed;
- branch pushed sem PR simulado;
- merge interrompido;
- rebase interrompido;
- detached HEAD;
- branch totalmente merged;
- tarefa com lease expirado;
- retomada concorrente por dois workers.

### Funcional

No rollout piloto:

1. iniciar uma tarefa real em worktree isolada;
2. registrar heartbeat;
3. interromper deliberadamente o worker;
4. confirmar `AGENT_LOST -> NEEDS_RESUME`;
5. gerar Resume Packet;
6. retomar com novo worker na mesma worktree;
7. finalizar testes, commit, push e PR;
8. mergear;
9. validar o resultado real pós-merge;
10. confirmar `DONE` somente depois da evidência de verificação.

## Rollout

### Fase A - auditoria somente leitura

Inventariar estado atual sem modificar branches/worktrees existentes. Criar ledger com achados `DISCOVERED` e classificações iniciais.

### Fase B - continuidade para novas tarefas

Novas tarefas passam obrigatoriamente por `TASK_ID + worktree + lease + heartbeat`.

### Fase C - retomada assistida

Gerar Resume Packets e deixar agentes claimarem itens `NEEDS_RESUME` com controle de concorrência.

### Fase D - retomada automática

Habilitar dispatcher Codex e fallbacks somente após os testes de isolamento, secret scanning e claim transacional passarem.

### Fase E - migração das pendências antigas

Processar a fila inicial uma tarefa por vez até que todo achado esteja em `DONE`, `BLOCKED_EXTERNAL` ou `SUPERSEDED` com evidência explícita.

## Critérios de aceite

O sistema é considerado implantado somente quando:

- o controlador persiste fora de qualquer conversa;
- toda nova tarefa de implementação recebe `TASK_ID` e worktree isolada;
- lease expirado gera `NEEDS_RESUME` sem perda de mudanças;
- nenhuma retomada concorrente consegue adquirir a mesma tarefa;
- a auditoria local + GitHub encontra as classes de pendências definidas;
- Resume Packet contém informação suficiente para um novo agente continuar;
- um teste funcional real prova interrupção e retomada até `MERGED + VERIFIED`;
- a primeira auditoria dos repositórios/hosts cadastrados foi concluída e cada pendência recebeu classificação e próxima ação;
- nenhuma alteração encontrada foi apagada ou sobrescrita para simplificar a reconciliação.

## Decisões explícitas

- SQLite como ledger inicial, sem banco de infraestrutura adicional.
- Git worktree como unidade de isolamento.
- 30 minutos como cadência recorrente padrão, mais eventos de checkpoint.
- Codex como primeiro worker automático; ChatGPT, Claude e Gemini como fallbacks configuráveis.
- GitHub sozinho não é suficiente: estado local dos hosts é obrigatório para detectar trabalho ainda sem commit.
- auto-commit cego é proibido.
- comandos Git destrutivos sobre checkouts de trabalho são proibidos.
- retenção e limpeza são etapas posteriores a `DONE`, nunca mecanismo de resolução de pendências.