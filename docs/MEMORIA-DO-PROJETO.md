# Memória persistente do projeto — Amazon Returns / SAFE-T

Este arquivo registra decisões expressas do proprietário. É versionado no Git e deve ser lido junto com `AGENTS.md` e `docs/REGRAS-DE-ENTREGA.md` no início de cada tarefa. Não substitui a consulta ao estado atual de produção e não deve guardar credenciais, dados pessoais, mensagens privadas ou provas financeiras brutas.

## Decisão de 05/09/2026: gatilho operacional D+45
A contagem começa na data do **reembolso efetuado pela Amazon ao cliente**, não na data posterior do débito ao vendedor, no início da devolução ou na entrada do pedido no aplicativo.

A tentativa operacional deve ocorrer em **D+45**, independentemente de o rastreamento indicar “retornando ao vendedor”, estar sem movimentação, sem devolução iniciada ou sem esse status. Não exigir `RETURNING_TO_SELLER` nem usar a ausência desse texto para bloquear a análise ou adiar a primeira tentativa.

Ao atingir D+45, verificar o recebimento físico real e o ressarcimento efetivo ao vendedor. Se a perda continuar sem solução por devolução válida ou crédito reconciliado, abrir a tentativa SAFE-T no canal aplicável. Rastreamento de entrega, promessa, aprovação ou informação de que um reembolso será emitido não substituem a conferência física/financeira.

Não cobrar novamente uma perda já integralmente resolvida. Recebimento parcial, produto divergente/danificado e crédito parcial continuam exigindo tratamento do saldo/prejuízo remanescente, com o motivo correto. A regra não autoriza transformar uma devolução intacta já recebida em uma alegação falsa de não recebimento.

Confirmar que o reembolso partiu da Amazon. Reembolso iniciado pelo próprio vendedor ou iniciador desconhecido não pode ser automaticamente classificado como reembolso da Amazon. Preservar os dados e encaminhar para o tratamento adequado quando houver dúvida.

A regra operacional não dispensa a validação de elegibilidade efetiva na Amazon nem autoriza usar um canal incompatível com o programa logístico. As regras externas por programa ficam registradas separadamente; não substituem silenciosamente D+45 por D+60 ou D+75 neste tenant.

Se a Amazon pedir para aguardar, registrar a resposta original e a data solicitada. Retomar/reabrir no canal adequado nessa data, após nova verificação financeira, sem criar reivindicação, recurso, e-mail ou chamado duplicado. Prazo ambíguo requer esclarecimento/revisão, não uma data inventada.

## Decisão de 05/09/2026: entrega completa pelo agente
O agente deve executar e validar a tarefa de ponta a ponta. Nenhuma alteração válida deve ficar abandonada apenas localmente: revisar, testar, commitar, publicar, integrar e confirmar a implantação real. Isso inclui testes e documentação, além do código.

Nenhum PR, rascunho, Action necessária, conflito ou falha de deploy do trabalho pode ser abandonado como se a tarefa estivesse concluída. Inspecionar também os demais worktrees e pendências do repositório; coordenar execuções simultâneas e preservar trabalho alheio. Não integrar regra obsoleta apenas para encerrar um PR.

O agente faz a validação e o merge do SHA revisado; o **auto gate existente** faz o deploy. O agente acompanha o resultado e corrige falhas na mesma execução até comprovar o sucesso, salvo bloqueio externo real que exija atuação do usuário/provedor. Não contornar checks, permissões ou autenticação para aparentar entrega.

Toda alteração deve ter validação funcional real do fluxo afetado, além dos testes automatizados e CI. Não considerar uma rotina concluída apenas porque o código foi implantado ou o health está OK; quando houver um fluxo de usuário alterado, executar o fluxo ponta a ponta e conferir o efeito persistido no backend. Se isso não puder ser executado por um bloqueio externo real, a tarefa permanece aberta com o bloqueio explicitado.

## Decisao de 05/09/2026: devolucao danificada
Produtos que retornarem danificados terao a abertura inicial tratada manualmente pelo usuario. O aplicativo nao deve abrir automaticamente uma nova reivindicacao SAFE-T para `RECEIVED_DISCREPANT`/dano fisico. Depois que o usuario abrir manualmente e existir um SAFE-T ID, se a Amazon negar a reivindicacao, o aplicativo pode assumir o acompanhamento e os recursos subsequentes, respeitando prazo oficial, evidencia, deduplicacao e demais gates do canal.


