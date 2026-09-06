# SAFE-T Review Cockpit and Learned Memory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a production-safe SAFE-T cockpit that shows every case and write, routes only genuinely novel/ambiguous situations to AI-assisted human review, and automatically reuses approved deterministic rules for matching existing and future cases.

**Architecture:** Keep `SvAmazonSafeTDecisionEngine` as the base business-rule authority, then add a `SvAmazonDecisionCoordinator` that only consults learned memory when the base result is review-gated. Learned memory resolves a review through structured, versioned effects and never performs external writes directly; every write still traverses scheduler, outbox, versioned write profile, bridge, idempotency and read-back. The admin cockpit consumes tenant-scoped repositories and a timeline projector; AI is a recommendation-only adapter using strict structured output and is optional at runtime.

**Tech Stack:** PHP 8.3, MySQL/InnoDB, vanilla HTML/CSS/JavaScript, existing PHP worker/daemon, Node Seller Central bridge, OpenAI Responses API with strict JSON Schema for optional review suggestions.

**Spec:** `docs/superpowers/specs/2026-09-06-safet-review-cockpit-memory-design.md`

## Global Constraints

- Existing business rules and active learned memories execute automatically; they never require redundant human approval.
- Human review is only for novel, incomplete, conflicting or materially different situations unresolved by existing rules.
- AI is advisory only and cannot enqueue or perform any Seller Central, Gmail or Support write.
- Learned rules are tenant + Amazon-connection scoped and versioned; ShopVivaliz rules are not silently shared globally.
- Full-credit reconciliation, authoritative physical receipt, exact Amazon wait dates, appeal deadlines and damaged-return initial-manual-opening rules remain hard gates.
- External writes continue through the existing versioned write profile, kill switch, idempotent outbox and read-back contract.
- `SAFE_T_EMAIL_REVIEW`, `SAFE_T_EMAIL_REPLY`, `SELLER_SUPPORT_OPEN` and `SELLER_SUPPORT_UPDATE` remain disabled unless independently activated.
- No historical narrative may be fabricated. New external writes must preserve the exact submitted narrative immutably; old missing text is displayed as unavailable.
- Every database access remains server-bound to the current tenant and Amazon connection.
- Use TDD for every behavior change; run the full PHP suite, SQL tenant audit, syntax checks and production shadow/replay before enabling learned-rule execution.

---
## Planned file structure

- `includes/amazon-returns/ReviewContext.php` — canonical review facts/signature; strips one-off IDs, dates and amounts from equality matching while exposing extracted variables separately.
- `includes/amazon-returns/ReviewRepository.php` — append/open/decide review episodes with optimistic version checks.
- `includes/amazon-returns/LearnedRuleRepository.php` — immutable learned-rule versions, activation, supersession and disable operations.
- `includes/amazon-returns/RuleApplicationRepository.php` — audit every candidate/matched/blocked rule application.
- `includes/amazon-returns/LearnedRuleEngine.php` — deterministic signature matching, specificity ordering and conflict detection.
- `includes/amazon-returns/DecisionCoordinator.php` — base decision first; learned rule only for review-gated outcomes; unresolved cases create/open review episodes.
- `includes/amazon-returns/ReviewAdvisor.php` — recommendation interface and schema validation.
- `includes/amazon-returns/OpenAiReviewAdvisor.php` — server-side OpenAI Responses API adapter with `store:false` and strict JSON Schema output.
- `includes/amazon-returns/ReviewService.php` — human decision transaction, rule promotion/versioning, affected-case preview and re-evaluation orchestration.
- `includes/amazon-returns/CockpitTimeline.php` — combines events, evidence, outbox rows, reviews and rule applications into one chronological projection.
- `admin/amazon-returns/api/cases.php` — paginated/filterable all-case list.
- `admin/amazon-returns/api/case.php` — expand existing endpoint to return 360-degree detail/timeline.
- `admin/amazon-returns/api/reviews.php` and `api/review.php` — review queue/detail.
- `admin/amazon-returns/api/review-suggest.php` — advisory AI suggestion only.
- `admin/amazon-returns/api/review-decision.php` — CSRF-protected approve/edit/reject/wait/exception submission.
- `admin/amazon-returns/api/rules.php` and `api/rule-status.php` — rule list and disable/supersede control.
- `admin/amazon-returns/index.php`, `assets/cockpit.css`, `assets/cockpit.js` — responsive single-workspace cockpit.
- `scripts/replay-learned-rules.php` — read-only historical/current shadow replay before activation.
- Existing `Schema.php`, `TenantPersistence.php`, `SafeTDecisionEngine.php`, `daemon.php`, `Runtime.php`, provisioning/verifier and tests are modified only where their owned responsibility requires it.

