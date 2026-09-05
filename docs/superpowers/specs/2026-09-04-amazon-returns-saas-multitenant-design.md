> Policy correction (2026-09-05): the former global 75-day rule is withdrawn. Use docs/superpowers/specs/2026-09-05-policy-modes-45-60.md; periods and action routes depend on program, order date and evidence.

# Amazon Returns & SAFE-T — API-First Multi-Tenant SaaS Design

**Date:** 2026-09-04
**Status:** Architecture approved in chat; written specification pending review
**Repository:** `amazon-returns-safet`
**Product direction:** commercial SaaS for multiple Amazon sellers

## 1. Goal

Evolve the isolated Amazon Returns / SAFE-T recovery system from a single-seller automation into a commercial multi-tenant SaaS that requires the least possible ongoing intervention from each seller.

The product operates primarily through documented Amazon SP-API capabilities, reports, and asynchronous notifications. Seller Central browser automation is never a dependency of the core discovery, monitoring, or financial reconciliation path.

Normal operation requires no daily Seller Central login. Human intervention is reserved for genuinely browser-only Amazon actions, ambiguous evidence, explicit Amazon authentication challenges, or periodic reauthorization required by Amazon.

## 2. Product principles

1. API-first and documented-source-first.
2. Tenant isolation is mandatory at every data and execution boundary.
3. Financial recovery is terminal only after authoritative credit reconciliation.
4. External writes are idempotent, auditable, and fail closed.
5. Browser automation is an optional executor, never the source of truth.
6. Normal monitoring cannot depend on a customer's always-on computer.
7. No fabricated evidence, policy, status, amount, deadline, or rationale.
8. Least privilege, least data retention, and tenant-scoped secrets apply from day one.

## 3. Verified external constraints

The architecture is based on the currently documented Amazon integration surface as verified on 2026-09-04:

- Amazon documents returns management through the Reports API rather than a dedicated returns-management API. The supported seller reports include `GET_FLAT_FILE_RETURNS_DATA_BY_RETURN_DATE` and related Prime/MFN report types.
- Amazon documents `REPORT_PROCESSING_FINISHED` for report completion and `TRANSACTION_UPDATE` for finance changes. Polling remains a recovery mechanism when a notification is delayed or unavailable.
- Finances v2024-06-19 is the primary transaction interface. The official Finances v0 model additionally exposes `SAFETReimbursementEventList`, including SAFE-T claim ID, reimbursement amount, posted date, reason code, and reimbursed items.
- Public seller applications require OAuth authorization and Selling Partner Appstore approval. Amazon states that public-app LWA refresh-token authorization must be renewed annually.
- Amazon's Seller Forums describe the SAFE-T Communication Center in Seller Central as the location for claim correspondence and appeals.
- No documented public SP-API operation was found for creating a SAFE-T claim, filing a SAFE-T appeal, or posting a SAFE-T Communication Center reply.

The absence of a documented operation is treated as a hard boundary. The product must not imitate an API by calling undocumented internal Seller Central endpoints.

## 4. Scope boundaries

### 4.1 Core SaaS

The core product discovers return/refund cases, evaluates policy, monitors SAFE-T state available from official sources, reconciles reimbursements, produces evidence-backed recommendations, and alerts only when action is required.

### 4.2 Optional execution adapters

Browser-assisted claim submission, appeal, Communication Center reply, and Seller Support interaction are separate adapters. A tenant can use the SaaS without installing or authorizing a browser adapter.

### 4.3 Deferred from the tenant-foundation tranche

The first implementation tranche does not include subscription billing, hosted browser credentials, automatic CAPTCHA/MFA handling, or unsupported scraping of Amazon internal APIs. Public developer registration and Selling Partner Appstore approval are not optional for third-party distribution; they are external launch prerequisites addressed before any outside-seller pilot.

## 5. Architecture decision

Use a shared application and shared MySQL data store with explicit `tenant_id` scoping on every tenant-owned row. A small shared control plane manages tenants, users, Amazon authorizations, feature gates, and worker leases. The existing Returns/SAFE-T domain becomes the tenant-scoped data plane.

This is preferred over one database per tenant because it reduces deployment, migration, backup, and support overhead while the product is growing. Enterprise dedicated databases may be added later behind the same tenant repository interfaces.

