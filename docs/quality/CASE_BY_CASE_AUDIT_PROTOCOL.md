# Protocolo obrigatório de Auditoria Inteligente Caso a Caso

**Status:** regra permanente do repositório
**Escopo:** Amazon Returns / SAFE-T
**Ativação:** qualquer pedido de auditoria dos casos, análise caso a caso, validação das decisões tomadas, investigação individual das devoluções/SAFE-T ou equivalente semântico.

Este protocolo complementa `AUDIT_POLICY.md`, `AGENTS.md`, `docs/REGRAS-DE-ENTREGA.md`, `docs/MEMORIA-DO-PROJETO.md` e os demais documentos de qualidade. Ele não reduz nenhuma regra de segurança, elegibilidade, idempotência, tenant isolation, write gate, autenticação ou deploy.

## 1. Regra de ativação

Este protocolo é obrigatório quando a solicitação contiver, mesmo com redação diferente, intenção equivalente a:

- "auditoria dos casos";
- "auditar cada caso";
- "analisar caso a caso";
- "verificar todos os casos";
- "investigar cada devolução";
- "verificar se as decisões foram corretas";
- "revisar as decisões tomadas pelo sistema";
- "auditar SAFE-Ts individualmente";
- "verificar casos parados/incorretos";
- "reconstruir o que aconteceu em cada pedido";
- qualquer pedido que exija julgar a correção da decisão ou da execução de casos individuais.

A auditoria não pode ser substituída por contagem agregada, dashboard, amostragem, status geral, health check, resumo do motor de decisão ou inspeção exclusiva de casos já sinalizados como problemáticos.

Se a solicitação também caracterizar Auditoria Extrema pelos gatilhos de `AUDIT_POLICY.md`, execute **ambos**: este protocolo caso a caso e o conjunto completo da Auditoria Extrema.

## 2. Objetivo obrigatório

Para **cada caso elegível**, reconstruir independentemente os fatos e responder:

> Considerando todas as evidências reais disponíveis até o instante analisado, o sistema tomou a decisão correta, no momento correto, executou a ação correta e confirmou o efeito real esperado?

Não validar uma decisão porque o próprio motor a registrou como correta.

A auditoria deve produzir duas conclusões independentes:

1. **DECISÃO ESPERADA** — derivada das regras vigentes e das evidências reais do caso;
2. **DECISÃO REAL** — o que o sistema efetivamente decidiu e executou.

Depois comparar:

`DECISÃO ESPERADA × DECISÃO REAL × EFEITO REAL`

## 3. Princípio de independência

Cada caso deve ser tratado como investigação própria.

É proibido:

- assumir que casos com o mesmo status têm a mesma causa;
- inferir correção pela quantidade total de casos;
- concluir pelo status atual sem reconstruir a linha do tempo;
- usar apenas o resultado do decision engine como prova;
- considerar "job processado", "fila vazia", "HTTP 200", "email enviado", "claim aprovado" ou "ação enfileirada" como efeito final;
- encerrar auditoria apenas com diagnóstico, relatório ou lista de pendências corrigíveis.

Casos marcados como concluídos também entram no escopo quando forem elegíveis, porque encerramento incorreto ou prematuro é uma classe de falha.

## 4. Fonte das regras

Antes de julgar um caso, determine as regras vigentes no momento aplicável a partir das fontes autoritativas do projeto, incluindo:

- `AGENTS.md`;
- `docs/MEMORIA-DO-PROJETO.md`;
- `docs/REGRAS-DE-ENTREGA.md`;
- runbooks e ADRs aplicáveis;
- versão da política de tenant/conexão;
- regras oficiais do programa Amazon aplicável;
- contratos, schema, migrations e testes que expressem comportamento vigente.

Não ressuscite automaticamente regra histórica supersedida.

Quando houver conflito entre regra interna vigente e requisito oficial do canal, preserve a evidência, aplique a hierarquia definida pelo projeto e sinalize o conflito quando ele impedir decisão segura.

## 5. Escopo de casos

