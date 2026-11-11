# [GPT] FBA Onsite / Seller Flex: taxas e ressarcimento SAFE-T

## Fontes oficiais Amazon

- Politica FBA Onsite para ressarcimento SAFE-T, formula: https://sellercentral.amazon.com.br/help/hub/reference/external/GRFFG69BHA5M8EY9?locale=pt-BR
- Creditos e tarifas de administracao de reembolso ao comprador: https://sellercentral.amazon.com.br/help/hub/reference/external/G21531?locale=pt-BR
- Reembolsos a vendedores: https://sellercentral.amazon.com.br/help/hub/reference/external/G200213130

O ressarcimento SAFE-T considera o preco pago pelo comprador, menos o frete pago por ele, tarifas de venda SOA, tarifas Seller Flex e impostos sobre ambas. Nao e equivalente ao debito liquido que o vendedor sofre quando o comprador e reembolsado. Ao reembolsar o cliente a Amazon pode devolver parte da comissao e reter taxa administrativa.

## Metodologia auditavel e conservadora

1. Conferir dinheiro realmente liberado via SP-API Finances v2024-06-19 (nao confundir credito prometido com pago).
2. Ler eventos de envio, refund e SAFE-T via SP-API Finances v0 por Order ID.
3. Extrair ItemFeeList (AmazonForAllFee, Commission, FBAPerUnitFulfillmentFee etc.) e ItemFeeAdjustmentList, incluindo RefundCommission, sem tratar frete pago pelo comprador como receita liquida elegivel.
4. Ratear taxas originais apenas quando houver uma unica linha SKU vinculada sem ambiguidade; conferir quantidade reembolsada e debito esperado.
5. Validar reivindicacao SAFE-T v0 contra ID do caso e liquidacao efetiva do ledger v2024.
6. Decompor: diferenca aparente entre debito ao vendedor e SAFE-T liberado = tarifas retidas demonstradas + componente de tributos/outros ajustes ainda nao discriminado.
7. Nao estimar imposto ou dar baixa em componente nao documentado. Caso seja necessario detalhar tributos SOA/SF que a API nao individualizou, obter memoria de calculo via Seller Central e Suporte.

O relatorio abaixo e **somente leitura**, sem alteracao do financeiro, politica do proprietario (<15% com credito real), PRs de SAFE-T, gates, envios ou estados operacionais.

### Execucao

    AMAZON_RETURNS_ENV_FILE=/home/ubuntu/amazon-returns-deploy/shared/.env php scripts/amazon-returns/audit-safet-fee-breakdown.php --tenant-id=1 --connection-id=1 --case-ids=13211,13212

Saida JSON por caso com componentes e status de evidencias; nao emitir tokens, informacoes fiscais pessoais ou dados de compradores. Esta auditoria nao e autorizacao de recurso, nem determina que toda a diferenca e devida.

## Evidencia inicial da conta (conferencia cruzada SP-API v0 e v2024, 10/11/2026)

Os oito casos abaixo contem SAFE-T emitido, ajuste liberado e estorno ao comprador. O abatimento de tarifas foi conferido com ItemFeeList + ItemFeeAdjustmentList (incluindo RefundCommission). A parcela final **nao e necessariamente tributo**: a API v0 informa o valor do SAFE-T, mas nao detalha neste evento os tributos sobre SOA/Seller Flex. A parcela demanda memoria de calculo/consulta de pagamentos.

| Caso | Debito do reembolso BRL | SAFE-T liberado BRL | Diferenca nominal | Tarifa identificada | Outros abatimentos nao individualizados |
|---|---:|---:|---:|---:|---:|
| 13212 | 121,50 | 91,31 | 30,19 | 21,33 | 8,86 |
| 13211 | 137,12 | 114,75 | 22,37 | 18,71 | 3,66 |
| 13215 | 36,39 | 25,81 | 10,58 | 7,38 | 3,20 |
| 13216 | 68,29 | 53,07 | 15,22 | 8,65 | 6,57 |
| 13218 | 72,78 | 51,61 | 21,17 | 14,76 | 6,41 |
| 1 | 81,00 | 55,23 | 25,77 | 15,25 | 10,52 |
| 13206 | 20,46 | 5,31 | 15,15 | 6,74 | 8,41 |
| 13207 | 20,46 | 12,20 | 8,26 | 6,74 | 1,52 |
| **Soma** | **558,00** | **409,29** | **148,71** | **99,56** | **49,15** |

A regra de 15% foi estabelecida pelo proprietario e NAO foi alterada por esta auditoria. O relatorio nao grava casos como RECOVERED nem envia mensagens/reivindicacoes.

### Tolerancia do proprietario de 15% apos abatimentos comprovados

O campo informativo under_15_percent_after_proven_fees_advisory mostra se o valor residual entre a referencia anterior aos impostos discriminados e o credito realmente liberado e **estritamente inferior a 15% dessa referencia ajustada**.

Nos oito casos auditados, a regra numerica e satisfeita em 13212, 13211, 13215, 13216, 13218 e 13207 (seis casos). Os casos 1 e 13206 ficam acima. Essa classificacao e **meramente informativa**, nao redefine o ressarcimento devido, nao prova os impostos e nao fecha automaticamente reivindicacoes. O codigo principal da conciliacao permanece inalterado.