Because MySQL does not provide the row-level security model required here, application isolation must be structural:

- request and worker entrypoints establish an immutable `TenantContext`;
- domain repositories require `tenant_id` at construction and never expose unscoped list/update methods;
- every tenant-owned unique key includes `tenant_id`;
- child tables use composite `(tenant_id, parent_id)` references where practical;
- raw `PDO` access outside migration and repository code is prohibited by tests;
- cross-tenant negative tests run in CI;
- internal support access uses an audited break-glass workflow rather than hidden impersonation.

## 6. Control-plane model

Add shared control-plane entities:

- `tenants`: organization identity, status, locale, timezone, plan, retention policy;
- `users`: authenticated human identities;
- `tenant_memberships`: role assignment (`OWNER`, `OPERATOR`, `VIEWER`);
- `amazon_connections`: seller/merchant identity, marketplaces, OAuth state, encrypted LWA refresh token, authorization timestamps, and health;
- `mail_connections`: optional Gmail/Outlook or managed-forwarding configuration;
- `browser_agents`: optional agent identity, public key, capability set, heartbeat, and last authentication state;
- `tenant_feature_flags`: per-channel read/write gates and rollout state;
- `tenant_audit_log`: immutable security and administrative actions;
- `notification_subscriptions`: SQS destination, Amazon subscription ID, status, and renewal/repair state.

A tenant can own multiple Amazon connections. Every case belongs to one `tenant_id` and one `amazon_connection_id`; marketplace is an attribute of that connection and case.

The current ShopVivaliz seller becomes the first tenant during migration. Existing production identifiers and history are preserved rather than recreated.

## 7. Tenant-scoped domain model

Add `tenant_id` and, where relevant, `amazon_connection_id` to:

- return cases;
- immutable domain events;
- evidence metadata;
- outbox and dead letters;
- source cursors;
- manual overrides;
- browser bridge jobs and status observations.

Change uniqueness from global keys to tenant-qualified keys, including:

- `(tenant_id, amazon_connection_id, amazon_order_id, amazon_order_item_id)` for cases;
- `(tenant_id, idempotency_key)` for events and outbox;
- `(tenant_id, source, cursor_key)` for source cursors;
- `(tenant_id, case_id, kind, content_sha256)` for evidence.

Global marketplace policy versions remain shared, read-only reference data. Tenant-specific policy exceptions are stored separately, require a reason and actor, and never overwrite the global policy history.

## 8. Authentication and authorization

Replace the single environment-based admin login with application-owned user accounts and tenant membership.

- Browser sessions contain only the authenticated user ID, current tenant membership ID, rotation timestamp, and CSRF state.
- Tenant identity is resolved server-side from membership; a client-supplied tenant ID cannot grant access.
- Owners manage connections, feature gates, users, exports, and deletion.
- Operators manage cases and approved actions but cannot expose or rotate secrets.
- Viewers have read-only access.
- Platform support has no standing access to tenant data. Temporary support access requires tenant consent or a documented break-glass incident, expires automatically, and is audited.

Authentication can initially use secure email/password credentials with optional TOTP, while preserving an interface for a managed identity provider later. Production commercial launch requires MFA for owners and platform administrators.

## 9. Amazon seller onboarding

The target onboarding flow is:

1. User creates an organization and becomes its owner.
2. User chooses the Amazon region/marketplaces to connect.
3. The application starts a signed, expiring OAuth state transaction.
4. Amazon returns the authorization result; the server exchanges it for the seller-scoped LWA refresh token.
5. The server verifies seller identity and marketplace participation through documented APIs.
6. Required notification subscriptions are created or verified.
7. A bounded historical backfill is scheduled per marketplace.
8. Readiness checks show exactly which capabilities are active or blocked.
9. External write channels remain disabled until the tenant explicitly enables each channel after read-only validation.

Onboarding is resumable and idempotent. Refreshing, repeating, or abandoning an OAuth callback cannot create duplicate connections or bind a seller authorization to the wrong tenant.

## 10. Secrets and cryptography

No seller refresh token, mail token, browser credential, session cookie, or MFA value is stored in plaintext application tables or logs.

Use envelope encryption:

