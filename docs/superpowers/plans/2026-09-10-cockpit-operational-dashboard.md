# Operational Case Cockpit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transformar a consulta de devoluções em um painel operacional em português que explique situação, ação já tomada, próximo passo, responsabilidade do usuário, datas financeiras e evidências sem expor termos técnicos do backend.

**Architecture:** Manter o modelo/event-store existente e enriquecer apenas a projeção/API e a camada de apresentação. Os dados factuais continuam vindo de eventos, financeiro, outbox e evidências; a UI deriva resumos humanos de forma determinística. O histórico operacional será condensado no frontend, preservando todos os registros técnicos no backend.

**Tech Stack:** PHP 8.3, MySQL/PDO, JavaScript sem framework, CSS responsivo, GitHub Actions.

**Spec:** Conversa aprovada do projeto Devolucoes Amazon em 09/09/2026: consulta orientada a situação + ação tomada + próximo passo, com datas do pedido e do reembolso, linguagem humana, histórico colapsável e auditoria de inconsistências.

## Global Constraints

- Não expor enums, nomes de jobs, SP-API ou rótulos técnicos na interface operacional.
- Não apagar eventos técnicos; apenas condensar a apresentação.
- Dados e consultas permanecem tenant-scoped.
- Nenhuma ação externa deve ser criada por esta mudança de UI.
- A lista e o detalhe devem derivar o estado da mesma fonte para evitar divergência visual.
- Campos sem valor não devem ocupar espaço no resumo principal.
- Prazos vencidos devem ser interpretados no contexto da ação já realizada; não exibir alerta falso quando o recurso já foi enviado.

---

### Task 1: Contrato de dados operacionais

**Files:**
- Modify: `admin/amazon-returns/api/cases.php`
- Modify: `admin/amazon-returns/api/case.php`
- Test: `tests/cockpit-api-contract-test.php`

**Interfaces:**
- Consumes: caso projetado, eventos, evidências, outbox, reviews e rule applications existentes.
- Produces: campos `order_at`, `refund_at`, `seller_debit_at`, `next_action_at`, `updated_at`, `customer_tracking_ids`, `customer_delivery_carriers`, `sales_invoice_number`, `last_external_write`, `last_read_back`, `current_action`, `current_reason` e valores financeiros necessários ao painel.

- [ ] **Step 1: Write the failing test**

Adicionar asserts ao contrato da API exigindo que a consulta exponha as datas do pedido, reembolso e débito, além do identificador da NF quando houver evento `SALES_INVOICE_LINKED`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/cockpit-api-contract-test.php`
Expected: FAIL antes de o contrato novo estar completo.

- [ ] **Step 3: Write minimal implementation**

Em `cases.php`, incluir `order_at`, `seller_debit_at` e dados de atualização já projetados. Em `case.php`, percorrer eventos do caso uma única vez para extrair a NF de venda mais recente de `SALES_INVOICE_LINKED` e anexá-la ao payload do caso sem mudar o schema persistido.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/cockpit-api-contract-test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

`git commit -m "feat: expose operational case facts in cockpit api"`

### Task 2: Resumo operacional humano e responsabilidade

**Files:**
- Modify: `admin/amazon-returns/assets/cockpit.js`
- Test: `tests/cockpit-ui-contract-test.php`

**Interfaces:**
- Consumes: payload dos endpoints de casos e detalhe.
- Produces: funções determinísticas `operatorStatus(caseData)`, `operatorResponsibility(caseData)`, `operatorNextStep(caseData)` e `operatorAlert(caseData)`.

- [ ] **Step 1: Write the failing test**

Exigir no contrato de UI os textos `Nenhuma ação sua é necessária`, `Sua decisão é necessária`, `Aguardando resposta da Amazon`, `Aguardando crédito da Amazon` e impedir `Aguardar` como rótulo operacional isolado.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/cockpit-ui-contract-test.php`
Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Mapear ação/estado para estados operacionais específicos. Se houver review aberto, responsabilidade = usuário. Se houver ação externa enviada ou espera temporal/financeira, responsabilidade = sistema. Prazos vencidos só geram `Providência automática atrasada` quando nenhuma ação correspondente foi executada e o caso continua pendente.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/cockpit-ui-contract-test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

`git commit -m "feat: add human operational case summaries"`

### Task 3: Redesenho da lista de casos

**Files:**
- Modify: `admin/amazon-returns/assets/cockpit.js`
- Modify: `admin/amazon-returns/assets/cockpit.css`
- Modify: `admin/amazon-returns/index.php`
- Test: `tests/cockpit-ui-contract-test.php`

**Interfaces:**
- Consumes: funções operacionais da Task 2.
- Produces: linha com número do pedido, SAFE-T separado, estado operacional, recebimento/valor, responsabilidade e botão Abrir.

- [ ] **Step 1: Write the failing test**

