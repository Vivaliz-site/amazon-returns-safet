# ERP Sales Return Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create an idempotent Olist/Tiny sales-return lifecycle for refunded Amazon orders while keeping return-NF issuance manual in the ERP.

**Architecture:** Add a read-only return-NF lookup, tenant-scoped lifecycle persistence, a guarded workflow service, and UI/API projection. The external Olist/Tiny sales-return write is isolated behind a gateway and remains disabled until the exact authenticated ERP operation is verified and a production canary passes.

**Tech Stack:** PHP 8.3, PDO/MySQL, Olist/Tiny public API v3, existing Amazon Returns runtime, GitHub Actions CI.

**Spec:** `docs/superpowers/specs/2026-09-12-erp-sales-return-design.md`

## Global Constraints

- Never create an ERP sales return when a qualifying return NF already exists.
- Repeat the return-NF lookup immediately before any external sales-return write.
- Never substitute a generic order or direct NF creation for Olist/Tiny `Devoluções de venda`.
- The system must not issue or authorize the NF de devolução; that remains a user action in Olist/Tiny.
- ERP external writes are OFF by default and require a separate production canary before enablement.
- All persistence is tenant/connection scoped.
- UI primary wording is simple Portuguese; internal codes remain backend details.

---

### Task 1: Return-NF read-side deduplication

**Files:**
- Create: `includes/amazon-returns/ErpReturnInvoiceLookup.php`
- Create: `tests/erp-return-invoice-dedupe-test.php`

**Interfaces:**
- Produces: `SvAmazonErpReturnInvoiceLookup::findForOrder(string $amazonOrderId): ?array`
- Returned array keys: `invoice_id`, `invoice_number`, `series`, `access_key`, `status`, `purpose`, `order_id`, `issued_at`.

- [ ] **Step 1: Write the failing test**

Create a fake HTTP transport where `/notas?tipo=E...` returns entry-note candidates and `/notas/{id}` returns the detailed note. Assert that a candidate for the exact Amazon order with return purpose is returned; a normal entry note and another order are ignored; two qualifying candidates throw `UnexpectedValueException`.

- [ ] **Step 2: Run the test and verify RED**

Run: `php tests/erp-return-invoice-dedupe-test.php`
Expected: FAIL because `ErpReturnInvoiceLookup.php` / `SvAmazonErpReturnInvoiceLookup` does not exist.

- [ ] **Step 3: Implement the minimal lookup**

Use the existing protected credential source and `https://api.tiny.com.br/public-api/v3`. Query entry notes only, match `ecommerce.numeroPedidoEcommerce` exactly, fetch candidate details, and accept only return-related purposes (`finalidade` values explicitly classified by the helper). Throw on multiple qualifying candidates instead of guessing.

- [ ] **Step 4: Run focused and related tests**