- one platform key-encryption key in a managed KMS or equivalent secret service;
- one versioned data-encryption key per tenant connection;
- authenticated encryption with tenant ID, connection ID, and credential type as associated data;
- key version recorded with every ciphertext;
- rotation without requiring seller reauthorization when possible;
- secret values decrypted only inside the adapter call boundary and immediately discarded.

Application client secrets are platform credentials and are separated from seller authorizations. Rotation is automated where Amazon supports it, with dual-secret overlap and rollback.

Annual seller authorization renewal is tracked as a dated health obligation. The system begins non-disruptive reminders well before expiry, verifies the replacement authorization, and only then retires the previous token.

## 11. API-first data acquisition

### 11.1 Orders

Use the documented Orders API to identify order items, fulfillment owner, marketplace, quantities, and immutable order identifiers. Order synchronization enriches an existing case but cannot downgrade verified facts to `UNKNOWN`.

### 11.2 Returns reports

Use `GET_FLAT_FILE_RETURNS_DATA_BY_RETURN_DATE` as the default seller-fulfilled return source, with other documented report types added behind a report-adapter interface when a marketplace or program requires them.

Report parsers are header-driven, tolerate added columns and unknown values, preserve the document hash, and fail closed when required identifiers are missing. Optional SAFE-T columns are feature-detected; their absence cannot break the whole return import.

### 11.3 Report completion notifications

Subscribe to `REPORT_PROCESSING_FINISHED` and correlate by selling-partner account, report ID, and tenant connection. A notification schedules document retrieval; it never directly mutates a case.

Scheduled polling remains a safety net because notifications are at-least-once and may be delayed. Duplicate notifications collapse through tenant-qualified idempotency keys.

### 11.4 Financial transactions

Use Finances v2024-06-19 transactions filtered by exact `ORDER_ID` for current transaction lifecycle and reconciliation. Consume `TRANSACTION_UPDATE` notifications where available, then refetch authoritative transaction data rather than trusting a notification as the complete ledger.

### 11.5 SAFE-T reimbursement events

Use the documented Finances v0 order financial-events operation as a compatibility adapter specifically for `SAFETReimbursementEventList` until equivalent claim-linked data is verified in a newer API.

Normalize each SAFE-T reimbursement into:

- `safe_t_claim_id`;
- posted timestamp;
- reimbursed amount and currency;
- reason code;
- reimbursed item facts;
- Amazon request ID and raw-response evidence hash.

The adapter is read-only. Empty event lists are valid and cause later rechecks, not a false denial. Pagination, the documented recent-order delay, reversals, and duplicate lifecycle representations must be handled explicitly.

## 12. Canonical data flow

1. An adapter obtains an official source record.
2. A normalizer emits a tenant-scoped immutable observation.
3. The event store rejects duplicates by tenant-qualified idempotency key.
4. A projector updates the current case view without rewriting historical facts.
5. The policy engine evaluates eligibility using the policy version effective for that marketplace and program.
6. The decision engine produces an action plus reasons, evidence references, and confidence.
7. Read-only actions execute automatically; write actions enter the tenant outbox only after all gates pass.
8. Financial reconciliation closes a case only when the actual seller credit is observed and matched.

No adapter writes directly to projected case state. This keeps source ingestion, business decisions, and external execution independently testable.

## 13. Policy architecture

the applicable 45/60-day matrix remains the active policy for the current ShopVivaliz production tenant where already verified. It is not hardcoded as a universal commercial rule.

Policy is versioned by:

- marketplace;
- fulfillment/program type;
- effective date range;
- triggering basis such as seller debit, refund date, delivery scan, or another verified fact;
- eligibility window and exclusion rules;
- authoritative source reference and content fingerprint.

A policy change creates a new immutable version. Existing decisions retain the version used at decision time. A new version can trigger a dry-run impact report before it affects live scheduling.

Forum posts and third-party articles are discovery signals only. They can open a policy-review task but cannot automatically change production eligibility. Only verified marketplace-applicable policy evidence can activate a version.

## 14. Decision engine

Every decision returns a structured result containing:

- proposed action;
- blocking reasons;
- required evidence and missing evidence;
- policy version;
- source event IDs;
- confidence/classification state;
- earliest next check time;
- whether human review is mandatory.