Audite **100% dos casos elegíveis dentro do escopo solicitado**, sem amostragem.

Se houver filtro temporal, use o marco temporal explicitamente definido pela solicitação ou pela política vigente. Não substitua silenciosamente "data do reembolso" por "data da venda", "data do pedido" ou outro campo.

Quando a solicitação não definir janela, priorize todos os casos ativos, pendentes, bloqueados, em revisão, em crédito pendente e encerrados recentemente relevantes ao risco atual; se a tarefa exigir cobertura total, cubra todos os elegíveis.

Registre explicitamente o critério de inclusão e exclusão.

## 6. Identificação mínima por caso

Para cada caso, identifique quando aplicável:

- tenant;
- Amazon connection/seller;
- order ID;
- return ID;
- shipment ID;
- SAFE-T Claim ID;
- support case/thread relacionada;
- ASIN;
- SKU;
- item/quantidade;
- data da venda;
- data do envio;
- data de entrega/tentativa;
- data de devolução;
- data do reembolso ao comprador;
- data de débito ao seller;
- valor debitado;
- valor esperado de ressarcimento;
- valor efetivamente creditado;
- datas oficiais de espera/reabertura;
- prazos de recurso;
- estado atual;
- versão da regra/política aplicada.

## 7. Reconstrução cronológica obrigatória

Monte uma linha do tempo factual antes de julgar.

A linha do tempo deve separar, quando aplicável:

`venda → envio → entrega/tentativa → devolução → reembolso ao comprador → débito do seller → rastreamento/retorno físico → abertura SAFE-T → resposta Amazon → recurso → promessa/aprovação → crédito financeiro → devolução ERP → encerramento`

Eventos fora de ordem, duplicados ou contraditórios devem ser investigados.

A auditoria deve avaliar não apenas **o que** foi feito, mas **quando** foi feito.

Para cada ação temporalmente relevante, calcule ou determine:

`evento gatilho → decisão esperada → prazo/data permitida → decisão real → atraso/adiantamento`

Uma ação conceitualmente correta executada depois do prazo continua sendo falha.

## 8. Fontes de evidência

Cruze todas as fontes materiais disponíveis e apropriadas ao caso, priorizando APIs e fontes primárias:

- SP-API Orders;
- SP-API Reports/Returns;
- Finances;
- SAFETReimbursementEventList ou fonte financeira equivalente vigente;
- banco de dados da aplicação;
- cases;
- events;
- evidence;
- outbox;
- decision snapshots;
- policy snapshots;
- cursors/checkpoints;
- Gmail/API de email;
- mensagens Amazon;
- Seller Central quando a API não resolver;
- SAFE-T;
- Seller Flex quando aplicável ao fluxo logístico;
- transportadora/rastreamento oficial;
- evidência de recebimento físico pelo seller;
- ERP Tiny/Olist;
- logs de workers;
- scheduler;
- bridges;
- health/telemetria;
- histórico de tentativas externas.

Browser é fallback para ação/evidência que não esteja disponível por API ou fonte primária equivalente.

## 9. Logística e rastreamento

Quando transporte for material, determine concretamente:

1. o pedido foi entregue ao comprador?
2. houve apenas tentativa de entrega?
3. a entrega se tornou impossível?
4. o pacote entrou em retorno?
5. o retorno foi realmente concluído?
6. para quem ele retornou?
7. existe confirmação física de recebimento pelo seller?
8. a Amazon ou transportadora permaneceu com o item?
9. o comprador permaneceu com o item?
10. existe perda logística?
11. existe divergência entre rastreamento, Amazon, banco e realidade física?
12. alguma inferência de "retornando" foi tratada indevidamente como recebimento físico?

Status de transportadora ou Seller Central não substitui, quando a regra exigir, a evidência de recebimento físico real pelo seller.

A evidência de chegada física deve obedecer a fonte definida pela política vigente do projeto.

## 10. Reembolso do comprador é diferente de ressarcimento do seller

Nunca confundir:

- **refund/reembolso ao comprador**; e
- **reimbursement/ressarcimento ao seller ShopVivaliz**.

