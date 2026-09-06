# SAFE-T Review Cockpit and Learned Memory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a production-safe SAFE-T cockpit that shows every case and write, routes only genuinely novel/ambiguous situations to AI-assisted human review, and automatically reuses approved deterministic rules for matching existing and future cases.

**Architecture:** Keep `SvAmazonSafeTDecisionEngine` as the base business-rule authority, then add a `SvAmazonDecisionCoordinator` that consults learned memory only when the base result is review-gated. Learned memory is structured/versioned and never performs external writes directly; every write still traverses scheduler, outbox, versioned write profile, bridge, idempotency and read-back. The cockpit uses tenant-scoped repositories and a timeline projector; AI is recommendation-only and optional at runtime.

**Tech Stack:** PHP 8.3, MySQL/InnoDB, vanilla HTML/CSS/JavaScript, existing PHP daemon, Node Seller Central bridge, OpenAI Responses API with strict JSON Schema for optional review suggestions.

**Spec:** `docs/superpowers/specs/2026-09-06-safet-review-cockpit-memory-design.md`

## Global Constraints

- Existing business rules and active learned memories execute automatically; they never require redundant human approval.
- Human review is only for novel, incomplete, conflicting or materially different situations unresolved by existing rules.
- AI is advisory only and cannot enqueue or perform a Seller Central, Gmail or Support write.
- Learned rules are tenant + Amazon-connection scoped and versioned; ShopVivaliz rules are not silently shared globally.
- Full-credit reconciliation, authoritative physical receipt, exact Amazon wait dates, appeal deadlines and damaged-return initial-manual-opening rules remain hard gates.
- External writes continue through the existing versioned write profile, kill switch, idempotent outbox and read-back contract.
- Email/review/support writes remain disabled unless independently activated.
- No historical narrative may be fabricated; every new external write persists the exact final text before execution.
- Every database access remains server-bound to the current tenant and Amazon connection.
- Use TDD for every behavior change and run full suite + SQL audit + syntax + shadow replay before activation.

---
## Planned file structure

- `ReviewContext.php` — canonical review facts/signature and extracted variables.
- `ReviewRepository.php` — open/decide review episodes with optimistic locking.
- `LearnedRuleRepository.php` — immutable rule versions, activation/supersession/disable and revision hash.
- `RuleApplicationRepository.php` — idempotent rule-application audit plus later outcome.
- `LearnedRuleEngine.php` — deterministic matching, specificity and conflict detection.
- `DecisionCoordinator.php` — base rules first; learned rule only for review-gated outcomes; review creation only when still unresolved.
- `ReviewAdvisor.php` / `OpenAiReviewAdvisor.php` — advisory AI with strict schema and no execution path.
- `ReviewService.php` — human decision transaction, promotion, preview and propagation.
- `ExternalWritePayload.php` — exact server-side write snapshot before enqueue.
- `CockpitTimeline.php` — 360-degree chronological projection.
- Admin APIs — all cases, case detail, reviews, suggestion/preview/decision, rules and disable.
- `admin/amazon-returns/assets/cockpit.css` / `cockpit.js` — responsive workspace.
- `scripts/replay-learned-rules.php` — read-only shadow replay before activation.
- Existing `Schema.php`, `TenantPersistence.php`, `CaseRepository.php`, `SafeTDecisionEngine.php`, scheduler/daemon, runtime/verifiers and tests change only where their owned responsibility requires it.

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
- `SvAmazonReviewRepository::lock(int $reviewId): array`
- `SvAmazonReviewRepository::decide(int $reviewId,int $expectedVersion,array $decision): array`
- `SvAmazonReviewRepository::saveSuggestion(int $reviewId,int $expectedVersion,array $suggestion,string $model): array`
- `SvAmazonReviewRepository::recordAiFailure(int $reviewId,int $expectedVersion,string $errorClass): array`
- `SvAmazonReviewRepository::find(int $reviewId): ?array`, `forCase(int $caseId): array`, `openQueue(array $filters=[]): array`
- `SvAmazonReviewRepository::countOpen(): int`, `countOpenForCase(int $caseId): int`, `countOpenByReason(string $reason): int`, `countAiFailures(): int`
- `SvAmazonLearnedRuleRepository::active(): array`
- `SvAmazonLearnedRuleRepository::promote(array $definition): array`
- `SvAmazonLearnedRuleRepository::revision(): string`
- `SvAmazonLearnedRuleRepository::list(array $filters=[]): array`, `setStatus(int $ruleId,int $expectedVersion,string $status): array`, `incrementOutcome(int $ruleId,string $outcome,string $applicationKey): void`
- `SvAmazonRuleApplicationRepository::record(array $application): int`, `forCase(int $caseId): array`, `countAll(): int`, `pendingOutcomes(int $limit=500): array`
- `SvAmazonRuleApplicationRepository::recordOutcome(int $applicationId,string $outcome,array $evidenceRefs): void`

- [ ] **Step 1: Write RED schema/repository tests**

```php
$tables=['amazon_return_reviews','amazon_return_learned_rules','amazon_return_rule_applications'];
foreach($tables as $table){
    rmAssert(str_contains($ddl,"CREATE TABLE IF NOT EXISTS `{$table}`"),"missing {$table}");
}
```
Assert each table carries tenant/connection scope and cross-tenant mutation fails.

- [ ] **Step 2: Run targeted tests and confirm RED**

```bash
php tests/review-memory-persistence-test.php
php tests/amazon-returns-tenant-schema-test.php
php tests/amazon-returns-tenant-isolation-test.php
```
Expected: missing tables/repositories and old schema-count assumptions.

- [ ] **Step 3: Add immutable/versioned tables and indexes**

`amazon_return_reviews` stores status/version/reason/context hash+JSON, evidence refs, affected-case preview, AI provider/suggestion/model/error counters, human decision/mode/actor/source version, decided time, resulting rule ID and later outcome JSON/time. While open it carries `open_key=sha256(case_id|context_hash)`; deciding sets `open_key=NULL`, so the same situation can legitimately be reviewed again later after a rule is disabled/superseded while duplicate open episodes remain impossible.
`amazon_return_learned_rules` stores family/version/status, match/effect JSON, source review, specificity, activation/supersession timestamps and outcome counters.
`amazon_return_rule_applications` stores a unique `application_key`, rule/case/signature/effect hashes, result/blockers/action reference, created time and later outcome/evidence references.
Required keys:
```sql
UNIQUE KEY uq_review_open_key (tenant_id,amazon_connection_id,open_key),
UNIQUE KEY uq_learned_rule_version (tenant_id,amazon_connection_id,rule_family_key,version),
UNIQUE KEY uq_rule_application_key (tenant_id,amazon_connection_id,application_key)
```

- [ ] **Step 4: Wire repositories into persistence/migration/audit**

```php
public readonly SvAmazonReviewRepository $reviews;
public readonly SvAmazonLearnedRuleRepository $learnedRules;
public readonly SvAmazonRuleApplicationRepository $ruleApplications;
```
Update all schema table counts and tenant/connection ownership allowlists.

- [ ] **Step 5: Run GREEN tests and commit**