Unknown or conflicting facts produce `HUMAN_REVIEW`; they never default to an external write. Repeated denials are fingerprinted so the system does not send the same argument repeatedly through different channels.

## 15. External action modes

Each tenant selects one of three execution modes independently per channel.

### `API_ONLY`

The SaaS performs discovery, monitoring, evidence preparation, reconciliation, and alerts. Browser-only actions are presented as an action packet with the exact order, SAFE-T ID, rationale, deadline, and evidence. The seller performs the final Seller Central action.

### `ASSISTED`

The SaaS generates a signed, single-use task that opens the correct Seller Central workflow and guides the seller through the action. No seller password or browser session is sent to the SaaS.

### `AGENT_AUTOMATED`

An optional tenant-authorized browser agent executes supported Seller Central actions using a persistent profile owned by that tenant. The agent receives only one signed job at a time and returns a redacted result plus read-back proof.

`AGENT_AUTOMATED` is never silently enabled. It requires an explicit tenant opt-in, a successful read-only validation period, separate enablement for each write channel, and documented compatibility with Amazon policy and the customer environment.

## 16. Browser-agent isolation and authentication

Each browser profile is bound to one tenant and one Amazon connection. Profiles, cookies, and local browser storage cannot be shared between tenants.

The SaaS stores only agent identity, public key, capabilities, heartbeat, and authentication health. It does not store Seller Central passwords, cookies, or MFA values.

When Amazon expires or challenges a Seller Central session, the agent reports `AUTH_REQUIRED` or `HUMAN_CHALLENGE`, pauses affected browser-only jobs, and sends one actionable notification. API-first monitoring and reconciliation continue normally.

A user should therefore not need to log in daily. Login is required only when Amazon invalidates that tenant's browser session or demands a fresh challenge for a browser-only operation.

## 17. SAFE-T communication and email

Seller Central Communication Center is treated as the primary claim-correspondence location when the marketplace uses it. Gmail/Outlook ingestion is optional enrichment, not the only way to learn claim state.

Mail connection options are ordered by least privilege:

1. managed tenant-specific forwarding address for messages the seller chooses to forward;
2. provider OAuth limited to the smallest usable read/send scopes;
3. manual upload of a message artifact for exceptional review.

Incoming content is correlated by tenant, message/thread metadata, SAFE-T ID, and order ID. A message from one tenant can never match another tenant's case even when an order-like identifier collides.

A negative reply is classified before any response is scheduled. Supported decisions remain `RESPOND_EMAIL`, `OPEN_SUPPORT`, `WAIT`, `CREDIT_PENDING`, `CLOSED_LOSS`, and `HUMAN_REVIEW`. No repeated template loop is permitted.

## 18. Write gating and outbox

Every external write requires all of the following:

- platform channel enabled;
- tenant channel enabled;
- connection healthy;
- compatible executor available;
- case decision current and not superseded;
- required evidence present;
- deterministic idempotency key unused;
- no active equivalent job;
- no circuit breaker open;
- deadline still valid.

Jobs include `tenant_id`, `amazon_connection_id`, case ID, action, decision fingerprint, policy version, evidence references, expiry, and signature. Secrets are never embedded in the job.

The worker claims jobs with leases. Expired leases return to `PENDING` only when retry safety is proven; uncertain external write state goes to human review rather than blind retry.

## 19. Financial truth and case closure

A report field, email promise, SAFE-T approval screen, or support reply can move a case to `CREDIT_PENDING`, but none can close it as recovered.

A case reaches `RECOVERED` only when authoritative financial observations identify sufficient seller credit for the expected reimbursement. Prefer a claim-linked `SAFETReimbursementEvent` when present and corroborate it with current transaction data where possible.

Reversals or later debit events reopen a previously recovered case automatically, preserving the original closure and reopening history as immutable events.

`CLOSED_LOSS` requires an exhausted defensible channel, no unresolved financial inconsistency, a terminal reason, supporting evidence, and either an approved automatic rule or explicit human decision according to tenant policy.

## 20. Scheduling and workload isolation

The scheduler operates per tenant connection with fair-use limits and rate-aware queues.