Mensagem da Amazon dizendo que "o pedido foi reembolsado" não prova que a ShopVivaliz recebeu o crédito.

Quando houver dúvida semântica, leia o conteúdo completo e determine a quem o dinheiro foi destinado.

## 11. Reconciliação financeira

Para cada caso financeiro, determine:

- houve débito ao seller?
- valor e moeda;
- data efetiva do débito;
- houve aprovação/promessa de ressarcimento?
- qual prazo foi informado?
- houve crédito financeiro real?
- valor creditado;
- data do crédito;
- ClaimId/referência financeira;
- associação do crédito com o caso correto;
- crédito parcial ou total;
- divergência entre approval e settlement;
- duplicidade de crédito;
- saldo ainda em risco.

**Aprovação, promessa ou mensagem de reembolso não significa dinheiro recuperado.**

Enquanto não houver crédito real reconciliado, o caso não pode ser considerado recuperado apenas por status declarativo.

Use os estados internos vigentes, preservando o princípio:

`approval/promise → ainda pendente de confirmação financeira`

`crédito efetivamente reconciliado → recuperação comprovada`

## 12. Espera ou ressarcimento futuro informado pela Amazon

Se a Amazon instruir aguardar até determinada data:

1. preserve a mensagem/evidência original;
2. preserve a data exata informada;
3. não invente outra data;
4. revalide financeiro/físico quando a data vencer;
5. retome no canal correto já existente;
6. não crie claim/thread duplicada;
7. se o crédito prometido não ocorreu, continue a contestação/recuperação legítima conforme elegibilidade e regras vigentes.

Uma data ambígua deve ser tratada segundo a política vigente de revisão, nunca inferida silenciosamente.

## 13. Auditoria do ciclo SAFE-T

Para cada claim ou oportunidade de claim, verifique:

- havia elegibilidade real?
- havia prejuízo não resolvido?
- o gatilho operacional vigente foi alcançado?
- o claim inicial deveria ser automático ou manual para aquela classe?
- o claim foi aberto?
- foi aberto dentro do prazo?
- foi reutilizado um claim existente em vez de duplicar?
- evidências corretas foram anexadas?
- argumento correspondia aos fatos?
- Amazon respondeu?
- a resposta foi classificada corretamente?
- havia pedido de espera/data?
- havia direito/possibilidade de recurso?
- o recurso foi feito no prazo?
- novas evidências relevantes foram consideradas?
- uma negativa foi tratada corretamente?
- havia outro canal legítimo aplicável?
- o sistema parou indevidamente em REVIEW/BLOCKED?
- todas as possibilidades legítimas aplicáveis foram realmente esgotadas antes de considerar perda final?

Respeite write gates, eligibility gates, idempotência e regras de abertura manual aplicáveis. Auditoria não autoriza fabricar claim, evidência ou alegação.

## 14. REVIEW não é destino automático

Não aceite `REVIEW` como decisão final apenas porque o sistema não conseguiu classificar automaticamente.

Para cada caso em REVIEW:

1. identifique a razão exata;
2. busque as evidências faltantes nas fontes disponíveis;
3. determine se a ambiguidade continua material;
4. se as evidências já forem suficientes, aplique a decisão correta;
5. se revisão humana for realmente necessária pela política, registre exatamente qual decisão humana é indispensável.

"Review" genérico sem causa material e sem próxima ação é falha de operação/observabilidade.

## 15. Comunicações externas

Leia o conteúdo integral das mensagens relevantes.

Classifique semanticamente:

- quem recebeu reembolso;
- o que a Amazon está pedindo;
- prazo;
- evidência faltante;
- negativa;
- recurso disponível;
- promessa futura;
- confirmação financeira;
- divergência logística.

Toda contestação, recurso, justificativa, e-mail ou comunicação externa da ShopVivaliz deve ser escrita **em primeira pessoa**, como a própria ShopVivaliz falando diretamente com a Amazon.

