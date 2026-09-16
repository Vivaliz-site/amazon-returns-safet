# Auditoria Extrema

## Comando canonico

Quando o usuario disser apenas `auditoria extrema`, este repositorio deve interpretar o comando como a execucao integral do padrao definido em `docs/superpowers/specs/2026-09-16-real-e2e-audit-governance-design.md`.

Nao e necessario que o usuario repita criterios, escopo, evidencias ou regras de validacao.

## Regra de classificacao

Uma rotina obrigatoria so pode ser classificada como `APTO` quando houver evidencia de todos os estagios aplicaveis:

1. entrada real pelo ponto de entrada efetivamente usado pelo usuario ou sistema;
2. processamento interno pelo caminho de producao correspondente;
3. efeito real no sistema de destino, quando houver side effect externo;
4. readback ou reconciliacao independente do resultado no destino;
5. evidencia registrada com identificadores e timestamps reproduziveis.

Se qualquer estagio obrigatorio nao puder ser comprovado, a rotina e `NAO APTO`.

Um projeto so pode ser `APTO` quando todas as suas rotinas obrigatorias estiverem `APTO` ou formalmente classificadas como `OUT_OF_SCOPE` com justificativa aprovada.
## Evidencias que nao bastam sozinhas

Nenhum dos itens abaixo prova prontidao por si so:

- codigo existente;
- teste unitario ou mock passando;
- CI verde;
- processo ou servico ativo;
- endpoint de health respondendo `OK`;
- fila vazia ou job marcado como concluido;
- log local dizendo `submitted`, `accepted`, `queued` ou `200 OK`;
- feature flag/configuracao existente, mas sem execucao real;
- integracao externa sem confirmacao do destino.

Esses sinais podem compor a evidencia, mas nunca substituir a prova ponta a ponta.

## Auditoria contraditoria

Depois do happy path, a auditoria deve tentar provar que a rotina nao esta pronta. Testar, quando aplicavel: gate de escrita desligado, credencial/sessao indisponivel, worker/scheduler parado ou atrasado, fila travada, dependencia externa indisponivel, duplicacao/retry, resposta incerta apos escrita, readback divergente, dado stale e erro de escopo/tenant/permissao.
## Readback obrigatorio

Toda escrita externa precisa ser confirmada no sistema de destino. A ordem preferida e: API oficial, consulta suportada no destino, UI autenticada e, por ultimo, reconciliacao independente com fonte autoritativa.

Sem readback ou reconciliacao equivalente, a rotina permanece `NAO APTO` ou explicitamente pendente de validacao.

## UI, automacao e autonomia

Se a funcao e operada pela UI, a auditoria precisa executar a UI real ao menos uma vez. Teste apenas de backend nao prova que o usuario consegue concluir a tarefa.

Se a funcao e autonoma, a auditoria precisa provar o scheduler/daemon/worker real, sua cadencia, ultima execucao valida, ausencia de starvation/dead letters e capacidade de operar sem maquinas locais quando essa independencia faz parte da arquitetura.

## Saude funcional

Uma capacidade obrigatoria indisponivel deve degradar o health do projeto. Gate obrigatorio desligado, credencial inutilizavel, worker/scheduler stale, backlog travado ou ausencia de readback nao podem coexistir com status global `OK`.

## Regra de regressao

Todo defeito encontrado durante `auditoria extrema` deve gerar uma protecao duravel apropriada: teste automatizado, invariante de runtime, regra de health, alerta, reconciliacao ou probe de producao. Corrigir apenas o sintoma nao encerra a auditoria.