- High-volume tenants cannot starve smaller tenants.
- Amazon rate limits are tracked per operation and selling-partner authorization.
- Backfills have lower priority than deadline-sensitive claim monitoring.
- Failed tenants are isolated by connection-level circuit breakers.
- A malformed report or revoked token for one tenant cannot pause global processing.
- All recurring work has deterministic next-run timestamps and bounded retry policies.

Workers may run in one service initially, but adapters, scheduler, projector, decision engine, and action executors remain separate components so they can be split horizontally without changing domain behavior.

## 21. User experience and intervention budget

The default dashboard is exception-driven rather than a raw queue monitor. It shows:

- money recovered, pending, and at risk;
- cases approaching a policy or appeal deadline;
- connection health and reauthorization date;
- actions that specifically require a person;
- blocked automation with a plain-language reason;
- an immutable timeline for each case.
The product target is zero routine daily interaction. Notifications are deduplicated and grouped unless a deadline or security event is urgent. Every alert states what happened, why automation stopped, the deadline, and the smallest required user action.

Read-only connection degradation does not generate repeated messages. One incident stays open until recovered or escalated.

## 22. Evidence storage and privacy

Evidence objects use tenant-prefixed storage paths and immutable content hashes. The database stores only a storage reference, metadata, and hash; signed download links are short-lived and tenant-authorized.

Raw Amazon report documents and financial responses are retained only for the configured audit period and encrypted at rest. Derived normalized facts can be retained longer when legally and contractually appropriate.

The first commercial version avoids restricted PII wherever possible. Buyer names, addresses, phone numbers, and unneeded message content must not be collected merely because an API can return them. Adding a restricted role requires a separate data-flow inventory, retention rule, access review, and Amazon approval.

Tenant export and deletion are explicit workflows. Deletion revokes active connections, removes or cryptographically destroys secrets, stops jobs, and deletes tenant-owned evidence according to the retention/legal-hold policy.

## 23. Auditability

The audit trail records:

- authentication and membership changes;
- Amazon/mail/agent connection changes;
- feature-gate changes;
- policy version used by each decision;
- every external action request and result;
- human overrides with before/after state and reason;
- support access and data export/deletion;
- key rotation and authorization renewal events.

Audit records contain identifiers and hashes, not secrets. Tenant users can view their operational audit history; security-only fields remain restricted to platform administrators.

## 24. Reliability and observability

Metrics are partitioned by tenant and connection but aggregated without exposing tenant data. Required signals include queue age, stuck leases, source lag, report failures, token health, notification lag, browser auth state, policy deadlines, reconciliation lag, and write success/read-back rates.
Health checks distinguish platform health, tenant health, and individual adapter health. A global `ready` badge cannot hide a revoked seller authorization or stale source cursor.

Every `PROCESSING` job has a lease deadline. Production alerts fire when a read or write job exceeds its action-specific maximum age; repair jobs may requeue safe reads, but writes with uncertain external state require read-back or review.

Backups are encrypted, tested by restore, and include the control plane, tenant domain data, and evidence-reference inventory. Restore drills verify that tenant boundaries and idempotency keys survive recovery.

## 25. Deployment topology

The current isolated service and domain remain the initial runtime:

- application: `https://returns.shopvivaliz.com.br`;
- repository: `/home/ubuntu/amazon-returns-safet`;
- deploy root: `/home/ubuntu/amazon-returns-deploy`;
- service: `amazon-returns-safet.service`.

The application remains deployable as one artifact initially. Configuration moves from one seller's environment variables into platform configuration plus encrypted connection records. Only platform bootstrap secrets remain in the host environment.

Background processes use the same release artifact but explicit commands/roles, for example API web, scheduler, source workers, projector, reconciliation worker, notification consumer, and optional bridge gateway.

Deployments are backward-compatible across one release boundary: schema expansion precedes code use; destructive cleanup occurs only after production verification and rollback expiry.

## 26. Migration of the current tenant

Migration is performed without changing the already verified 37-case business history:

1. Create the platform tenant representing ShopVivaliz.
2. Create its Amazon, mail, and Fred-Win connection records.
3. Add nullable tenant/connection columns and backfill every current row.
4. Verify there are no orphan or unassigned rows.
5. Add tenant-qualified indexes and foreign-key constraints.
6. Switch repositories and workers to mandatory tenant context.
7. Run source/target shadow comparison including tenant-qualified identities.
8. Confirm the API-only sources reproduce the expected current projections.
9. Keep all external write gates off during migration.
10. Enable one source and one action channel at a time with production evidence.