Run: `php tests/erp-return-invoice-dedupe-test.php && php tests/erp-invoice-fallback-test.php && php tests/amazon-invoice-live-lookup-test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

Commit message: `feat: detect existing ERP return invoices`

### Task 2: Tenant-scoped ERP return lifecycle persistence

**Files:**
- Modify: `includes/amazon-returns/Schema.php`
- Create: `includes/amazon-returns/ErpSalesReturnRepository.php`
- Modify: `includes/amazon-returns/TenantPersistence.php`
- Create: `tests/erp-sales-return-repository-test.php`
- Modify: `tests/amazon-returns-tenant-schema-test.php`

**Interfaces:**
- Produces repository methods `findByOrder(string $orderId): ?array`, `ensureWorkflow(array $data): array`, `markReady(...)`, `markReturnCreated(...)`, `linkReturnInvoice(...)`, `markBlocked(...)`.
- Unique workflow identity: tenant + Amazon connection + Amazon order.

- [ ] **Step 1: Write repository/schema tests first**

Assert the schema contains an `amazon_return_erp_sales_returns` table with tenant/connection/order uniqueness and that repository queries include tenant and Amazon-connection scope.

- [ ] **Step 2: Verify RED**

Run the two focused tests; expected failure is missing table/repository binding.

- [ ] **Step 3: Implement schema and repository**

Persist original sale references, ERP sales-return ID, lifecycle status, return-NF references, idempotency key, safe error, and timestamps. Do not store credentials or fiscal document bodies.

- [ ] **Step 4: Verify GREEN**

Run focused tests plus tenant SQL audit: `php tests/erp-sales-return-repository-test.php && php tests/amazon-returns-tenant-schema-test.php && php scripts/audit-tenant-sql.php`.

- [ ] **Step 5: Commit**

Commit message: `feat: persist ERP sales return lifecycle`

### Task 3: Idempotent workflow and guarded write gateway

**Files:**
- Create: `includes/amazon-returns/ErpSalesReturnGateway.php`
- Create: `includes/amazon-returns/ErpSalesReturnService.php`
- Modify: `includes/amazon-returns/Config.php`
- Create: `tests/erp-sales-return-workflow-test.php`

**Interfaces:**
- `SvAmazonErpSalesReturnGateway::create(array $command): array`
- `SvAmazonErpSalesReturnService::reconcileOrder(string $orderId): array`
- Config gate: `AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED`, default false.

- [ ] **Step 1: Write workflow tests first**

Cover: existing return NF => gateway call count zero and NF linked; gate off => no write and `READY_TO_CREATE`; no NF + gate on => mandatory second preflight then exactly one gateway create; uncertain gateway result => readback before any retry; repeated reconcile => no duplicate create.

- [ ] **Step 2: Verify RED**

Run `php tests/erp-sales-return-workflow-test.php`; expected failure is missing service/gateway.

- [ ] **Step 3: Implement the state machine**

Use order-level aggregation from `CaseRepository::forOrder()`. Require at least one refunded quantity, resolve original ERP sale evidence, run return-NF preflight, consult repository state, apply the write gate, repeat preflight immediately before create, and require stable gateway readback before `RETURN_CREATED_WAITING_INVOICE`.

The production gateway implementation initially returns a deterministic `ERP_SALES_RETURN_WRITE_NOT_VERIFIED` block until the exact Olist/Tiny `Devoluções de venda` operation is captured and verified. It must never call a generic order/NF endpoint.

- [ ] **Step 4: Verify GREEN**

Run focused workflow tests and all return routing regression tests.

- [ ] **Step 5: Commit**

Commit message: `feat: guard ERP sales return workflow`

### Task 4: Runtime reconciliation and operator projection

**Files:**
- Modify: `includes/amazon-returns/Runtime.php`
- Modify: `workers/amazon-returns/daemon.php`
- Modify: `admin/amazon-returns/api/case.php`
- Modify: `admin/amazon-returns/api/cases.php`
- Modify: `admin/amazon-returns/assets/cockpit-operational.js`
- Create: `tests/erp-sales-return-runtime-test.php`
- Create: `tests/erp-sales-return-ui-contract-test.php`

**Interfaces:**
- Add `erp_sales_returns` to the existing 12-hour business cadence.
- API projection key: `erp_return` with backend code plus Portuguese display fields.

- [ ] **Step 1: Write runtime/UI tests first**

Assert the task cadence is 43200 seconds, eligible refunded orders are reconciled once per order, and API/UI exposes `Devolução no ERP pronta para criar`, `Devolução criada no ERP — aguardando NF de devolução`, and `NF de devolução já emitida` without an NF-issuance action.

- [ ] **Step 2: Verify RED**

Run focused tests; expected failure is absent runtime task/projection.

- [ ] **Step 3: Implement runtime and projection**

Reconcile only refunded order IDs, aggregate case rows by Amazon order, and expose persisted ERP return state in case list/detail. Manual lookup may trigger read-only reconciliation immediately; no short polling is added.

- [ ] **Step 4: Verify GREEN**

Run focused tests, cockpit contract tests, and runtime tests.

- [ ] **Step 5: Commit**

Commit message: `feat: expose ERP return lifecycle in cockpit`

### Task 5: Full validation, integration, and gated production handoff

**Files:**
- Modify documentation only if verification uncovers a concrete operational constraint.

- [ ] **Step 1: Run full project validation**

Run all PHP tests, node tests, tenant SQL audit, PHP lint, JS checks, shell syntax checks, and `git diff --check` exactly as required by `docs/REGRAS-DE-ENTREGA.md` and CI.

- [ ] **Step 2: Review the diff independently**

Confirm no credentials, cookies, fiscal bodies, generic NF creation, or unsafe fallback were added; confirm external ERP write flag defaults OFF.

- [ ] **Step 3: Merge only a green reviewed head**

Open PR, verify checks for the exact head SHA, merge, and leave no task-owned PR pending.

- [ ] **Step 4: Follow auto-deploy**

Verify the merge SHA or a proven descendant is deployed through the repository auto-gate, the service is healthy, and read-only ERP return reconciliation behaves as designed.

- [ ] **Step 5: Discover/verify the real ERP sales-return operation before enabling writes**

Use an authenticated Olist/Tiny account and the `Vendas > Devoluções de venda` flow to capture the exact supported/authenticated operation that creates the sales-return record without issuing the NF. Implement that operation behind `SvAmazonErpSalesReturnGateway`, add a failing contract test before implementation, and repeat the full validation/merge/deploy cycle.

- [ ] **Step 6: Production canary before write enablement**

Choose a refunded order with a verified original Olist/Tiny sale and no return NF. Prove preflight finds no existing return NF, create exactly one sales-return record, read it back in ERP, run reconciliation again, and prove no second return is created. Only then may the ERP sales-return write gate be enabled.