Exigir classes/estruturas `case-row-id`, `case-row-status`, `case-row-finance`, `case-row-responsibility` e filtros rápidos `Todos`, `Precisa da minha atenção`, `Sistema tratando`, `Concluídos`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/cockpit-ui-contract-test.php`
Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Separar visualmente pedido e SAFE-T, estado e valores. Transformar filtros rápidos em filtros locais/servidor existentes sem duplicar regra de negócio. Ordenação segue backend por review/prazo/atualização.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/cockpit-ui-contract-test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

`git commit -m "feat: redesign case list for operational scanning"`

### Task 4: Painel de detalhe orientado a decisão

**Files:**
- Modify: `admin/amazon-returns/assets/cockpit.js`
- Modify: `admin/amazon-returns/assets/cockpit.css`
- Test: `tests/cockpit-ui-contract-test.php`

**Interfaces:**
- Consumes: fatos operacionais e funções da Task 2.
- Produces: blocos `Situação atual`, `O que aconteceu`, `O que o sistema fez`, `O que acontece agora`, `Sua ação`, `Datas importantes`, `Valores`, `Produto e documentos`, `Rastreio`.

- [ ] **Step 1: Write the failing test**

Exigir na UI `Data do pedido`, `Reembolso concedido ao cliente`, `Débito na conta da loja`, `Saldo ainda a recuperar`, `NF de venda`, `Última verificação`, `Próxima providência` e ausência do campo `Pode ser solicitado a partir de` quando nulo.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/cockpit-ui-contract-test.php`
Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Renderizar somente campos disponíveis. Exibir as três datas financeiras separadas. Se o prazo de recurso estiver no passado mas `APPEAL_SUBMITTED`/ação equivalente já tiver ocorrido, mostrar que foi enviado e que agora se aguarda a Amazon, sem alerta de atraso.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/cockpit-ui-contract-test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

`git commit -m "feat: build decision-oriented case detail panel"`

### Task 5: Histórico condensado e evidências

**Files:**
- Modify: `admin/amazon-returns/assets/cockpit.js`
- Modify: `admin/amazon-returns/assets/cockpit.css`
- Test: `tests/cockpit-ui-contract-test.php`

**Interfaces:**
- Consumes: timeline completa existente.
- Produces: `condenseTimeline(items)` que agrupa observações repetitivas e preserva ações, respostas, finanças, decisões e erros.

- [ ] **Step 1: Write the failing test**

Exigir `condenseTimeline`, botão `Ver histórico completo`, contagem de verificações agrupadas e traduções humanas para `Pedido sincronizado` e `Movimentação financeira identificada`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/cockpit-ui-contract-test.php`
Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Agrupar eventos repetitivos por título/categoria e dia quando não houver mudança de estado/valor. Manter íntegros eventos de ação externa, resposta Amazon, reconciliação, decisão, erro e eventos com mensagem/evidência relevante. Histórico inicia recolhido.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/cockpit-ui-contract-test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

`git commit -m "feat: condense operational timeline"`

### Task 6: Auditoria de consistência visual/funcional

**Files:**
- Create or Modify: `tests/cockpit-operational-audit-test.php`
- Modify as findings require: `admin/amazon-returns/assets/cockpit.js`, `cockpit.css`, APIs.

**Interfaces:**
- Consumes: implementação final das Tasks 1-5.
- Produces: contrato para detectar datas incoerentes, estado/lista divergente, prazo vencido sem ação, valores negativos/impossíveis e review aberto versus responsabilidade exibida.

- [ ] **Step 1: Write the failing audit test**

Criar fixtures cobrindo: recurso enviado antes do prazo, prazo vencido sem ação, crédito parcial, caso concluído, review aberto, rastreio entregue com alegação de não recebimento e caso com NF.

- [ ] **Step 2: Run test to verify findings**

Run: `php tests/cockpit-operational-audit-test.php`
Expected: FAIL para qualquer inconsistência ainda presente.

- [ ] **Step 3: Fix findings**

Corrigir somente comportamentos comprovadamente inconsistentes; não adicionar fluxo novo sem evidência.

- [ ] **Step 4: Run focused and full regression suites**

Run: `php tests/cockpit-operational-audit-test.php && php tests/cockpit-ui-contract-test.php && php tests/cockpit-api-contract-test.php`
Expected: PASS.

Run CI equivalently via GitHub Actions on PR.
Expected: all jobs green.

- [ ] **Step 5: Commit**

`git commit -m "test: audit operational cockpit consistency"`

### Task 7: Revisão independente e validação em produção

**Files:**
- Modify only if review findings are valid.

**Interfaces:**
- Consumes: PR diff + screenshots da produção.
- Produces: revisão adversarial de código/fluxo e revisão visual independente antes da conclusão.

- [ ] **Step 1: Independent code/flow review**

Submeter o diff final a um segundo modelo/agente com instrução para procurar divergência de regras, falsos alertas, estados impossíveis, regressões e problemas de acessibilidade.

- [ ] **Step 2: Independent visual review**

Submeter screenshots reais da produção a uma segunda revisão visual focada em clareza, hierarquia, densidade, responsividade e entendimento sem termos técnicos.

- [ ] **Step 3: Implement only validated findings**

Aplicar achados que reduzam ambiguidade, cliques, ruído ou risco operacional e adicionar/ajustar testes que os cubram.

- [ ] **Step 4: Merge and production smoke**

Confirmar CI verde, merge em `main`, auto-deploy concluído, página autenticada carregando, lista abrindo detalhe, filtros funcionando, histórico recolhível, datas/valores corretos e nenhum erro de console/API.

- [ ] **Step 5: Final audit**

Confirmar que a tela responde imediatamente: o que aconteceu, o que o sistema fez, o que acontecerá, se o usuário precisa agir, datas críticas, valores, evidências e rastreio.