The migration fails if any tenant-owned row remains unassigned, any original idempotency key changes meaning, any current case disappears, or cross-tenant tests can access the seeded second tenant.

## 27. Delivery phases

### Phase A — Tenant foundation

Introduce control-plane tables, `TenantContext`, tenant-scoped repositories, schema migration, current-tenant backfill, role-aware authentication, and cross-tenant tests. No new external behavior is enabled.

### Phase B — API-first source completeness

Complete report notifications, transaction notifications, Finances v0 SAFE-T reimbursement ingestion, resilient parsers, per-connection cursors, and tenant health. Prove routine monitoring without Seller Central login.

### Phase C — OAuth-capable onboarding implementation

Implement OAuth state/callback handling, connection verification, marketplace discovery, initial backfill, annual renewal workflow, and owner-facing connection controls. Validate with the current tenant and Amazon-supported test paths; do not authorize outside sellers yet.

### Phase D — Public application and pilot gate

Complete public-developer registration, required security controls, public-app registration, and Selling Partner Appstore approval. Only after this gate may explicitly contracted outside sellers enter a controlled read-only pilot.

### Phase E — Exception-driven operations

Add deadline alerts, action packets, tenant roles, evidence UI, decision explanations, and assisted browser-only actions. Keep automated writes disabled by default.

### Phase F — Controlled optional automation

Generalize the browser agent from Fred-Win into a tenant-bound signed agent, validate read-only operation, then enable `SAFE_T_SUBMIT`, `SAFE_T_APPEAL`, communication reply, and Seller Support separately under per-tenant gates.

### Phase G — Commercial readiness

Complete terms/privacy/data-processing documents, support tooling, usage metering, billing, backups/restore evidence, operational security review, and pilot exit criteria.

## 28. Failure handling

### Revoked or expired Amazon authorization

Mark only that connection degraded, pause its API jobs, preserve deadlines, and notify its owners once. Other tenants continue. Reauthorization creates a replacement credential only after seller identity matches the existing connection.

### Report schema drift

Unknown columns are preserved in evidence metadata when safe and ignored by projections. Missing required identifiers quarantine the document, open an operator incident, and leave previous case state unchanged.

### Delayed or incomplete financial data

Remain in `CREDIT_PENDING`, schedule bounded rechecks, and display the last authoritative observation time. Empty Finances results never imply denial, loss, or zero credit.

### Notification duplication or loss

Tenant-qualified idempotency handles duplicates. Periodic cursor and reconciliation sweeps recover missed notifications.

### Unknown browser write result

Do not retry. Mark `WRITE_RESULT_UNCERTAIN`, perform independent read-back when possible, and otherwise require review. This prevents duplicate claims, appeals, or support cases.

### Tenant-specific overload

Apply connection-level backpressure and quotas. Deadline-sensitive jobs retain priority, while backfills pause first.

## 29. Testing strategy

All production changes follow red-green-refactor TDD. Test categories include:

- pure domain tests for policy, projections, decisions, and reconciliation;
- parser fixtures from redacted real Amazon reports and messages;
- repository tests proving mandatory tenant scope;
- cross-tenant tests using identical order IDs, SAFE-T IDs, and idempotency inputs;
- OAuth state, callback replay, seller mismatch, and token-renewal tests;
- notification signature/source, duplicate, out-of-order, and recovery-sweep tests;
- Finances v2024 and v0 pagination, empty-list, delayed-event, reversal, and duplicate-lifecycle tests;
- outbox lease expiry and uncertain-write tests;
- browser-agent signature, tenant binding, capability, and read-back tests;
- migration tests proving all 37 current cases/events remain identical after tenant backfill;
- security tests for authorization, CSRF, secret redaction, evidence paths, and support access;
- production smoke tests with all writes off before any channel rollout.

CI rejects unscoped SQL against tenant-owned tables, public methods that can fetch tenant data without context, and idempotency keys that omit tenant identity.

## 30. Commercial acceptance criteria