Run targeted tests plus `php scripts/audit-tenant-sql.php`.
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
rcsSame($a['signature_hash'],$b['signature_hash'],'IDs/amounts/literal dates excluded');
$bCase=$caseB;$bCase['program']='DELIVERY_BY_AMAZON';
$c=SvAmazonReviewContext::build($bCase,$timelineB,$policy,$review);
rcsAssert($a['signature_hash']!==$c['signature_hash'],'program is material');
```
- [ ] **Step 2: Run RED test**

Run: `php tests/review-context-signature-test.php`
Expected: missing `SvAmazonReviewContext`.

- [ ] **Step 3: Implement canonical dimensions**

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
Store literal `promised_date`, `appeal_deadline_at`, `outstanding_amount`, order/SAFE-T identifiers only in `variables`/facts, never equality signature.

- [ ] **Step 4: Fail closed on unknown/conflicting facts**

Unknown category values normalize to `UNKNOWN`. Contradictory material evidence sets `material_conflict=true`; no learned rule may auto-resolve that context.

- [ ] **Step 5: Run GREEN tests and commit**

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

**Interfaces:** `SvAmazonLearnedRuleEngine::match(array $context,array $activeRules): array` returning `status=NONE|MATCH|CONFLICT`, `rule`, and `conflicts`.
- [ ] **Step 1: Write RED match/conflict tests**

```php
$r=$engine->match($context,[$broad,$specific]);
lreSame('MATCH',$r['status'],'compatible winner');
lreSame($specific['id'],$r['rule']['id'],'more-specific rule wins');
$r=$engine->match($context,[$specific,$incompatibleSameSpecificity]);
lreSame('CONFLICT',$r['status'],'equal-specificity incompatible effects fail closed');
```

- [ ] **Step 2: Run RED test**

Run: `php tests/learned-rule-engine-test.php`
Expected: missing rule engine.

- [ ] **Step 3: Implement exact predicate matching and safe effects**

`match_json` is an equality map over canonical signature dimensions. Server recalculates `specificity=count(match_json)`; the browser cannot set trusted priority.
Supported phase-1 effect actions are `WAIT`, `CHECK_FINANCES`, `SAFE_T_SUBMIT`, `SAFE_T_APPEAL`, `SAFE_T_EMAIL_REVIEW`, `SAFE_T_EMAIL_REPLY`, `SELLER_SUPPORT_OPEN`, `SELLER_SUPPORT_UPDATE`, `CLOSE_LOSS`. Parameters may bind only to allowlisted ReviewContext variables such as `PROMISED_DATE` or `APPEAL_DEADLINE`; no executable expression/string template is evaluated.

```php
foreach($rule['match'] as $key=>$expected){
    if(!array_key_exists($key,$context['signature']) || $context['signature'][$key]!==$expected) continue 2;
}
$effect=SvAmazonLearnedRuleEngine::normalizeEffect($rule['effect']);
$matches[]=['rule'=>$rule,'effect'=>$effect,'specificity'=>count($rule['match'])];
```

- [ ] **Step 4: Make conflicts deterministic**

Ignore disabled/superseded versions. If highest-specificity matches normalize to incompatible effects, return `CONFLICT`; never resolve by newest/oldest rule.

```php
$top=array_values(array_filter($matches,fn($m)=>$m['specificity']===$max));
$effects=array_unique(array_map(fn($m)=>hash('sha256',json_encode($m['effect'],JSON_THROW_ON_ERROR)),$top));
if(count($effects)>1)return ['status'=>'CONFLICT','rule'=>null,'conflicts'=>array_column($top,'rule')];
```

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
- `SvAmazonDecisionCoordinator::previewAction(array $case,array $timeline,array $policy,?DateTimeImmutable $now=null): array` — pure, no review/application writes.
- `SvAmazonDecisionCoordinator::nextAction(array $case,array $timeline,array $policy,?DateTimeImmutable $now=null): array` — persists review/rule-application audit as appropriate.
- `SvAmazonSafeTDecisionEngine::guardLearnedEffect(array $effect,array $case,array $timeline,array $policy,DateTimeImmutable $now): array`
- `SvAmazonReturnsScheduler::scheduleDecision(SvAmazonTenantReturnsOutbox $target,array $case,array $decision,array $timeline=[]): array`
- [ ] **Step 1: Write RED tests proving known logic bypasses review**

```php
$r=$coordinator->nextAction($eligible,[],$eligiblePolicy,$now);
dcSame('SAFE_T_SUBMIT',$r['action'],'existing deterministic rule runs automatically');
dcSame(0,$reviews->countOpenForCase(77),'known base rule creates no review');
$r=$coordinator->nextAction($ambiguous,$timeline,$policy,$now);
dcSame('WAIT',$r['action'],'matching learned memory resolves automatically');
dcSame(0,$reviews->countOpenForCase(88),'known memory asks no repeat approval');
```
Also prove `previewAction()` returns the same action but inserts no review/application/event, `NONE` opens one idempotent review, and `CONFLICT` opens `LEARNED_RULE_CONFLICT` with zero outbox write.

- [ ] **Step 2: Run RED tests**

```bash
php tests/decision-coordinator-test.php
php tests/known-rule-auto-flow-test.php
```
Expected: coordinator/preview behavior missing.

- [ ] **Step 3: Implement base-first coordination**

```php
$base=$this->base->nextAction($case,$timeline,$policy,$now);
if(!in_array($base['action'],['HUMAN_REVIEW','BLOCKED_REVIEW'],true)) return $base;
$context=SvAmazonReviewContext::build($case,$timeline,$policy,$base);
$match=$this->ruleEngine->match($context,$this->rules->active());
if($match['status']==='MATCH') return $this->applyMatchedRule($match['rule'],$context,$case,$timeline,$policy,$now,$persist);
return $persist ? $this->openReview($case,$base,$context,$match) : $this->previewReview($base,$context,$match);
```
`previewAction()` calls this path with `$persist=false`; `nextAction()` uses `$persist=true`. No AI call occurs in either path.

- [ ] **Step 4: Guard every learned effect with the same non-negotiable invariants**

`guardLearnedEffect()` must fail closed when the learned action would violate current facts. Required checks include:
```php
if($this->hasRecoveredCredit($case)) return $this->decision('WAIT','ALREADY_REIMBURSED',(int)$case['id']);
if($action==='SAFE_T_SUBMIT' && trim((string)($case['safe_t_id']??''))!=='') return $this->decision('WAIT','SAFE_T_ALREADY_EXISTS',(int)$case['id']);
if($action==='SAFE_T_SUBMIT' && $this->sellerAppConfirmedPhysicalReceipt($case,$timeline)) return $this->decision('WAIT','SELLER_APP_PHYSICAL_RECEIPT_CONFIRMED',(int)$case['id']);
if($action==='SAFE_T_SUBMIT' && !$this->amazonCustomerRefundConfirmed($case)) return $this->decision('WAIT','AMAZON_CUSTOMER_REFUND_NOT_CONFIRMED',(int)$case['id']);
if($action==='SAFE_T_SUBMIT' && ($policy['eligible']??false)!==true) return $this->decision('HUMAN_REVIEW','LEARNED_RULE_D45_GATE_BLOCKED',(int)$case['id']);
if($action==='SAFE_T_SUBMIT' && $this->isDamagedOrDiscrepant($case) && trim((string)($case['safe_t_id']??''))==='') return $this->decision('HUMAN_REVIEW','DAMAGED_RETURN_INITIAL_CLAIM_MANUAL_ONLY',(int)$case['id']);
if($action==='WAIT' && !$this->resolvedLearnedWaitDateExists($effect)) return $this->decision('HUMAN_REVIEW','LEARNED_RULE_WAIT_DATE_UNRESOLVED',(int)$case['id']);
if($action==='SAFE_T_APPEAL' && !$this->validOpenAppealWindow($case,$now)) return $this->decision('HUMAN_REVIEW','LEARNED_RULE_APPEAL_GATE_BLOCKED',(int)$case['id']);
if($action==='SAFE_T_APPEAL' && ($case['state']??'')==='APPEAL_SUBMITTED') return $this->decision('WAIT','APPEAL_ALREADY_SUBMITTED',(int)$case['id']);
```
Do not duplicate ad-hoc gate logic in the coordinator: extract/reuse helpers in `SafeTDecisionEngine` so base and learned paths use identical checks. Add regression coverage proving refund-based D+45 remains authoritative and a historical denial cannot rewind an already submitted appeal.

- [ ] **Step 5: Preserve learned-rule audit without duplicate events**

On a match, record `amazon_return_rule_applications` and append a `LEARNED_RULE_APPLIED` event keyed by `rule_id + rule_version + case_id + signature_hash + effect_hash`. The event contains IDs/hashes and resolved action, not secrets or unrelated message bodies.

```php
$key=hash('sha256',implode('|',[$rule['id'],$rule['version'],$case['id'],$context['signature_hash'],$effectHash]));
$applicationId=$this->applications->record(['application_key'=>$key,'case_id'=>(int)$case['id'],'rule_id'=>(int)$rule['id'],'signature_hash'=>$context['signature_hash'],'result'=>'MATCHED','action'=>$decision['action']]);
$this->events->append(['case_id'=>(int)$case['id'],'event_type'=>'LEARNED_RULE_APPLIED','source'=>'INTERNAL','idempotency_key'=>$key,'occurred_at'=>gmdate('Y-m-d H:i:s'),'payload'=>['rule_id'=>$rule['id'],'application_id'=>$applicationId,'action'=>$decision['action']]]);
```

- [ ] **Step 6: Modify daemon/scheduler to use the precomputed coordinated decision**

The daemon constructs one coordinator per scheduler run. Replace direct `$engine->nextAction()` with `$coordinator->nextAction()`. When write eligible, call `scheduleDecision()` so the scheduler does not recompute and discard a learned result. Keep `Config::externalWriteAllowed()` and readiness checks exactly where they currently gate enqueueing.

```php
$coordinator=new SvAmazonDecisionCoordinator($engine,$this->persistence,$this->config);
$decision=$coordinator->nextAction($projected,$timeline,$policy,$now);
if(SvAmazonReturnsScheduler::isWriteAction($decision) && $this->config->externalWriteAllowed((string)$decision['action'])){
    $scheduled=(new SvAmazonReturnsScheduler($engine))->scheduleDecision($this->persistence->outbox,$projected,$decision,$timeline);
}
```

- [ ] **Step 7: Run GREEN/regression tests and commit**

Run:
```bash
php tests/decision-coordinator-test.php
php tests/known-rule-auto-flow-test.php
php tests/amazon-returns-safet-decision-test.php
php tests/d45-production-regression-test.php
php tests/return-routing-integration-test.php
php tests/write-profile-test.php
```
Commit: `feat: reuse approved SAFE-T decisions automatically`

---

### Task 5: Build transactional human review promotion and affected-case re-evaluation
**Files:**
- Create: `includes/amazon-returns/ReviewService.php`
- Modify: `workers/amazon-returns/daemon.php`
- Test: `tests/review-service-test.php`
- Test: `tests/review-propagation-test.php`

**Interfaces:**
- `SvAmazonReviewService::preview(int $reviewId,array $humanDecision): array`
- `SvAmazonReviewService::submit(int $reviewId,int $expectedVersion,array $humanDecision,string $actor): array`
- Return submit counts: `re_evaluated`, `changed`, `unchanged`, `blocked`, `queued`, plus `rule_id` or `null` for exception-only.

- [ ] **Step 1: Write RED tests for optimistic locking, exception-only and propagation**

```php
$preview=$service->preview($reviewId,$decision);
rpsSame([31,44],array_column($preview['matching_cases'],'id'),'preview exact existing matches');
$result=$service->submit($reviewId,1,$decision,'Fred');
rpsSame(2,$result['re_evaluated'],'all existing matches re-evaluated');
rpsAssert($result['rule_id']>0,'reusable human decision promotes rule');
rpsThrows(fn()=> $service->submit($reviewId,1,$decision,'Fred'),'stale review version rejected');
```
For `decision_mode=EXCEPTION`, assert no rule row and only the originating case is resolved.

- [ ] **Step 2: Run RED tests**

Run:
```bash
php tests/review-service-test.php
php tests/review-propagation-test.php
```
Expected: missing service/promotion behavior.

- [ ] **Step 3: Implement one transaction for human decision + rule version activation**

Lock the review row, verify `expectedVersion`, save the final human decision, and for reusable decisions create the next immutable rule version and supersede the previous active version in the same DB transaction. The AI proposal is never copied over the human-final payload.

```php
$db->beginTransaction();
try{
    $review=$this->reviews->lock($reviewId);
    if((int)$review['version']!==$expectedVersion)throw new RuntimeException('STALE_REVIEW_VERSION');
    $saved=$this->reviews->decide($reviewId,$expectedVersion,$humanDecision);
    $rule=$humanDecision['decision_mode']==='EXCEPTION'?null:$this->rules->promote($this->ruleDefinition($saved,$humanDecision));
    $db->commit();
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
```
- [ ] **Step 4: Re-evaluate matching cases through the coordinator, never by bulk patch**

After commit, load each matching case independently, rebuild policy/timeline, call `DecisionCoordinator::nextAction()`, and record the per-case result. Wrap each case in its own `try/catch`; one re-evaluation failure is returned as that case's failure and cannot roll back or skip unrelated matches. For a write action, enqueue only through `SvAmazonReturnsScheduler::scheduleDecision()` with the case timeline after the same `externalWriteAllowed()` and dependency-readiness checks used by the daemon. Idempotency keys make retrying the HTTP request harmless.

- [ ] **Step 5: Make rule revision wake the daemon**

Add `SvAmazonLearnedRuleRepository::revision(): string` and store `learned_rule_revision` in daemon state. If it changes, force `scheduler` due exactly as policy/write-profile revision changes already do.

```php
$ruleRevision=$this->persistence->learnedRules->revision();
if(($state['learned_rule_revision']??null)!==$ruleRevision){
    $due=array_values(array_unique([...$due,'scheduler']));
    $state['learned_rule_revision']=$ruleRevision;
}
```

- [ ] **Step 6: Run GREEN tests and commit**

Run:
```bash
php tests/review-service-test.php
php tests/review-propagation-test.php
php tests/known-rule-auto-flow-test.php
php tests/amazon-returns-reliability-test.php
```
Commit: `feat: promote human SAFE-T reviews into reusable rules`

---

### Task 6: Add optional AI review suggestions with strict structured output

**Files:**
- Create: `includes/amazon-returns/ReviewAdvisor.php`
- Create: `includes/amazon-returns/OpenAiReviewAdvisor.php`
- Modify: `includes/amazon-returns/Config.php`
- Test: `tests/review-advisor-test.php`
- Test: `tests/openai-review-advisor-test.php`

**Interfaces:**
- `interface SvAmazonReviewAdvisor { public function suggest(array $reviewContext): array; }`
- `SvAmazonOpenAiReviewAdvisor::__construct(SvAmazonReturnsConfig $config,?callable $transport=null)`; injected transport is test-only and production uses cURL.
- `SvAmazonReturnsConfig::openAiKey(): string`, `reviewAiModel(): string`, `reviewAiReady(): bool`; the key is never returned by health/UI.
- `SvAmazonOpenAiReviewAdvisor` reads `OPENAI_API_KEY` server-side and `AMAZON_RETURNS_REVIEW_AI_MODEL`, default `gpt-5.6-terra`.

- [ ] **Step 1: Write RED contract tests using a fake HTTP transport**
```php
$fake=function(array $request): array {
    raSame(false,$request['store'],'review suggestions must not use API response storage');
    raSame('json_schema',$request['text']['format']['type'],'strict structured output required');
    return fakeResponsesPayload(['action'=>'WAIT','rationale'=>'Amazon gave an explicit date','confidence'=>0.94,'uncertainties'=>[],'parameters'=>['date_binding'=>'PROMISED_DATE']]);
};
$s=$advisor->suggest($context);
raSame('WAIT',$s['action'],'supported recommendation');
```
Also assert malformed JSON, unsupported actions, missing required fields, HTTP failure and missing API key fail to a controlled exception without changing case/review state.

- [ ] **Step 2: Run RED tests**

Run:
```bash
php tests/review-advisor-test.php
php tests/openai-review-advisor-test.php
```
Expected: advisor classes/config absent.

- [ ] **Step 3: Implement strict recommendation schema**

Allowed `action` values: `WAIT`, `CHECK_FINANCES`, `SAFE_T_SUBMIT`, `SAFE_T_APPEAL`, `SAFE_T_EMAIL_REVIEW`, `SAFE_T_EMAIL_REPLY`, `SELLER_SUPPORT_OPEN`, `SELLER_SUPPORT_UPDATE`, `CLOSE_LOSS`.
`parameters.date_binding` is one of `NONE`, `PROMISED_DATE`, `APPEAL_DEADLINE`; AI never invents a literal date. Required fields are `action`, `rationale`, `confidence`, `uncertainties`, `parameters`; reject additional executable fields.

```php
$schema=['type'=>'object','additionalProperties'=>false,'required'=>['action','rationale','confidence','uncertainties','parameters'],'properties'=>[
 'action'=>['type'=>'string','enum'=>self::ACTIONS],
 'rationale'=>['type'=>'string','minLength'=>1,'maxLength'=>2000],
 'confidence'=>['type'=>'number','minimum'=>0,'maximum'=>1],
 'uncertainties'=>['type'=>'array','items'=>['type'=>'string','maxLength'=>500],'maxItems'=>10],
 'parameters'=>['type'=>'object','additionalProperties'=>false,'required'=>['date_binding'],'properties'=>['date_binding'=>['type'=>'string','enum'=>['NONE','PROMISED_DATE','APPEAL_DEADLINE']]]],
]];
```

- [ ] **Step 4: Implement OpenAI Responses API adapter**

POST server-side to `/v1/responses` with `Authorization: Bearer`, `store:false`, configured model and `text.format` containing `type=json_schema`, `name=safe_t_review_suggestion`, `strict=true`, and the exact schema from Step 3. Parse only `output[].content[]` entries of type `output_text`, JSON-decode, then validate again locally.

```php
$request=['model'=>$this->config->reviewAiModel(),'store'=>false,'input'=>$this->input($reviewContext),'text'=>['format'=>['type'=>'json_schema','name'=>'safe_t_review_suggestion','strict'=>true,'schema'=>self::schema()]]];
$response=$this->transport->post('https://api.openai.com/v1/responses',['Authorization: Bearer '.$this->config->openAiKey()],$request);
$suggestion=$this->decodeOutputText($response);
return $this->validateSuggestion($suggestion);
```

The input contains canonical facts, unresolved facts and bounded evidence excerpts only. Do not send bridge tokens, cookies, OAuth credentials, full unrelated emails or arbitrary case columns.

- [ ] **Step 5: Run GREEN tests and commit**

Run the two advisor tests plus `php tests/runtime-case-audit-test.php`.
Commit: `feat: add advisory AI for SAFE-T reviews`

---
### Task 7: Persist the exact outbound narrative before every new write

**Files:**
- Create: `includes/amazon-returns/ExternalWritePayload.php`
- Modify: `workers/amazon-returns/scheduler.php`
- Modify: `workers/amazon-returns/daemon.php`
- Modify: `scripts/amazon-returns/seller-central-bridge-worker.mjs`
- Modify: `includes/amazon-returns/BridgeService.php`
- Test: `tests/external-write-snapshot-test.php`
- Test: `tests/amazon-returns-remote-bridge-test.php`
- Test: `tests/gmail-thread-reply-test.php`

**Interfaces:**
- `SvAmazonExternalWritePayload::build(array $decision,array $case,array $timeline): array`
- New outbox payload field `write_snapshot` contains `format_version`, `channel`, `narrative` or `message`, and `content_sha256`.

- [ ] **Step 1: Write RED tests proving text is persisted before enqueue**

```php
$payload=SvAmazonExternalWritePayload::build($appealDecision,$case,$timeline);
ewsAssert(trim($payload['write_snapshot']['narrative'])!=='','appeal narrative is materialized server-side');
ewsSame(hash('sha256',$payload['write_snapshot']['narrative']),$payload['write_snapshot']['content_sha256'],'snapshot hash');
```
Schedule a test row and assert `payload_json.write_snapshot.narrative` equals the text later handed to the bridge.

- [ ] **Step 2: Run RED tests**

Run:
```bash
php tests/external-write-snapshot-test.php
php tests/amazon-returns-remote-bridge-test.php
```
Expected: new snapshot class/field missing.

- [ ] **Step 3: Move new-write composition to the server-side snapshot builder**

For `SAFE_T_SUBMIT`, `SAFE_T_APPEAL`, Support and Gmail actions, build the exact text/message once before enqueue. Reuse current wording rules; do not silently change business argumentation while relocating composition.

```php
$snapshot=match($decision['action']){
 'SAFE_T_SUBMIT','SAFE_T_APPEAL'=>self::sellerCentralNarrative($decision,$case,$timeline),
 'SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY'=>self::gmailMessage($decision,$case,$timeline),
 'SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'=>self::supportNarrative($decision,$case,$timeline),
 default=>throw new InvalidArgumentException('Unsupported write snapshot action'),
};
return ['format_version'=>2,'channel'=>$channel]+$snapshot+['content_sha256'=>hash('sha256',$canonicalText)];
```
- [ ] **Step 4: Make executors consume the stored snapshot**

Seller Central bridge uses `job.payload.write_snapshot.narrative` for version-2 jobs. Gmail execution uses the stored `message` body/subject/thread fields. Preserve the existing composition fallback only for pre-deployment legacy outbox rows; every newly scheduled row must carry `format_version=2`.

```js
const snapshot=job.payload?.write_snapshot;
const narrative=snapshot?.format_version===2 ? text(snapshot.narrative) : narrativeForLegacy(job,max);
if(snapshot?.format_version===2 && !narrative) return bridgeResult('FAILED',{reason:'WRITE_SNAPSHOT_MISSING',retry_safe:false});
```

- [ ] **Step 5: Link results to the snapshot hash**

`SELLER_CENTRAL_ACTION_RESULT` and email sent events include `outbox_id` and `write_content_sha256`. Do not duplicate the full narrative into result events because the immutable outbox payload is the source of truth.

- [ ] **Step 6: Run GREEN tests and commit**

Run:
```bash
php tests/external-write-snapshot-test.php
php tests/amazon-returns-remote-bridge-test.php
php tests/gmail-thread-reply-test.php
node --check scripts/amazon-returns/seller-central-bridge-worker.mjs
```
Commit: `feat: persist exact outbound SAFE-T write snapshots`

---

### Task 8: Project the 360-degree case timeline and write history

**Files:**
- Create: `includes/amazon-returns/CockpitTimeline.php`
- Modify: `includes/amazon-returns/TenantOutbox.php`
- Modify: `includes/amazon-returns/EvidenceStore.php`
- Test: `tests/cockpit-timeline-test.php`

**Interfaces:**
- `SvAmazonTenantReturnsOutbox::historyForCase(int $caseId): array`
- `SvAmazonCockpitTimeline::project(array $case,array $events,array $evidence,array $outbox,array $reviews,array $ruleApplications): array`

- [ ] **Step 1: Write RED timeline-ordering and narrative tests**

```php
$t=SvAmazonCockpitTimeline::project($case,$events,$evidence,$outbox,$reviews,$apps);
ctSame(['OBSERVATION','EXTERNAL_WRITE','AMAZON_RESPONSE'],array_column(array_slice($t,0,3),'category'),'chronological categories');
ctSame($appealText,$writeItem['content']['narrative'],'exact persisted appeal shown');
```
Also test that an old successful outbox/event with no stored narrative produces `narrative_status=MISSING_HISTORICAL_SNAPSHOT` rather than reconstructed text, and that secrets/private arbitrary fields are not projected.

- [ ] **Step 2: Run RED test**

Run: `php tests/cockpit-timeline-test.php`
Expected: missing projector/history queries.

- [ ] **Step 3: Add tenant-scoped history queries**

`historyForCase()` returns all statuses, including `SUCCEEDED` and `DEAD_LETTER`, with decoded safe payload. Evidence projection returns metadata/storage reference only through the existing secret allowlist and owned-case check.

```php
$stmt=$this->prepare('SELECT * FROM amazon_return_outbox WHERE tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id AND case_id=:case_id ORDER BY created_at,id');
$stmt->execute($this->scopeParams([':case_id'=>$caseId]));
return array_map(fn($row)=>$this->decodeHistoryRow($row),$stmt->fetchAll(PDO::FETCH_ASSOC));
```

- [ ] **Step 4: Implement a stable timeline item contract**

Each item contains:
```php
[
 'id'=>'event:123', 'occurred_at'=>'2026-09-05 18:13:24',
 'category'=>'OBSERVATION|DECISION|EXTERNAL_WRITE|AMAZON_RESPONSE|FINANCIAL|RULE|ERROR',
 'title'=>'Recurso SAFE-T enviado', 'source'=>'SELLER_CENTRAL', 'status'=>'ACCEPTED',
 'content'=>[], 'evidence_refs'=>[], 'rule_ref'=>null,
]
```
Sort by `occurred_at`, then deterministic source ID. A `write_snapshot` is rendered exactly; missing legacy text is labeled missing, never regenerated.

- [ ] **Step 5: Run GREEN tests and commit**

Run:
```bash
php tests/cockpit-timeline-test.php
php tests/runtime-case-audit-test.php
php tests/amazon-returns-tenant-outbox-test.php
```
Commit: `feat: add 360 degree SAFE-T timeline projection`

---

### Task 9: Add read-only cockpit APIs for all cases, SAFE-T detail and review queue

**Files:**
- Create: `admin/amazon-returns/api/cases.php`
- Create: `admin/amazon-returns/api/reviews.php`
- Create: `admin/amazon-returns/api/review.php`
- Modify: `admin/amazon-returns/api/case.php`
- Modify: `includes/amazon-returns/CaseRepository.php`
- Create: `includes/amazon-returns/CockpitFilters.php`
- Test: `tests/cockpit-api-contract-test.php`
- Test: `tests/amazon-returns-admin-test.php`
**Interfaces:**
- `SvAmazonCockpitFilters::fromQuery(array $query): self` with `sqlFilters()`, `requiresDecisionFilter()`, `action()`, `perPage()` and `page()` accessors.
- `SvAmazonReturnCaseRepository::search(array $filters,int $page=1,int $perPage=50): array`
- `cases.php` returns `{items,page,per_page,total,filters}`; each item includes order/item/SKU/ASIN/program, SAFE-T/support IDs, state/physical status, refund/eligibility/next-action/appeal dates, expected/reconciled/outstanding amounts, current action/reason, review status, applied-rule reference, last external write/read-back and updated time.
- Expanded `case.php?case_id=N` returns `{case,timeline,current_review,rule_applications}`.

- [ ] **Step 1: Write RED API/repository contract tests**

```php
$result=$repo->search(['safe_t_id'=>'98143-99485-9285859','review_status'=>'OPEN'],1,50);
caSame(1,$result['total'],'filters are tenant scoped');
caAssert(isset($detail['timeline']),'case detail exposes 360 timeline');
```
Source-contract tests must also assert every API requires `AdminAuth.php`, resolves `TenantRegistry`, uses `TenantPersistence`, and never trusts a request `tenant_id`.

- [ ] **Step 2: Run RED tests**

Run:
```bash
php tests/cockpit-api-contract-test.php
php tests/amazon-returns-admin-test.php
```
Expected: missing endpoints/search contract.

- [ ] **Step 3: Implement bounded filter parsing and pagination**

Supported filters: `q` (order/SAFE-T/SKU), `state`, `action`, `review_status`, `program`, `physical_status`, `deadline=overdue|today|7d`, `learned_rule=applied|none|conflict`, `min_outstanding`, `max_outstanding`. `per_page` is clamped to 25-100. Values are bound parameters; sort keys are server allowlisted.

Default ordering: unresolved/overdue review first, then nearest appeal/next-action deadline, then latest activity. Closed historical cases remain searchable. `cases.php` enriches returned rows with `DecisionCoordinator::previewAction()` so the displayed current action/rule never creates a review or rule-application side effect. When an `action` filter is present, evaluate the bounded tenant result set first, filter by the pure preview result, then paginate; do not mutate state merely to support the list.

```php
$filters=SvAmazonCockpitFilters::fromQuery($_GET);
$rows=$p->cases->search($filters->sqlFilters(),1,$filters->requiresDecisionFilter()?1000:$filters->perPage());
$items=array_map(fn($case)=>$this->enrichWithPreview($case,$coordinator,$p,$now),$rows['items']);
if($filters->action()!==null)$items=array_values(array_filter($items,fn($row)=>$row['current_action']===$filters->action()));
```

- [ ] **Step 4: Expand case detail using `SvAmazonCockpitTimeline`**

Return exact persisted write snapshots, read-backs, financial events, review decisions and learned-rule references. Never return credential-bearing metadata or raw bridge/browser tokens.

- [ ] **Step 5: Run GREEN tests and commit**

Run the two API tests plus `php scripts/audit-tenant-sql.php`.
Commit: `feat: add read only SAFE-T cockpit APIs`

---

### Task 10: Build the responsive read-only cockpit workspace
**Files:**
- Create: `admin/amazon-returns/assets/cockpit.css`
- Create: `admin/amazon-returns/assets/cockpit.js`
- Modify: `admin/amazon-returns/index.php`
- Test: `tests/cockpit-ui-contract-test.php`

- [ ] **Step 1: Write RED source/UI contract tests**

Assert the page has authenticated navigation for `Casos` and `Revisões`, search/filter controls with labels, a case-detail region, mobile viewport, and external JS/CSS assets. Assert JS uses `textContent`/DOM creation rather than inserting API text with `innerHTML`.

```php
$js=(string)file_get_contents(__DIR__.'/../admin/amazon-returns/assets/cockpit.js');
cuAssert(!str_contains($js,'innerHTML'),'API text must not be injected as HTML');
cuAssert(str_contains($js,'openCase'),'case detail interaction required');
cuAssert(str_contains($js,'loadReviews'),'review queue interaction required');
```

- [ ] **Step 2: Run RED test**

Run: `php tests/cockpit-ui-contract-test.php`
Expected: assets and interactions absent.

- [ ] **Step 3: Replace the recent-only table with the cockpit shell**

Keep existing money/health cards, then add tab buttons for `Casos` and `Revisões`, responsive filters, result count/pagination, and a detail panel. On <=600px, render case rows as stacked cards with 44px minimum action targets; avoid page-level horizontal scrolling.

```html
<nav class="cockpit-tabs" aria-label="Painel SAFE-T">
  <button type="button" data-view="cases" aria-selected="true">Casos</button>
  <button type="button" data-view="reviews">Revisões</button>
</nav>
<section id="case-list" aria-live="polite"></section>
<aside id="case-detail" aria-label="Detalhes do caso"></aside>
```

- [ ] **Step 4: Implement safe client rendering and state**

`cockpit.js` owns `loadCases(filters,page)`, `loadReviews()`, `openCase(caseId)`, `renderTimeline(items)`, and URL query state. All server content is inserted with `textContent`; dates are formatted in `pt-BR`; monetary values use BRL. Failed requests show a visible retry action without discarding current selection.

```js
function text(tag,value,className=''){const el=document.createElement(tag);el.textContent=value??'—';if(className)el.className=className;return el;}
async function openCase(id){const r=await fetch(`/admin/amazon-returns/api/case.php?case_id=${encodeURIComponent(id)}`,{credentials:'same-origin',cache:'no-store'});const j=await r.json();if(!r.ok)throw new Error(j.error||'Falha ao carregar caso');renderCaseDetail(j);}
```

- [ ] **Step 5: Run GREEN test and HTTP smoke**

Run:
```bash
php tests/cockpit-ui-contract-test.php
php tests/amazon-returns-admin-test.php
curl -fsS -o /dev/null https://returns.shopvivaliz.com.br/login.php
```
The authenticated functional smoke is performed after deployment with the existing admin session/test harness, not by weakening auth.

- [ ] **Step 6: Commit**

Commit: `feat: add read only SAFE-T cockpit workspace`

---

### Task 11: Add AI-assisted review actions, impact preview and approval UI
**Files:**
- Create: `admin/amazon-returns/api/review-suggest.php`
- Create: `admin/amazon-returns/api/review-preview.php`
- Create: `admin/amazon-returns/api/review-decision.php`
- Modify: `admin/amazon-returns/index.php`
- Modify: `admin/amazon-returns/assets/cockpit.js`
- Modify: `admin/amazon-returns/assets/cockpit.css`
- Test: `tests/review-admin-api-test.php`
- Test: `tests/review-ui-contract-test.php`

- [ ] **Step 1: Write RED API security/behavior tests**

```php
$decisionApi=source('admin/amazon-returns/api/review-decision.php');
ruAssert(str_contains($decisionApi,'SvAmazonReturnsCsrf::valid'),'decision requires CSRF');
ruAssert(str_contains($decisionApi,'expected_version'),'optimistic concurrency required');
ruAssert(!str_contains($decisionApi,'outbox->claimBatch'),'HTTP handler cannot execute external work');
```
Test service-level inputs so a reusable decision with a literal custom date is rejected; the operator must bind to an extracted variable or choose `EXCEPTION`.

- [ ] **Step 2: Run RED tests**

Run:
```bash
php tests/review-admin-api-test.php
php tests/review-ui-contract-test.php
```
Expected: endpoints/UI actions missing.

- [ ] **Step 3: Implement suggestion endpoint**

POST `{review_id,expected_version,csrf_token}`. Load the owned open review; if it is already resolved by an active rule, return `409 RULE_ALREADY_RESOLVED` and let the UI refresh instead of asking AI. Otherwise call `SvAmazonOpenAiReviewAdvisor`, persist the structured suggestion and model metadata, increment review version, and return the new version. On AI failure record bounded failure telemetry (`ai_error_count`, class, timestamp; never prompt/token text), return `suggestion_available=false`, and keep human controls enabled.

```php
if(!SvAmazonReturnsCsrf::valid('review-ai',$input['csrf_token']??null))reply(['success'=>false,'error'=>'CSRF'],403);
try{$suggestion=$advisor->suggest($review['context']);$saved=$p->reviews->saveSuggestion($reviewId,$expectedVersion,$suggestion,$advisor->model());}
catch(Throwable $e){$p->reviews->recordAiFailure($reviewId,$expectedVersion,$e::class);reply(['success'=>true,'suggestion_available'=>false]);}
```

- [ ] **Step 4: Implement deterministic impact preview**

POST the proposed final decision to `review-preview.php`. Server validates the final action/parameters, builds the rule definition exactly as `ReviewService` would, and returns the signature plus matching existing cases with order, SAFE-T, current state, outstanding amount and the guarded projected result. No DB mutation and no outbox enqueue occurs.

```php
$preview=$service->preview((int)$input['review_id'],$input['decision']);
reply(['success'=>true,'signature'=>$preview['signature'],'matching_cases'=>$preview['matching_cases'],'future_behavior'=>'AUTOMATIC_WHEN_MATCHED']);
```
- [ ] **Step 5: Implement the decision endpoint**

Accepted decision modes: `APPROVED`, `EDITED_APPROVED`, `REJECTED`, `WAIT`, `EXCEPTION`. Every mode stores a concrete `final_action`; `REJECTED` means the AI suggestion was rejected and the human-selected alternate action is authoritative. `EXCEPTION` never promotes a reusable rule.

The endpoint calls only `ReviewService::submit()`. It may enqueue an idempotent outbox row through that service/scheduler, but it never calls Seller Central/Gmail/Support executors or bridge pull/ACK methods.

```php
$result=$service->submit((int)$input['review_id'],(int)$input['expected_version'],$input['decision'],SvAmazonReturnsAdminAuth::username());
reply(['success'=>true,'result'=>$result]);
```

- [ ] **Step 6: Add review controls to the cockpit**

Show review reason, facts, unresolved facts, evidence excerpts, deadlines, financial posture, AI action/rationale/confidence/uncertainties, and editable final action. Buttons: `Aprovar sugestão`, `Alterar e aprovar`, `Escolher outra ação`, `Aguardar`, `Somente este caso`.

Before final confirmation, call preview and show: signature dimensions, number/list of matching existing cases, each guarded projected result, and a clear statement that matching future cases will follow automatically without new approval. Require a second explicit confirm after preview for reusable decisions.

```html
<div id="review-impact" role="region" aria-label="Impacto da regra"></div>
<button type="button" id="review-preview">Ver impacto</button>
<button type="button" id="review-confirm" disabled>Confirmar decisão e memória</button>
```

- [ ] **Step 7: Make stale tabs and double taps safe**

Disable the submit button while in flight, send `expected_version`, and on HTTP 409 refresh the review instead of retrying a stale mutation. `ReviewService` and outbox idempotency remain authoritative even if the browser repeats a request.

```js
confirmBtn.disabled=true;
try{const r=await postJson('/admin/amazon-returns/api/review-decision.php',payload);if(r.status===409){await openReview(payload.review_id);return;}await loadReviews();}
finally{confirmBtn.disabled=false;}
```

- [ ] **Step 8: Run GREEN tests and commit**

Run:
```bash
php tests/review-admin-api-test.php
php tests/review-ui-contract-test.php
php tests/review-service-test.php
php tests/review-propagation-test.php
```
Commit: `feat: add AI assisted SAFE-T review approval workflow`

---

### Task 12: Add learned-memory management to the cockpit

**Files:**
- Create: `admin/amazon-returns/api/rules.php`
- Create: `admin/amazon-returns/api/rule-status.php`
- Modify: `admin/amazon-returns/index.php`
- Modify: `admin/amazon-returns/assets/cockpit.js`
- Modify: `admin/amazon-returns/assets/cockpit.css`
- Create: `includes/amazon-returns/LearnedRuleOutcome.php`
- Modify: `includes/amazon-returns/RuleApplicationRepository.php`
- Modify: `includes/amazon-returns/LearnedRuleRepository.php`
- Modify: `includes/amazon-returns/ReviewRepository.php`
- Modify: `workers/amazon-returns/daemon.php`
- Test: `tests/learned-rule-admin-test.php`
- Test: `tests/learned-rule-outcome-test.php`
- [ ] **Step 1: Write RED tests for rule visibility, safe disabling and outcomes**

```php
$api=source('admin/amazon-returns/api/rule-status.php');
lraAssert(str_contains($api,'SvAmazonReturnsCsrf::valid'),'rule mutation requires CSRF');
lraAssert(str_contains($api,'expected_version'),'stale rule mutation rejected');
```
Repository tests assert disabling an active rule changes the rule revision, preserves all old applications, and does not delete/supersede unrelated families.

- [ ] **Step 2: Run RED test**

Run: `php tests/learned-rule-admin-test.php`
Expected: endpoints and memory tab absent.

- [ ] **Step 3: Implement read API and memory view**

`rules.php` returns active/superseded/disabled versions with family key, version, source review/case, match conditions, effect, specificity, created/activated/superseded dates, application counts and latest outcomes. Add a `Memória` cockpit tab with expandable rule details and a link back to the originating review/case timeline.

```php
$rows=$p->learnedRules->list(['status'=>$_GET['status']??null]);
reply(['success'=>true,'rules'=>$rows]);
```

- [ ] **Step 4: Implement disable-only mutation**

`rule-status.php` accepts only `status=DISABLED` for an active version plus CSRF and `expected_version`. Re-enabling an old version is intentionally unsupported; a changed decision must create a new reviewed version so history remains monotonic.

```php
if(($input['status']??'')!=='DISABLED')reply(['success'=>false,'error'=>'Unsupported rule transition'],422);
$rule=$p->learnedRules->setStatus((int)$input['rule_id'],(int)$input['expected_version'],'DISABLED');
```

Disabling changes `LearnedRuleRepository::revision()`, so the daemon immediately re-evaluates affected cases. Cases no longer covered by any known rule may enter review; no automatic replacement rule is guessed.

- [ ] **Step 5: Record later outcomes without mutating rule logic**

`SvAmazonLearnedRuleOutcome::classify()` maps later case/timeline facts to `PENDING`, `APPROVED_PENDING_CREDIT`, `RECOVERED`, `DENIED`, or `CLOSED_LOSS`. The daemon refreshes applications lacking a terminal outcome, updates the application once per outcome transition, increments rule counters idempotently, and records the originating review outcome when the source case becomes known. Outcomes are evidence for future human rule refinement only; they never auto-edit an active rule in this phase.

```php
$outcome=SvAmazonLearnedRuleOutcome::classify($case,$timeline);
if($outcome!==$application['outcome']){
    $p->ruleApplications->recordOutcome((int)$application['id'],$outcome,$evidenceRefs);
    $p->learnedRules->incrementOutcome((int)$application['rule_id'],$outcome,(string)$application['application_key']);
}
```

- [ ] **Step 6: Run GREEN tests and commit**

Run:
```bash
php tests/learned-rule-admin-test.php
php tests/learned-rule-outcome-test.php
php tests/learned-rule-engine-test.php
php tests/known-rule-auto-flow-test.php
```
Commit: `feat: add learned SAFE-T memory management`

---

### Task 13: Add shadow replay, operational health and guarded activation

**Files:**
- Create: `scripts/replay-learned-rules.php`
- Modify: `includes/amazon-returns/Config.php`
- Modify: `includes/amazon-returns/Runtime.php`
- Modify: `api/health.php`
- Modify: `scripts/provision-production.sh`
- Modify: `scripts/verify-live-tenant-foundation.sh`
- Modify: `docs/runbooks/tenant-foundation-migration.md`
- Test: `tests/learned-rule-rollout-test.php`
- Test: `tests/production-provision-test.php`
- Modify: `includes/amazon-returns/DecisionCoordinator.php`

- [ ] **Step 1: Write RED rollout tests**

Assert `AMAZON_RETURNS_LEARNED_RULE_EXECUTION` defaults false, production provisioning installs it as `0`, replay never mutates cases/outbox/rules, and health exposes `pending_reviews`, `rule_conflicts`, `rule_applications`, `ai_suggestion_failures`, `review_ai_ready`, `pending_outbox`, `dead_letters`, and `learned_rule_execution_enabled`.

```php
$cfg=new SvAmazonReturnsConfig([]);
lrrSame(false,$cfg->learnedRuleExecutionEnabled(),'learned execution starts fail closed');
```

- [ ] **Step 2: Run RED tests**

Run:
```bash
php tests/learned-rule-rollout-test.php
php tests/production-provision-test.php
```
Expected: flag/replay/health fields missing.

- [ ] **Step 3: Add guarded execution flag to coordinator**

When the flag is false, `DecisionCoordinator` still builds context and evaluates active rules for shadow telemetry, but returns the original review-gated base decision and does not emit `LEARNED_RULE_APPLIED` or enqueue a learned action. When true, a guarded `MATCH` resolves automatically. Existing deterministic base-engine rules are never disabled by this flag and continue automatically in both modes.

```php
if($match['status']==='MATCH' && !$this->config->learnedRuleExecutionEnabled()) return $base+['learned_rule_shadow_match'=>$match['rule']['id']];
if($match['status']==='MATCH') return $this->applyMatchedRule($match['rule'],$context,$case,$timeline,$policy,$now,true);
```

- [ ] **Step 4: Implement read-only replay script**

Usage:
```bash
php scripts/replay-learned-rules.php --all-open --json=/tmp/learned-rule-replay.json
```
Output includes total cases, base auto decisions, review-gated cases, learned matches, conflicts, projected actions, hard-gate blocks, `hard_gate_bypass_count`, and per-case IDs/reasons. The script must not call case update, event append, outbox enqueue, rule promote or review decide methods.

- [ ] **Step 5: Extend health and live verification**

Health/verifier report the new review/rule counts plus execution flag while retaining exact write-profile flags. Auto-deploy/live verification must fail if schema ownership is broken, processing jobs are unsafe, or expected write-profile invariants regress. Provisioning must preserve an existing `OPENAI_API_KEY` if supplied, install `AMAZON_RETURNS_REVIEW_AI_MODEL=gpt-5.6-terra`, and install `AMAZON_RETURNS_LEARNED_RULE_EXECUTION=0` by default.

```php
$health['review_memory']=['pending_reviews'=>$p->reviews->countOpen(),'rule_conflicts'=>$p->reviews->countOpenByReason('LEARNED_RULE_CONFLICT'),'rule_applications'=>$p->ruleApplications->countAll(),'ai_suggestion_failures'=>$p->reviews->countAiFailures(),'review_ai_ready'=>$config->reviewAiReady(),'learned_rule_execution_enabled'=>$config->learnedRuleExecutionEnabled()];
```

```bash
# provision-production.sh
ensure_env_key 'AMAZON_RETURNS_REVIEW_AI_MODEL' 'gpt-5.6-terra'
ensure_env_key 'AMAZON_RETURNS_LEARNED_RULE_EXECUTION' '0'
```
- [ ] **Step 6: Define the activation gate**

Before changing production flag from `0` to `1`, require a fresh replay with `hard_gate_bypass_count=0`, no malformed rule effects, no tenant-scope violations, full test/CI green, and current `pending_outbox=0`/`dead_letters=0`. Conflicts are allowed only if every conflict projects to review and zero external write.

- [ ] **Step 7: Run GREEN tests and commit**

Run:
```bash
php tests/learned-rule-rollout-test.php
php tests/production-provision-test.php
php tests/web-entrypoint-test.php
php scripts/audit-tenant-sql.php
```
Commit: `feat: add shadow rollout gate for learned SAFE-T memory`

---

### Task 14: Full integration, CI, deployment and production validation

**Files:**
- Modify only as required by failures found in integration.
- Test: all `tests/*.php`
- Validate: all PHP/Node/Bash syntax, tenant SQL audit, shadow replay, CI, auto-gate, live health and Fred-Win bridge read/write singleton status.

- [ ] **Step 1: Run the complete local verification suite from a clean worktree**

```bash
set -e
for test in tests/*.php; do php "$test"; done
php scripts/audit-tenant-sql.php
find includes api admin workers scripts -name '*.php' -print0 | xargs -0 -n1 php -l
node --check scripts/amazon-returns/safe-t-status-parser.mjs
node --check scripts/amazon-returns/seller-central-safe-t-read-worker.mjs
node --check scripts/amazon-returns/seller-central-bridge-worker.mjs
bash -n scripts/install-service.sh
git diff --check
```
Expected: every command exits 0.

- [ ] **Step 2: Run learned-rule shadow replay with execution OFF**

Capture before counts for cases, events, outbox and rules; run replay; capture after counts and prove they are identical. Save the replay JSON as deployment evidence outside the repo. Require `hard_gate_bypass_count=0`.
- [ ] **Step 3: Push feature branch, open PR and require CI green**

Verify the PR head SHA equals the locally tested SHA. Do not merge with failed/pending required checks. Review the diff specifically for tenant scoping, CSRF, AI-to-write separation, hard-gate precedence, exact narrative persistence and accidental enabling of email/support writes.

- [ ] **Step 4: Merge and let the existing auto-gate deploy**

Do not manually replace the production release symlink. Wait for `amazon-returns-deploy.service` to accept the merged main SHA. Confirm `.release-sha`, `live_tenant_verification=ok`, database schema, case count continuity, exact write-profile version, `pending_outbox=0` and `dead_letters=0` before activation.

- [ ] **Step 5: Validate the cockpit in production while learned execution remains OFF**

Using the existing authenticated admin flow, prove:
- all current cases are listable/searchable, including closed/historical cases;
- opening a SAFE-T shows ordered events and every available persisted write snapshot/read-back;
- legacy missing narratives are labeled missing, never fabricated;
- unresolved review queue items show facts/deadlines/financial context;
- no known deterministic base rule is unnecessarily placed in review.

```bash
curl -fsS -b "$ADMIN_COOKIE_JAR" 'https://returns.shopvivaliz.com.br/admin/amazon-returns/api/cases.php?per_page=100' > /tmp/cockpit-cases.json
curl -fsS -b "$ADMIN_COOKIE_JAR" 'https://returns.shopvivaliz.com.br/admin/amazon-returns/api/reviews.php' > /tmp/cockpit-reviews.json
php -r '$j=json_decode(file_get_contents("/tmp/cockpit-cases.json"),true,512,JSON_THROW_ON_ERROR); if(!($j["success"]??false))exit(1);'
```

- [ ] **Step 6: Validate the AI advisor without approving a real decision**

Check `OPENAI_API_KEY` presence without printing it. If absent, provision a project-scoped key through the authorized OpenAI Platform workflow and store it only in `/home/ubuntu/amazon-returns-deploy/shared/.env` mode `0640`; never commit or log it. Keep `AMAZON_RETURNS_REVIEW_AI_MODEL=gpt-5.6-terra` unless an explicit later model decision changes it.

Open one genuinely pending review and request a suggestion. Verify the stored suggestion matches the strict schema, no outbox row is created, no case state is changed, and AI failure fallback still leaves manual controls usable.

```bash
before_outbox="$(sudo mysql --protocol=socket -uroot -Nse "SELECT COUNT(*) FROM amazon_returns_safet.amazon_return_outbox")"
# POST review-suggest through the authenticated admin smoke helper; do not POST review-decision.
after_outbox="$(sudo mysql --protocol=socket -uroot -Nse "SELECT COUNT(*) FROM amazon_returns_safet.amazon_return_outbox")"
test "$before_outbox" = "$after_outbox"
```

- [ ] **Step 7: Activate learned-rule execution only after the shadow gate passes**

Set `AMAZON_RETURNS_LEARNED_RULE_EXECUTION=1` atomically in the protected shared env, restart `amazon-returns-safet.service`, and verify health reports `learned_rule_execution_enabled=true`. Re-run scheduler once and confirm known deterministic cases continue normally while unmatched review cases remain review-only.

```bash
env_file=/home/ubuntu/amazon-returns-deploy/shared/.env
tmp="$(mktemp /home/ubuntu/amazon-returns-deploy/shared/.env.learned.XXXXXX)"
awk -F= 'BEGIN{done=0}$1=="AMAZON_RETURNS_LEARNED_RULE_EXECUTION"{print "AMAZON_RETURNS_LEARNED_RULE_EXECUTION=1";done=1;next}{print}END{if(!done)print "AMAZON_RETURNS_LEARNED_RULE_EXECUTION=1"}' "$env_file" > "$tmp"
sudo install -o root -g www-data -m 0640 "$tmp" "$env_file" && rm -f "$tmp"
sudo systemctl restart amazon-returns-safet.service
```

- [ ] **Step 8: Verify bridge/readers and no duplicate external action**

On Fred-Win confirm exactly one Seller Central writer and one SAFE-T reader process, both scheduled tasks `Running`, last 14 SAFE-T reads contain no unexpected `UNKNOWN`, and the existing appealed claim remains `APPEAL_SUBMITTED`/pending without a second appeal. Confirm the server outbox/dead-letter counts again after the read cycle.
- [ ] **Step 9: Final operational and repository hygiene gate**

Require:
```text
main == origin/main == deployed release SHA
open PRs = 0
dirty worktrees = 0
required Actions pending/failed = 0
pending_outbox = 0
dead_letters = 0
hard_gate_bypass_count = 0
```
Health must show current review/rule/AI counts and the exact external write flags. Email/support writes must still be false unless separately approved in a later change.

- [ ] **Step 10: Record acceptance evidence**

Preserve outside the repo: full test output, replay JSON, live verifier output, deployed SHA, health snapshot, bridge singleton/read snapshot and authenticated cockpit smoke result. Do not include credentials, cookies or tokens.

```bash
evidence_dir=/home/ubuntu/amazon-returns-audit-$(date -u +%Y%m%dT%H%M%SZ)/review-cockpit
mkdir -p "$evidence_dir"
cp /tmp/learned-rule-replay.json "$evidence_dir/replay.json"
printf '%s\n' "$(git rev-parse HEAD)" > "$evidence_dir/deployed-sha.txt"
```

Final acceptance requires the deterministic integration tests to demonstrate both directions: a learned rule reuses one approved decision for an existing matching case and a newly-created matching fixture without review, while a materially different fixture produces review and zero outbox write.

Commit any integration-only corrections with focused messages, re-run the complete suite, and repeat PR/CI/auto-gate if code changed after the first merge.

---

## Execution order and checkpoints

Tasks 1-4 establish safe persistence/signature/matching and automatic known-rule flow. Tasks 5-7 add human teaching, advisory AI and exact write snapshots. Tasks 8-12 build the read/review/memory cockpit. Task 13 gates activation; Task 14 performs full integration and production validation.

Do not turn on learned-rule execution merely because the UI works. The activation point is after the complete shadow replay proves `hard_gate_bypass_count=0` and CI/live verification are green.

## Execution-only smoke prerequisites

Production smoke credentials are runtime inputs, never repository content. Before Task 14 HTTP checks, obtain an authenticated cookie jar through the authorized admin login using a protected shell secret or existing authenticated connector session; expose only the cookie-file path as `ADMIN_COOKIE_JAR` and delete it after the smoke. Never print the password or cookie.

For DB count checks, run the query from a root-authorized VM shell using the existing local Unix-socket authentication. If an executor uses `MYSQL_CNF`, it must point to a root-only temporary client file and be deleted after verification; repository files must never contain database credentials.
