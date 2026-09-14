# Global Agent Continuity Controller Design

## Objetivo

Criar um mecanismo global de continuidade para impedir que trabalho de agentes fique órfão quando uma sessão ChatGPT/Codex/Claude/Gemini, processo remoto, terminal ou conexão é interrompida. A conclusão de uma tarefa deve deixar de depender da vida útil de uma conversa e passar a depender de estado persistente, verificável e retomável.

O sistema deve também auditar repositórios e hosts autorizados para localizar trabalho já abandonado: alterações locais sem commit, commits não enviados, branches à frente da `main`, branches sem PR, PRs sem merge, operações Git interrompidas, worktrees órfãs e tarefas sem heartbeat.

## Princípio operacional

Uma tarefa não termina quando o agente para. Ela termina somente quando atinge `MERGED + VERIFIED` ou quando existe bloqueio externo explícito, persistido e acionável.

Nenhuma alteração pode existir somente na memória ou conversa de um agente. Toda nova implementação deve possuir identidade, checkout isolado e estado persistente antes da primeira edição.

## Escopo

O controlador é global e suporta múltiplos repositórios e hosts, começando por `Vivaliz-site/amazon-returns-safet` e pelos hosts autorizados já usados pelo projeto. A arquitetura é genérica e não depende do domínio SAFE-T.

O rollout inclui:

1. ledger persistente de tarefas;
2. worktree isolada por tarefa;
3. heartbeat e lease de agente;
4. checkpoints seguros;
5. scanner Git local;
6. reconciliação com GitHub;
7. classificação automática de pendências;
8. Resume Packet determinístico;
9. fila `NEEDS_RESUME`;
10. retomada automática por Codex quando permitida;
11. fallback para outros agentes sem sobrescrever trabalho existente;
12. auditoria inicial das pendências existentes;
13. conclusão somente em `MERGED + VERIFIED`.

## 1. Task Ledger

Cada unidade de trabalho recebe `TASK_ID` imutável, por exemplo `TASK-20260913-001`.

Campos mínimos:

- `task_id`;
- `repository`;
- `host`;
- `worktree_path`;
- `branch`;
- `base_sha`;
- `current_head`;
- `agent_type`;
- `agent_session_id` quando disponível;
- `objective`;
- `status`;
- `classification`;
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

SQLite é o ledger inicial por ser local, transacional e não exigir infraestrutura adicional. Exportação JSON existe apenas para diagnóstico e interoperabilidade.

### Estado versus classificação

`status` representa a etapa do ciclo de vida da tarefa. `classification` representa a interpretação operacional do achado atual. São campos distintos e nunca devem ser usados como sinônimos.

Estados do fluxo:

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

Classificações operacionais:

```text
ACTIVE
NEEDS_RESUME
READY_FOR_PR
PR_BLOCKED
BLOCKED_EXTERNAL
SUPERSEDED
MERGED_UNVERIFIED
DONE
ORPHAN_UNKNOWN
```

Exemplo: uma tarefa pode ter `status=MERGED` e `classification=MERGED_UNVERIFIED` até passar pela validação pós-merge.

## 2. Isolamento por worktree

Cada nova tarefa de implementação trabalha em `git worktree` dedicada. Dois agentes não editam a mesma worktree simultaneamente.

Convenção:

```text
<controller-root>/worktrees/<repo>/<TASK_ID>/
```

Branch:

```text
agent/<TASK_ID>-<slug>
```

Checkouts exclusivos de deploy não podem ser reutilizados por agentes. Checkouts compartilhados antigos permanecem somente como fontes auditadas até migração completa.

## 3. Heartbeat e lease

Agente ativo renova heartbeat e lease. Quando `lease_expires_at` passa sem heartbeat, a tarefa muda para `AGENT_LOST`, recebe snapshot seguro e é reclassificada. Se houver trabalho recuperável, passa para `NEEDS_RESUME`.

Uma tarefa só pode possuir um lease ativo. Claim e renovação usam transação SQLite para impedir retomada concorrente.

## 4. Checkpoint seguro

Auto-commit cego é proibido.

Antes de checkpoint recuperável:

1. registrar `git status --porcelain=v2`;
2. capturar branch, HEAD, upstream e worktree;
3. detectar operação Git em andamento;
4. executar secret scan em arquivos novos/modificados;
5. excluir somente artefatos temporários definidos em política explícita;
6. preservar patch/diff quando commit não for seguro;
7. criar commit de checkpoint apenas quando permitido e secret scan estiver limpo.