Evite linguagem de terceiro observador, por exemplo "o seller informa" ou "a empresa solicita", quando a própria empresa está enviando a mensagem.

## 16. ERP Tiny/Olist

Para todo caso em que a política exigir devolução/movimentação no ERP, confirme o efeito real.

Não basta:

- job criado;
- write solicitado;
- retorno HTTP;
- log "success";
- fila drenada;
- status interno atualizado.

Execute readback e confirme:

- devolução de venda existe;
- pedido correto;
- itens corretos;
- quantidades corretas;
- valores coerentes;
- ausência de duplicidade;
- vínculo rastreável com o caso.

Se estiver ausente:

`detectar → causa raiz → corrigir → executar → readback → reconciliar`

## 17. Decisão esperada versus decisão real

Para cada ponto decisório relevante produza:

### DECISÃO ESPERADA

Derivada independentemente de:

- evidências reais;
- regra vigente;
- prazo aplicável;
- estado físico;
- estado financeiro;
- elegibilidade;
- histórico do canal.

### DECISÃO REAL

Determine:

- decisão gravada;
- regra/version/policy que a produziu;
- timestamp;
- ação enfileirada;
- ação realmente enviada;
- resultado externo;
- estado final persistido.

### COMPARAÇÃO

Classifique quando aplicável como:

- correta;
- correta, mas execução incompleta;
- correta, mas atrasada;
- correta, mas prematura;
- incorreta;
- ação faltante;
- ação duplicada;
- estado inconsistente;
- evidência insuficiente;
- encerramento prematuro;
- falso-verde;
- pendência externa real.

A classificação deve ser acompanhada da evidência que a sustenta.

## 18. Causa raiz obrigatória

Encontrar o caso errado não encerra o achado.

Investigue a causa sistêmica, inclusive:

- regra incorreta;
- regra ausente;
- prioridade de regra errada;
- snapshot/policy desatualizado;
- evidência não ingerida;
- cursor/checkpoint;
- parser;
- classificação semântica de email;
- correlação de order/return/claim;
- data/timezone;
- scheduler;
- worker;
- retry/backoff;
- quota;
- API;
- bridge;
- autenticação;
- feature flag;
- write gate;
- outbox;
- estado do banco;
- migration/backfill;
- reconciliação financeira;
- integração ERP;
- health falso-verde;
- exceção silenciosa;
- concorrência;
- idempotência;
- duplicidade de serviço/worker;
- legado ainda ativo.

## 19. Correção durante a auditoria

Auditoria de casos não é somente relatório.

Quando houver achado seguro e corrigível:

1. reproduza;
2. identifique causa raiz;
3. adicione teste de regressão quando aplicável;
4. corrija regra/código/configuração não secreta;
5. execute testes focais e suíte exigida;
6. siga o fluxo de branch/PR/checks/merge;
7. acompanhe o auto gate;
8. valide o release real;
9. reprocesse somente o necessário;
10. confirme o efeito externo real;
11. reaudite os casos impactados.

Mudanças `REVIEW`, `MIGRATION` ou `DESTRUCTIVE` seguem a governança de `AUDIT_POLICY.md`.

Nunca editar `current/` ou release ativa diretamente.

## 20. Busca por equivalentes

Para cada falha encontrada em um caso, pesquise a classe em todos os outros casos elegíveis:

`caso revelador → classe da falha → busca global → casos equivalentes → correção → reprocessamento → reauditoria`

É proibido corrigir apenas o primeiro registro se a falha puder ter afetado outros.

## 21. Validação de efeito real

Não usar isoladamente como prova de sucesso:

- HTTP 200;
- processo ativo;
- worker executado;
- fila vazia;
- decisão calculada;
- ação enfileirada;
- email enviado;
- SAFE-T submetida;
- aprovação da Amazon;
- flag ON;
- health OK;
- teste unitário verde.

Procure a pós-condição real no sistema de destino.

Exemplos:

- crédito → confirmar transação financeira;
- devolução ERP → confirmar registro no ERP;
- recurso → confirmar aceitação/persistência no canal correto;
- atualização de estado → reabrir/recarregar e conferir persistência;
- scheduler → provar execução material e resultado esperado.

