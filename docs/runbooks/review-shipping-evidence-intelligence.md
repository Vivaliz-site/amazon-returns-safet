# Análise inteligente de entrega e rastreio nas revisões Amazon Returns / SAFE-T

## Objetivo

Antes de sugerir WAIT, CHECK_FINANCES, SAFE_T_SUBMIT, recurso ou suporte, o agente deve reconstruir a situação do pedido por fontes independentes, sem confundir a **entrega original ao comprador**, a **devolução logística** e a **recuperação financeira do vendedor**.

## Evidências que entram na recomendação

1. **Envio ao comprador:** Amazon SP-API Orders (pacotes correlacionados ao `orderItemId`). Persistimos e projetamos `customer_delivery_status`, `customer_delivery_confirmed`, `customer_tracking_ids` e `customer_delivery_carriers`, mesmo quando o envio ainda está em trânsito. `DELIVERED` exige que todos os pacotes correlacionados estejam entregues; misturas de pacotes entregues e pendentes geram `PARTIALLY_DELIVERED`. Código de rastreamento sem confirmação de entrega **não comprova recebimento pelo comprador**.
2. **Devolução:** `return_tracking_ids` é evidência distinta do código de envio original. `quantity_received`, `physical_status` e evento de `PHYSICAL_RECEIVED` com origem de armazém são a evidência separada de recebimento pelo vendedor.
3. **Financeiro:** débito/reembolso e ressarcimento creditado são fatos diferentes. Um pedido entregue ao comprador, mesmo com reembolso aprovado, não demonstra produto devolvido nem SAFE-T efetivamente pago.

A recomendação da IA recebe os fatos acima e referências a eventos `ORDER_SYNCED` oficiais com entrega/rastreio, além de eventos financeiros/retorno. A assinatura de similaridade das regras registra **categorias**, e nunca números de pedido, códigos de rastreio ou datas literais. Mudança de evidência material força reavaliação do contexto da revisão, evitando reutilizar sugestão incompatível.

## Ausência de prova não é prova de ausência

`UNKNOWN`, `NOT_OBSERVED` e ausência de rastreio significam **pendência de verificação**, nunca certeza de não entrega, produto não devolvido ou ressarcimento inexistente. A SP-API pode não apresentar o andamento completo da transportadora; nesses casos, a próxima etapa investigativa é conferir o detalhamento oficial do pedido no Seller Central/transportadora, com identidade do pedido e item e comprovante de entrega. **Essa consulta independente de transportadora não é implementada por esta alteração**: os códigos atualmente são derivados da SP-API e o navegador do Seller Central continua responsável pelo rastreio anexado às ações de SAFE-T.

Não introduzir escrita externa, abertura duplicada de SAFE-T, novas restrições operacionais ou fechamento por simples ausência de rastreio. Preservar a janela e os controles oficiais já existentes.

## Testes de regressão

```sh
php tests/customer-delivery-evidence-test.php
php tests/review-context-signature-test.php
php tests/review-operations-test.php
php tests/customer-nonreceipt-delivery-contradiction-test.php
php tests/amazon-returns-spapi-test.php
php tests/review-service-test.php
```

## Validação em produção

Conferir a leitura dos pacotes pela SP-API, a transição de evidência em `ORDER_SYNCED` e a reabertura controlada da revisão quando a assinatura mudar; verificar sugestão AI atualizada e e-mail com situação de entrega/tracking. Sem prova de entrega, o resumo deve indicar “não verificada”, não “não entregue”. Ações externas continuam com as aprovações e idempotência existentes.