---

### Task 1: Add tenant-scoped review and learned-memory persistence
**Files:**
- Create: `includes/amazon-returns/ReviewRepository.php`
- Create: `includes/amazon-returns/LearnedRuleRepository.php`
- Create: `includes/amazon-returns/RuleApplicationRepository.php`
- Modify: `includes/amazon-returns/Schema.php`
- Modify: `includes/amazon-returns/TenantPersistence.php`
- Modify: `includes/amazon-returns/TenantMigration.php`
- Modify: `scripts/audit-tenant-sql.php`
- Test: `tests/review-memory-persistence-test.php`
- Test: `tests/amazon-returns-tenant-schema-test.php`
- Test: `tests/amazon-returns-tenant-isolation-test.php`

**Interfaces:**
- `SvAmazonReviewRepository::open(int $caseId,string $reason,string $contextHash,array $context): array`
- `SvAmazonReviewRepository::decide(int $reviewId,int $expectedVersion,array $decision): array`
- `SvAmazonLearnedRuleRepository::active(): array`
- `SvAmazonLearnedRuleRepository::promote(array $definition): array`
- `SvAmazonLearnedRuleRepository::setStatus(int $ruleId,int $expectedVersion,string $status): array`
- `SvAmazonRuleApplicationRepository::record(array $application): int`

- [ ] **Step 1: Write schema/repository tests that fail before implementation**

```php
$tables=['amazon_return_reviews','amazon_return_learned_rules','amazon_return_rule_applications'];
foreach($tables as $table){
    rmAssert(str_contains($ddl,"CREATE TABLE IF NOT EXISTS `{$table}`"),"missing {$table}");
    rmAssert(str_contains($ddl,'`tenant_id` BIGINT UNSIGNED NOT NULL'),"{$table} tenant scope");
}
```
- [ ] **Step 2: Run the targeted tests and confirm RED**

Run:
```bash
php tests/review-memory-persistence-test.php
php tests/amazon-returns-tenant-schema-test.php
php tests/amazon-returns-tenant-isolation-test.php
```
Expected: failures for missing tables/repositories and updated schema count.

- [ ] **Step 3: Add the three tables and scoped repositories**

Use immutable/versioned rows. Minimum table invariants:
```sql
UNIQUE KEY uq_review_open_scope (tenant_id,amazon_connection_id,case_id,status,context_hash),
UNIQUE KEY uq_learned_rule_version (tenant_id,amazon_connection_id,rule_family_key,version),
KEY idx_rule_application_case_time (tenant_id,amazon_connection_id,case_id,created_at,id)
```
`amazon_return_reviews` stores `status`, `version`, `review_reason`, `context_hash`, `context_json`, `ai_suggestion_json`, `ai_model`, `human_decision_json`, `decision_mode`, `actor`, `decided_at`, and `resulting_rule_id`.
`amazon_return_learned_rules` stores family/version/status, `match_json`, `effect_json`, source review, specificity, timestamps and outcome counters.
`amazon_return_rule_applications` stores rule/case/signature/result/blockers/action reference and timestamp.

- [ ] **Step 4: Wire repositories into `SvAmazonTenantPersistence` and migration/audit allowlists**

