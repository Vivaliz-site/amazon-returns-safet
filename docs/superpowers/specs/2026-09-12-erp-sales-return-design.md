# ERP sales return lifecycle design

**Date:** 2026-09-12  
**Scope:** `Vivaliz-site/amazon-returns-safet`  
**Owner decision:** approved in the Amazon Returns project conversation on 2026-09-12.

## Goal

For Amazon orders that were refunded to the customer and whose original sale was issued in Olist/Tiny ERP, the Returns application must prepare the corresponding **Devolução de venda** in Olist/Tiny without issuing the return NF automatically. The user remains responsible for generating the **NF de devolução** inside the ERP.

## Non-negotiable idempotency rule

Before any ERP sales-return write, the system must search Olist/Tiny for an already-issued return NF associated with the same Amazon order/original sale. If a return NF already exists, the application **must not create a new sales return**. It only links the existing NF to the Amazon Returns case and exposes the current status to the operator.

A second independent guard must run immediately before the external write, so a race between the initial lookup and the write cannot create a duplicate.

## Matching identity

The primary identity is the Amazon order plus the original Olist/Tiny sale. Matching uses, when available:

- Amazon order ID (`numeroPedidoEcommerce` in ERP data);
- original sale invoice ID/number and access key;
- refunded SKU/order-item identities;
- refunded quantities.

A candidate entry NF is accepted as a return NF only when its detailed ERP data identifies it as a return/return-related fiscal document. A simple incoming invoice for the same customer/order is not sufficient. Ambiguous matches block creation rather than guessing.

## Public API read path

The Olist/Tiny public API v3 is used for read-only reconciliation. Existing project credentials remain sourced from the protected ERP environment file and are never persisted in Git.

The lookup must query entry notes (`tipo=E`) and obtain note details before classifying a note as a return. The read path records only identifiers and non-secret metadata required by the application.

## Sales-return write path

The requested operation is the Olist/Tiny **Devoluções de venda** operational record, not a generic sales order and not direct creation/authorization of a fiscal NF.

The public API v3 does not expose a documented sales-return creation endpoint. Therefore the application must use a dedicated `ErpSalesReturnGateway` abstraction. Its production implementation may only be enabled after the exact Olist/Tiny authenticated operation has been observed and verified against the ERP UI or an official supported interface.

There is no fallback that silently creates another ERP object. If the verified return operation is unavailable, the workflow remains blocked/pending and records the reason.

## Lifecycle

For each refunded Amazon order backed by an Olist/Tiny sale:

1. resolve the original ERP sale/invoice;
2. search for an existing return NF;
3. if a return NF exists, link it and mark `RETURN_INVOICE_EXISTS`; do not create a return;
4. otherwise check whether an ERP sales-return record is already known/read back;
5. if no return exists and the external-write gate is disabled, mark `READY_TO_CREATE` without writing;
6. if the gate is enabled, repeat the return-NF preflight and create exactly one Olist/Tiny sales-return record;
7. verify the created return by ERP readback before marking `RETURN_CREATED_WAITING_INVOICE`;
8. on subsequent reconciliation, when the user issues the NF de devolução in ERP, link it and transition to `RETURN_INVOICE_EXISTS`.

One sales-return workflow is scoped to the original Amazon order/sale, aggregating the refunded items and quantities for that sale instead of creating one ERP return per local case row.

## Persistence

Create tenant-scoped persistence for the ERP return lifecycle. It stores only operational references, including:

- tenant/connection;
- Amazon order ID;
- original sale invoice ID/number/key when available;
- ERP sales-return ID when known;
- lifecycle status;
- return NF ID/number/key/status when known;
- idempotency key;
- last checked/created/updated timestamps;
- last safe error code/message suitable for operator diagnostics.

A database uniqueness constraint must prevent more than one workflow row for the same tenant, Amazon connection and Amazon order/original sale identity.

## UI and API

Case APIs and the operator UI expose plain Portuguese status, without internal codes as the primary wording:

- `Devolução no ERP pronta para criar`;
- `Devolução criada no ERP — aguardando NF de devolução`;
- `NF de devolução já emitida`;
- `Não foi possível criar a devolução automaticamente` when the verified ERP operation is unavailable or ambiguous.

When known, show the NF de devolução number and date. The UI must not offer a button that emits/authorizes the NF; that fiscal action remains in Olist/Tiny.

## Scheduling

The reconciliation joins the existing 12-hour business cadence and may also run immediately for an explicit operator lookup. It must not introduce short-interval polling. A manual lookup never waits for the next scheduled cycle.

## External-write gate

ERP sales-return creation is a separate external-write channel and is OFF by default. Merge/deploy does not authorize the write. Enablement requires:

1. verified Olist/Tiny sales-return operation;
2. production authentication/readiness;
3. a safe canary order with no existing return NF;
4. successful readback proving one and only one return was created;
5. repeat lookup proving the duplicate guard suppresses a second write.

## Failure behavior

- ERP authentication failure: preserve workflow and retry on normal cadence; do not downgrade to an unsafe method.
- Ambiguous sale or return-NF match: block write and expose a clear diagnostic.
- Timeout/uncertain write outcome: do not retry blindly. First perform readback and return-NF lookup to determine whether the first attempt succeeded.
- Existing return NF discovered at any point: stop all creation attempts and link the existing NF.

## Verification

Automated coverage must prove at least:

- an existing qualifying return NF suppresses the sales-return write;
- an unrelated entry NF does not suppress it;
- a different Amazon order does not match;
- ambiguous qualifying return NFs block creation;
- gate-off mode performs no external write;
- a successful create transitions only after readback;
- a second execution is idempotent;
- UI/API wording exposes the lifecycle in Portuguese.

Production verification must remain read-only until the dedicated ERP write gate is explicitly accepted through the canary sequence above.