# Amazon Seller Central Brasil - plano de automacao protegida (2026-11-01)

## Objetivo e autorizacao comercial

Automatizar cupons seletivos de 5% e promocao de 3% na compra de duas unidades apenas quando a modalidade oficial do Seller Central Brasil permitir, com margem liquida final >= 10% por SKU e em todos os cenarios de custos e acumulacao. Nunca aumentar 3% para 5% automaticamente.

A autorizacao do proprietario para implementar software nao e liberacao de ferramenta nem revoga uma recusa explicita. O comando de inspecao do formulario Create Coupon ja foi recusado por seguranca da ferramenta; nao tentar a mesma acao por outra credencial, caminho ou endpoint. Nao existe PR humana pendente exigida para o escopo comercial, mas um futuro grant protegido continua sujeito as regras normais de seguranca e aprovacao.

## Topologia vigente e protecoes

- Marina: marina-a1-4c24g, producao e dados comerciais. Backend: always-free-arm-1787907847-26, controller/browser corporativo.
- VM historica shopvivaliz-free-a1 EXCLUIDA. Proibido entrar, executar, usar alias ou restaurar.
- Sessao Seller Central na Marina anteriormente autenticada por amazon-returns-seller-central-auth-check.service; isso nao comprova que TOTP tenha sido usado nem permissao para publicar.
- Amazon Returns/SAFE-T possui operacoes de casos e suporte, nao escrita de promocoes.
- Credenciais de Seller Central e do ERP permanecem em arquivos protegidos, nunca em logs, chat, branch ou checkpoint.

## Plano de execucao com checkpoints

1. **Coleta LIVE de leitura:** casar exatamente SKU/ASIN entre SP-API e Tiny v3; exigir listing BUYABLE, estoque FBA fulfillable, status ERP A, ultimo historico real de precoCusto (nao media presumida), dimensoes e minimo de repricing. Guardar timestamps e origem das evidencias. Nao usar match por nome.
2. **Motor financeiro:** aplicar 14% de imposto sobre a receita ja descontada. Descontar custo ultima compra, comissao efetiva por SKU, logistica FBA Onsite contratual atual, taxa de tecnologia, parcelamento maximo, resgate de cupom e qualquer outra despesa. Considerar o pior desconto acumulado permitido, minimo de repricing e diferentes faixas logicas. Permitir apenas margem liquida >=10%.
3. **Tarifas oficiais:** validar por fonte oficial autorizada a cotacao FBA Onsite do proprio contrato do seller, para dimensoes/peso e cada faixa aplicavel; taxa real por resgate e tecnologia. A estimativa generica da FeesEstimate SP-API e APENAS indicativa de FBA padrao, nao atesta o Onsite. Nao usar tarifa de promocao de 2025 vencida em janeiro de 2026.
4. **Regras comerciais oficiais:** conferir cupom 5%, promocao por quantidade 3% se suportada, elegibilidade e acumulacao por SKU, valores minimos de orcamento e duracao; escolher os menores valores admitidos. Orcamentos nao sao limites absolutos de gastos.
5. **Autorizacao efetiva de publicacao:** obter concessao explicita da plataforma/ferramenta que negou inspeção de Create Coupon. Nunca converter login valido ou aprovacao comercial em permissao tecnica. Sem capacidade official, nenhuma chamada a formulario privado, endpoints nao documentados, outro conector ou outro usuario para contornar a recusa.
6. **Execucao idempotente:** futura implementacao de publicador isolado com lease exclusivo, preflight financeiro imediatamente anterior a cada envio, verificacao de conflitos/promocoes vigentes, unica submissao e receipt de ID. Nao incluir no worker SAFE-T.
7. **Reconciliacao:** consultar getPromotion e getSelection oficiais, confirmar ID, estado RUNNING/UPCOMING, ASIN, desconto, quantidade, periodo, orcamento e margem. Nao declarar ativado antes disso.
8. **Entrega:** executar testes locais e CI, merge sob branch protections, auto-deploy de release imutavel, smoke de runtime e prova visual oficial. Nenhuma mudanca manual sobre current/release ativa.

## Codigo versionado nesta entrega inicial

- scripts/amazon-promotions/finance-gate.mjs: motor deterministico de margem, que REJEITA campos ausentes, tarifas erradas, SKU proibido, piso e estoque insuficientes. Mesmo em PASS financeiro, publicationAuthorization=false.
- scripts/amazon-promotions/collect-live.mjs: coletor oficial somente leitura Amazon SP-API e Olist/Tiny v3 por SKU exato. Os campos onsiteFees, eligibility e stacking saem nulos de proposito ate prova independente. A estimativa standardFbaEstimateAdvisoryOnly NAO habilita Onsite.
- tests/amazon-promotions-finance.test.mjs: testes para descontos autorizados, 14% sobre preco final, custos, estoque, repricing, cumulatividade, fontes, expiracao e exclusoes.
- **Publicador nao ativado ou versionado:** tentativa de implementar o componente sofreu recusa explicita da ferramenta nesta rodada. Nao repetir por outra rota. Requer capacidade oficial distinta da autenticacao.

## Exclusoes e proibicoes

Excluir C08 e todos os carrinhos de 6 gavetas, Parafuso5x16 (materia-prima) e SKU-343577002. Nao mexer em tabelas de precos/custos, nao alterar C08, nao criar ads pagos, nao efetuar compras reais. Impedir duplicacao de campanhas e agregacao de descontos acima dos limites aprovados.

## Evidencia operacional de 01/11/2026

Na Marina, consulta read-only identificou cinco SKUs BUYABLE com status A no ERP, mas nenhuma margem certificada. JVCDAC34 teve preco Amazon de R$435 e ultimo custo de R$161,54 (2026-10-26); a FeesEstimate retornou comissao estimada 12% e taxa FBA padrao de R$6 ao preco com 5% de desconto: NAO equivale ao contrato FBA Onsite. SAB-PR-FBA-ONSITE: Amazon R$78, ultimo custo R$24,31, estoque fulfillable 1, e taxa Onsite real nao confirmada.

## Condicao de liberacao

Esta entrega constitui base tecnica de leitura e calculo, nao ativacao. A publicacao somente pode ser desenvolvida/acionada apos (a) aprovacao oficial da capacidade de ferramenta para Create Coupon e quantidade na interface do Seller Central; (b) comprovacao atual da logistica Onsite, tecnologia, resgate, elegibilidade e regras de stacking para o contrato real; (c) testes e gates de entrega. Enquanto faltarem, status BLOCKED_EXTERNAL com zero efeitos externos. Nao prometer execucao futura sem agente/checkpoint efetivo.