```php
public readonly SvAmazonReviewRepository $reviews;
public readonly SvAmazonLearnedRuleRepository $learnedRules;
public readonly SvAmazonRuleApplicationRepository $ruleApplications;
```
All repository SQL must include both `tenant_id` and `amazon_connection_id`; cross-tenant IDs must throw before mutation.

- [ ] **Step 5: Run tests GREEN and commit**

Run the three targeted tests plus `php scripts/audit-tenant-sql.php`.
Commit: `feat: add SAFE-T review memory persistence`

---
### Task 2: Build canonical ReviewContext and similarity signatures

**Files:**
- Create: `includes/amazon-returns/ReviewContext.php`
- Test: `tests/review-context-signature-test.php`

**Interfaces:**
- `SvAmazonReviewContext::build(array $case,array $timeline,array $policy,array $baseDecision): array`
- Returns `facts`, `signature`, `signature_hash`, `variables`, `evidence_refs`, `review_reason`.

- [ ] **Step 1: Write RED tests for stable equivalence and material differences**

```php
$a=SvAmazonReviewContext::build($caseA,$timelineA,$policy,$review);
$b=SvAmazonReviewContext::build($caseB,$timelineB,$policy,$review);
rcsSame($a['signature_hash'],$b['signature_hash'],'IDs, amounts and literal dates must not affect equality');
$bCase=$caseB;$bCase['program']='DELIVERY_BY_AMAZON';
$c=SvAmazonReviewContext::build($bCase,$timelineB,$policy,$review);
rcsAssert($a['signature_hash']!==$c['signature_hash'],'program is material');
```

- [ ] **Step 2: Run test to verify missing class/behavior fails**

Run: `php tests/review-context-signature-test.php`
Expected: RED because `SvAmazonReviewContext` does not exist.

- [ ] **Step 3: Implement normalized dimensions and extracted variables**

The equality signature must contain only canonical categories:
```php
[
 'review_reason'=>$baseDecision['reason'], 'marketplace'=>$case['marketplace_id'],
 'program'=>$case['program'], 'lifecycle'=>self::lifecycle($case),
 'physical'=>self::physicalCategory($case), 'refund_initiator'=>$case['refund_initiator'],
 'financial'=>self::financialPosture($case), 'evidence_pattern'=>self::evidencePattern($timeline),
 'wait_condition'=>self::waitCategory($timeline), 'appeal_window'=>self::appealPosture($case),
 'damaged_manual_opening'=>self::damagedManualOpening($case,$timeline),
]
```
Store literal values only under `variables`, for example `promised_date`, `appeal_deadline_at`, `outstanding_amount`, `safe_t_id`; they are available to a rule effect but excluded from `signature_hash`.

- [ ] **Step 4: Add explicit fail-closed normalization**

Unknown enum/category values become a canonical `UNKNOWN` dimension, not empty text. Contradictory evidence adds `material_conflict=true`, which prevents automatic memory reuse.

- [ ] **Step 5: Run GREEN tests and commit**

Run:
```bash
php tests/review-context-signature-test.php
php tests/return-action-router-test.php
php tests/amazon-returns-safet-decision-test.php
```
Commit: `feat: add deterministic review similarity signatures`

---

### Task 3: Implement learned-rule matching, specificity and conflicts

**Files:**
- Create: `includes/amazon-returns/LearnedRuleEngine.php`
- Test: `tests/learned-rule-engine-test.php`

**Interfaces:**
- `SvAmazonLearnedRuleEngine::match(array $context,array $activeRules): array`
- Return shape: `['status'=>'NONE|MATCH|CONFLICT','rule'=>?array,'conflicts'=>list<array>]`.

- [ ] **Step 1: Write RED tests for exact match, non-match, specificity and conflict**