## Decisao de 07/09/2026: Seller Central browser somente sob demanda
O Fred-Win nao deve manter os workers de Seller Central nem o Opera dedicado da porta 9225 residentes quando nao houver trabalho. SP-API, Finances, Returns e Gmail permanecem como fontes primarias. Operacoes exclusivas da interface Seller Central devem ser executadas por um dispatcher curto e serializado, chamando os workers com `--drain`; o navegador dedicado deve ser encerrado ao fim da execucao. As antigas tarefas persistentes de leitura e escrita devem ser substituidas por esse dispatcher, sem remover gates de escrita, elegibilidade, idempotencia ou reconciliacao financeira. A leitura de status via Seller Central e apenas uma rede de seguranca: o mesmo SAFE-T so deve gerar nova leitura browser em um novo bloco de seis horas; mudancas rotineiras continuam sendo detectadas prioritariamente por SP-API, Finances, Returns e Gmail.

## Decisao de 08/09/2026: cadencia API e browser
A decisao de 07/09/2026 sobre bloco de seis horas foi supersedida. Rotinas que dependem de navegador ou outra automacao nao-API devem executar apenas uma vez por dia. Rotinas respaldadas por API, incluindo SP-API, Finances, Returns/Reports e Gmail API, executam a cada quatro horas. O health interno pode continuar mais frequente porque e monitoramento de disponibilidade e nao uma rotina de negocio externa. A mudanca de cadencia nao habilita nenhum gate de escrita.

## Decisão de 08/09/2026: cliente alega não recebimento, mas rastreio confirma entrega
Quando a mensagem da Amazon ou do cliente alegar que o pedido não foi recebido, mas existir evidência confiável do pedido original marcada como entregue ao destinatário e houver código de rastreamento associado, essa divergência não deve, isoladamente, gerar revisão humana.

O sistema deve responder automaticamente no canal já existente do caso, citando o código de rastreamento e a confirmação de entrega e pedindo que a Amazon revise a inconsistência entre a alegação de não recebimento e o rastreio oficial. A automação deve ser idempotente e reutilizar a mesma SAFE-T/thread, sem abrir fluxo duplicado.

Revisão humana continua obrigatória se houver outra ambiguidade material independente, como ausência de evidência confiável de entrega, rastreamento sem identificação, problema de correlação com o pedido/item, prazo oficial indispensável não resolvido ou outro bloqueio de segurança do canal.

## Decisão de 09/09/2026: consultas de negócio duas vezes por dia e NF como busca
Esta decisão supersede as cadências anteriores de quatro horas, uma vez por dia e o agendador interno de cinco minutos. Gmail API, SP-API Orders/Finances, Returns/Reports, Seller Central, monitor de políticas e o agendador de decisões de negócio devem executar **duas vezes por dia**, em intervalos de 12 horas. Não manter rotina de negócio a cada cinco minutos. O monitoramento técnico de saúde pode continuar mais frequente porque não consulta o estado comercial dos casos.

A cadência de 12 horas não se aplica a uma consulta manual solicitada pelo usuário na interface. Ao informar um número de pedido em **Registrar devolução recebida**, o aplicativo deve verificar o banco local e, se o pedido ainda não existir, consultar a Amazon imediatamente e sincronizá-lo antes de informar que não encontrou. Um pedido existente na Amazon não pode ser apresentado como inexistente apenas porque a ingestão automática ainda não o trouxe para o banco local.

O **número da NF de venda** é uma opção de busca/localização da devolução, não um campo que o usuário precisa preencher ao confirmar o recebimento. O aplicativo deve preservar o campo `Invoice number` fornecido pelo relatório oficial de devoluções da Amazon como evidência pesquisável, mantendo compatibilidade de leitura com números de NF que já tenham sido gravados anteriormente.

A quantidade física recebida deve ser validada contra a quantidade reembolsada conhecida e, enquanto essa projeção ainda não existir, contra a quantidade pedida. O envio de **Confirmar recebimento** deve ser idempotente e impedir cliques concorrentes durante a requisição.

Na interface do usuário, revisão e consulta de caso devem priorizar linguagem simples e objetiva. Termos/códigos internos permanecem no backend. A revisão deve mostrar primeiro o que aconteceu e o que já foi verificado, depois explicar por que uma decisão é necessária e apresentar a recomendação. A linha do tempo deve ficar recolhida por padrão e abrir somente quando o usuário escolher **Ver histórico**.

A redução de cadência não elimina datas oficiais já conhecidas. O daemon deve comparar localmente a próxima data persistida e, quando ela vencer, disparar a revalidação de evidências, a decisão e o canal de entrega necessário **sem esperar o próximo ciclo de 12 horas**. Isso é um gatilho por data já conhecida, não polling rotineiro de negócio. Após a avaliação, uma data vencida já processada não pode criar um loop de reconsultas. Não é necessário manter agendador periódico de cinco minutos para cumprir prazos.