## 22. Ordem de execução

A auditoria deve percorrer caso por caso:

`CASO 1 → reconstruir → provar → decidir esperado → decidir real → comparar → verificar prazo → verificar logística → verificar financeiro → verificar SAFE-T → verificar ERP → corrigir se necessário → validar → reauditar → CASO 2`

e assim sucessivamente.

A análise transversal deve acontecer **depois** ou em paralelo controlado, sem substituir a conclusão individual.

## 23. Saída mínima por caso

O artefato de auditoria deve conter, no mínimo:

- Caso;
- Pedido;
- Tenant/seller;
- SAFE-T/Claim;
- Situação factual;
- Linha do tempo;
- Valor em risco;
- Regras vigentes aplicadas;
- Evidências consultadas;
- Decisão esperada;
- Decisão real;
- Correção da decisão;
- Ação esperada;
- Ação realmente executada;
- Prazo/data-limite;
- Rastreamento/logística;
- Financeiro;
- ERP;
- Comunicações;
- Problemas encontrados;
- Causa raiz;
- Correção realizada;
- Reprocessamento realizado;
- Validação pós-correção;
- Próxima ação necessária;
- Status real final;
- Grau de evidência: `COMPROVADO`, `FORTE EVIDÊNCIA`, `HIPÓTESE A VALIDAR` ou `NÃO VALIDADO`.

## 24. Matriz consolidada final

Depois da conclusão individual, produza uma matriz consolidada que permita identificar:

- total elegível;
- total auditado;
- decisões corretas;
- decisões incorretas;
- execuções incompletas;
- casos atrasados;
- casos prematuros;
- claims não abertos;
- recursos não enviados;
- recursos fora de prazo;
- casos presos em REVIEW;
- casos presos em BLOCKED;
- créditos pendentes;
- créditos não reconciliados;
- devoluções ERP ausentes;
- encerramentos prematuros;
- divergências de rastreamento;
- falhas de ingestão;
- falhas semânticas de email;
- write gates bloqueadores;
- falhas sistêmicas;
- correções aplicadas;
- casos reprocessados;
- pendências externas comprovadas.

As contagens devem reconciliar com a lista individual. Caso não reconciliem, a auditoria permanece incompleta.

## 25. Critério de conclusão

A auditoria de casos só pode ser considerada concluída quando, dentro do escopo definido:

- 100% dos casos elegíveis tiverem uma linha de auditoria individual;
- as decisões materiais tiverem sido confrontadas com evidência independente;
- prazo e ordem dos eventos tiverem sido avaliados;
- efeitos externos tiverem sido verificados no destino;
- estado financeiro tiver sido reconciliado quando aplicável;
- ERP tiver readback quando aplicável;
- causas raiz tiverem sido identificadas para falhas;
- correções seguras aplicáveis tiverem sido executadas;
- regressões tiverem testes quando tecnicamente aplicável;
- casos afetados tiverem sido reprocessados/reavaliados;
- nenhum caso permanecer em REVIEW/BLOCKED genérico sem causa e próxima ação;
- a matriz consolidada reconciliar com os registros individuais;
- pendências restantes forem realmente externas, documentadas e com ponto exato de retomada.

## 26. Segurança e integridade

Este protocolo não autoriza:

- expor credentials/secrets;
- fabricar evidência;
- apresentar alegação falsa à Amazon;
- burlar autenticação;
- ignorar elegibilidade;
- contornar write gates;
- duplicar claim/thread;
- alterar dados destrutivamente para "corrigir" auditoria;
- enviar comunicação externa sem respeitar as regras vigentes do canal.

## Regra final

**Auditoria dos casos significa auditoria individual baseada em evidência, com reconstrução independente da decisão esperada, comparação com a decisão real e validação do efeito real. Agregado não substitui caso; diagnóstico não substitui correção; intenção não substitui execução; aprovação não substitui crédito; status não substitui prova.**