The architecture is accepted for pilot use only when all of the following are demonstrated:

1. Two seeded tenants can use identical Amazon-like identifiers without collision or disclosure.
2. A user from tenant A cannot read, infer, mutate, export, or enqueue work for tenant B.
3. The current 37 ShopVivaliz cases migrate 37/37 with zero projection mismatch.
4. Returns reports, Orders, and Finances run independently per Amazon connection.
5. SAFE-T reimbursement events are linked by claim ID and amount without browser data.
6. A seller authorization failure affects only that seller connection.
7. Duplicate Amazon notifications and repeated jobs create no duplicate event or write.
8. No read or write job remains indefinitely in `PROCESSING`.
9. API-only monitoring operates without daily Seller Central login.
10. Browser-only authentication expiry pauses only browser actions.
11. Every proposed action shows its evidence and policy version.
12. Every write channel is off by default and can be rolled back independently.
13. No case closes as `RECOVERED` without reconciled financial credit.
14. Reversed credit reopens the case automatically and audibly.
15. Backup restore preserves tenant isolation, event order, and idempotency.
16. A pilot seller can onboard, authorize Amazon, and reach healthy read-only status without operator database edits.

Commercial launch additionally requires Amazon public-developer approval, Appstore/listing compliance where applicable, production OAuth renewal tests, documented incident response, security review, customer terms, privacy notice, and support ownership.

## 31. Principal risks and mitigations

### Amazon changes report fields or policy

Mitigation: header-driven parsers, raw evidence hashes, marketplace-versioned policy, schema-drift quarantine, and impact reports before policy activation.

### Finances interfaces disagree or lag

Mitigation: retain independent observations, never infer loss from absence, reconcile claim-linked events with transactions, and keep the case pending until the financial picture is coherent.

### Browser automation violates expectations or becomes unstable

Mitigation: core product remains useful without it; browser capability is opt-in, separately gated, tenant-local by default, contract-tested, and fail-closed on UI drift.

### Cross-tenant data leak

Mitigation: mandatory tenant context, tenant-qualified schema, repository-only data access, negative CI tests, encrypted tenant-scoped evidence, least-privilege support, and incident alerts.

### OAuth or Appstore approval delays

Mitigation: current seller remains a private/internal production tenant while public-app work proceeds; pilot onboarding starts only after Amazon authorization requirements are satisfied.

### Excessive user notifications

Mitigation: incident deduplication, digesting, severity thresholds, deadline-aware escalation, and one active alert per unresolved connection issue.

## 32. Architectural invariants

The following rules cannot be relaxed by feature flags:

- no tenant-owned record without tenant identity;
- no external write without a current decision and deterministic idempotency;
- no browser credential stored by the core SaaS;
- no unsupported internal Amazon endpoint;
- no automatic CAPTCHA or MFA bypass;
- no recovery closure before financial reconciliation;
- no cross-tenant support access without audit;
- no marketplace policy applied outside its verified scope.

## 33. Evidence basis

Authoritative design inputs reviewed on 2026-09-04:

- Amazon SP-API Onboarding as a Developer;
- Amazon SP-API Registration Overview;
- Amazon SP-API Seller Use Cases, especially Returns Management and Finance and Settlement;
- Amazon SP-API Report Type Values and Reports API lifecycle guidance;
- Amazon `selling-partner-api-models` Finances v0 OpenAPI model;
- Amazon notification documentation for `REPORT_PROCESSING_FINISHED` and `TRANSACTION_UPDATE`;
- the production-tested report, Gmail, Seller Central, and finance adapters in this repository.

Seller Forum discussions were used only to identify operational edge cases and confirm that claim correspondence/appeals are performed in Seller Central. Forum policy statements are not production policy evidence unless separately verified for the tenant's marketplace and effective date.

## 34. Decision summary

Proceed with an API-first, shared-schema multi-tenant SaaS. Build tenant isolation and connection-scoped execution before adding another seller. Complete official report/notification/financial coverage before depending on Seller Central reads. Keep browser actions optional, tenant-bound, and disabled by default.

The first implementation plan starts with Phase A tenant foundation and preserves the current single-tenant production behavior behind a migrated ShopVivaliz tenant. It must not enable any new external write during the architecture migration.