```php
$r=$engine->match($context,[$broad,$specific]);
lreSame('MATCH',$r['status'],'one compatible winner');
lreSame($specific['id'],$r['rule']['id'],'more-specific rule wins');
$r=$engine->match($context,[$specific,$incompatibleSameSpecificity]);
lreSame('CONFLICT',$r['status'],'incompatible equal-specificity rules fail closed');
```
- [ ] **Step 2: Run RED test**

Run: `php tests/learned-rule-engine-test.php`
Expected: missing engine class.

- [ ] **Step 3: Implement deterministic predicate matching**

`match_json` is a map of required signature dimensions. A rule matches only when every stored predicate equals the current canonical signature. `specificity` equals the number of required predicates and is recalculated server-side when promoting a rule; the client never sets trusted priority.

Supported effect schema in phase 1:
```php
[
 'action'=>'WAIT|CHECK_FINANCES|SAFE_T_SUBMIT|SAFE_T_APPEAL|CLOSE_LOSS',
 'reason'=>'HUMAN_APPROVED_RULE',
 'parameters'=>['next_action_at_from'=>'promised_date']
]
```
Only known parameter bindings may reference keys from `ReviewContext.variables`; arbitrary executable expressions are rejected.

- [ ] **Step 4: Make conflicts explicit and auditable**

If highest-specificity matches have different normalized effects, return `CONFLICT`; do not choose by creation date. Superseded/disabled rules are excluded before matching.

- [ ] **Step 5: Run GREEN test and commit**

Run: `php tests/learned-rule-engine-test.php`
Commit: `feat: add deterministic learned rule matching`

---

### Task 4: Add the decision coordinator so known rules run automatically

**Files:**
- Create: `includes/amazon-returns/DecisionCoordinator.php`
- Modify: `includes/amazon-returns/SafeTDecisionEngine.php`
- Modify: `workers/amazon-returns/scheduler.php`
- Modify: `workers/amazon-returns/daemon.php`
- Test: `tests/decision-coordinator-test.php`
- Test: `tests/known-rule-auto-flow-test.php`
**Interfaces:**
- `SvAmazonDecisionCoordinator::nextAction(array $case,array $timeline,array $policy,?DateTimeImmutable $now=null): array`
- `SvAmazonSafeTDecisionEngine::guardLearnedEffect(array $effect,array $case,array $timeline,array $policy,DateTimeImmutable $now): array`
- `SvAmazonReturnsScheduler::scheduleDecision(SvAmazonTenantReturnsOutbox $target,array $case,array $decision): array`

- [ ] **Step 1: Write RED tests proving existing rules bypass review**

```php
$r=$coordinator->nextAction($eligible,[],$eligiblePolicy,$now);
dcSame('SAFE_T_SUBMIT',$r['action'],'existing deterministic rule runs without review');
dcSame(0,$reviews->countOpenForCase(77),'known base rule must not create review');
$r=$coordinator->nextAction($ambiguous,$timeline,$policy,$now);
dcSame('WAIT',$r['action'],'matching learned memory resolves review automatically');
dcSame(0,$reviews->countOpenForCase(88),'known memory must not ask again');
```
Also test `NONE` opens one idempotent review, repeated daemon cycles do not duplicate it, and `CONFLICT` opens `LEARNED_RULE_CONFLICT`.

- [ ] **Step 2: Run RED tests**

Run:
```bash
php tests/decision-coordinator-test.php
php tests/known-rule-auto-flow-test.php
```
Expected: missing coordinator and auto-memory behavior.

- [ ] **Step 3: Implement coordinator order of operations**

```php
$base=$this->base->nextAction($case,$timeline,$policy,$now);
if(!in_array($base['action'],['HUMAN_REVIEW','BLOCKED_REVIEW'],true)) return $base;
$context=SvAmazonReviewContext::build($case,$timeline,$policy,$base);
$match=$this->ruleEngine->match($context,$this->rules->active());
if($match['status']==='MATCH') return $this->applyMatchedRule($match['rule'],$context,$case,$timeline,$policy,$now);
return $this->openReview($case,$base,$context,$match);
```
No AI call occurs in the daemon path.