Nunca executar automaticamente `git reset --hard`, `git clean`, troca forçada de branch ou sobrescrita de worktree com alterações não classificadas.

## 5. Repository Scanner

Por repositório/worktree, detectar:

- modified sem commit;
- staged sem commit;
- untracked;
- stashes;
- commits locais não enviados;
- branch sem upstream;
- ahead/behind/divergência contra branch base;
- detached HEAD;
- conflitos;
- merge/rebase/cherry-pick/revert interrompido;
- bisect ativo;
- worktrees bloqueadas ou órfãs;
- lock Git potencialmente abandonado sem removê-lo automaticamente;
- branch já incorporada à base;
- branch não incorporada sem `TASK_ID` correspondente.

## 6. GitHub Reconciler

Para cada repositório configurado, reconciliar:

- branches remotas;
- diferenças contra `main` ou branch base;
- PR aberto/fechado/merged;
- checks e Actions;
- branch com commits sem PR;
- PR pronto sem merge;
- PR bloqueado por check, conflito ou review;
- PR fechado sem merge com commits ainda exclusivos;
- commit incorporado à base por merge, squash ou cherry-pick;
- branches totalmente incorporadas candidatas a limpeza posterior.

Ausência de PR nunca significa ausência de trabalho.

## 7. Classificador de pendências

Cada achado recebe exatamente uma classificação:

- `ACTIVE`: agente saudável e lease válido;
- `NEEDS_RESUME`: trabalho recuperável ainda não concluído;
- `READY_FOR_PR`: mudanças commitadas/pushed e validações locais obrigatórias concluídas;
- `PR_BLOCKED`: PR existe e há impedimento corrigível;
- `BLOCKED_EXTERNAL`: depende de credencial, autorização, serviço externo ou ação humana inevitável;
- `SUPERSEDED`: substituído por trabalho posterior comprovado;
- `MERGED_UNVERIFIED`: integrado à base, mas validação final pendente;
- `DONE`: integrado e verificado;
- `ORPHAN_UNKNOWN`: alteração sem evidência suficiente para associá-la a uma tarefa; triagem obrigatória antes de editar.

A classificação é idempotente e recalculável a partir de evidências.

## 8. Resume Packet

Toda tarefa `NEEDS_RESUME` gera pacote determinístico contendo:

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
classification
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

O prompt de retomada ordena explicitamente:

- continuar da worktree existente;
- não recriar do zero;
- não descartar alterações;
- não sobrescrever trabalho concorrente;
- validar antes de commit/push;
- finalizar commit -> push -> PR -> checks -> merge -> verificação;
- registrar checkpoint antes de encerrar.

## 9. Resume Queue

Fila persistente e priorizada:

1. alteração local sem commit com risco de perda;
2. operação Git interrompida;
3. branch completa sem push;
4. branch pushed sem PR;
5. PR bloqueado por falha corrigível;
6. merge sem verificação;
7. tarefas antigas parcialmente implementadas.

Um único worker pode claimar cada `TASK_ID` por vez.

## 10. Dispatcher de agentes

Ordem preferencial:

1. Codex autenticado pelo fluxo ChatGPT Business já configurado;
2. sessão ChatGPT com acesso ao host/repositório;
3. Claude;
4. Gemini.

O dispatcher seleciona apenas agente disponível e nunca expõe segredo no Resume Packet. Falha por limite, expiração de sessão ou indisponibilidade devolve a tarefa para `NEEDS_RESUME` e tenta o próximo agente permitido. O fallback mantém a mesma branch e worktree.

## 11. Controlador persistente

O processo de continuidade executa fora da conversa. Em Linux, usar systemd. Em Windows autorizado, usar serviço/tarefa persistente equivalente apenas quando necessário.

Cadência recorrente padrão: 30 minutos, somada a eventos de início, fim e checkpoint. Cadência de 5 minutos é proibida.

O controlador sobrevive a logout, encerramento de terminal e reinício do host.

## 12. Auditoria inicial

Antes de controlar preventivamente o ambiente, executar inventário somente leitura de todos os repositórios/worktrees configurados.

Para cada achado registrar:

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
- idade da última atividade;
- provável agente/origem quando houver evidência;
- classificação;
- risco;
- próxima ação.

Nenhuma pendência da primeira varredura pode ser descartada automaticamente.

## Relação com GitHub

O estado operacional real é:

```text
estado local dos hosts
+
estado Git/GitHub remoto
+
ledger de tarefas
=
estado operacional real
```

GitHub sozinho não detecta alterações ainda não commitadas.

## Proteção contra concorrência

- exatamente um lease ativo por `TASK_ID`;
- worktree exclusiva por tarefa;
- lock transacional durante claim/checkpoint;
- comparação de `base_sha` e `HEAD` antes de escrita;
- se houver alteração concorrente no mesmo trecho, abortar e reclassificar;
- nunca aplicar patch destrutivo em checkout compartilhado;
- preservar branches e commits até confirmação de incorporação à base.

## Segurança

- nenhum token, cookie, senha ou segredo no ledger, logs ou Resume Packets;
- secret scan antes de checkpoint automatizado;
- logs com redaction;
- comandos Git destrutivos proibidos no modo automático;
- limpeza de branch/worktree somente depois de `DONE`, confirmação de incorporação e retenção configurada;
- nenhum navegador oculto é necessário;
- controlador API/CLI-first.

## Observabilidade

Métricas mínimas:

- tarefas por status e classificação;
- total `NEEDS_RESUME`;
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

Logs estruturados incluem `task_id`, `repo`, `host`, `agent` e `event`.

## Regras de conclusão

`DONE` exige simultaneamente:

1. nenhuma operação Git interrompida;
2. mudanças intencionais commitadas;
3. commits necessários pushed;
4. PR quando política do repositório exigir;
5. checks obrigatórios verdes;
6. mudança integrada à branch base;
7. validação pós-merge específica do projeto aprovada;
8. evidência persistida no ledger.

`AGENT_LOST`, sessão encerrada, limite de uso ou conversa interrompida nunca são estados finais.

## Testes obrigatórios

### Unitários

- transições válidas/inválidas do state machine;
- separação `status` x `classification`;
- expiração/renovação de lease;
- claim concorrente;
- parsing de status Git;
- detecção de operações interrompidas;
- ahead/behind;
- Resume Packet determinístico;
- redaction de segredos;
- prioridade da fila;
- idempotência do reconciliador.

### Integração

Usar repositórios Git temporários representando:

- dirty sem commit;
- staged sem commit;
- untracked;
- commit local não pushed;
- branch pushed sem PR simulado;
- merge interrompido;
- rebase interrompido;
- detached HEAD;
- branch totalmente merged;
- lease expirado;
- dois workers tentando claim simultâneo.

### Funcional

No rollout piloto:

1. iniciar tarefa real em worktree isolada;
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

Inventariar estado atual sem modificar branches/worktrees existentes e registrar achados `DISCOVERED`.

### Fase B - continuidade para novas tarefas

Novas implementações exigem `TASK_ID + worktree + lease + heartbeat`.

### Fase C - retomada assistida

Gerar Resume Packets e permitir claim de itens `NEEDS_RESUME` com controle transacional.

### Fase D - retomada automática

Habilitar dispatcher Codex e fallbacks somente após testes de isolamento, secret scanning e claim transacional.

### Fase E - migração das pendências antigas

Processar a fila inicial até cada achado ficar em `DONE`, `BLOCKED_EXTERNAL` ou `SUPERSEDED` com evidência explícita.

## Critérios de aceite

O sistema está implantado somente quando:

- controlador persiste fora de qualquer conversa;
- toda nova implementação recebe `TASK_ID` e worktree isolada;
- lease expirado gera `NEEDS_RESUME` sem perda de mudança;
- nenhum claim concorrente adquire a mesma tarefa;
- auditoria local + GitHub encontra as classes definidas;
- Resume Packet permite continuidade por novo agente;
- teste funcional real comprova interrupção e retomada até `MERGED + VERIFIED`;
- primeira auditoria dos repositórios/hosts cadastrados classifica cada pendência e define próxima ação;
- nenhuma alteração encontrada é apagada ou sobrescrita para simplificar reconciliação.

## Decisões explícitas

- SQLite como ledger inicial.
- Git worktree como unidade de isolamento.
- 30 minutos como cadência recorrente padrão, mais eventos de checkpoint.
- Codex como primeiro worker automático; ChatGPT, Claude e Gemini como fallbacks configuráveis.
- estado local dos hosts é obrigatório; GitHub sozinho não é suficiente.
- auto-commit cego é proibido.
- comandos Git destrutivos sobre checkouts de trabalho são proibidos.
- retenção/limpeza ocorrem somente após `DONE` e confirmação de incorporação.