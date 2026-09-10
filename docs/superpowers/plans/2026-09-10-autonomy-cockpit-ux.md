# Autonomy Cockpit UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transformar o cockpit de Devoluções Amazon em uma interface operacional confiável que separe ação humana de saúde do sistema, exponha autonomia e evidências em português simples, unifique busca por pedido/NF/TBR/SAFE-T/rastreio/SKU/ASIN e só seja considerada pronta depois de validação real pela UI em produção.

**Architecture:** Preservar o event store, regras determinísticas, write gates, idempotência e isolamento tenant/connection. Criar duas projeções de leitura focadas: uma para resumo/saúde (`SvAmazonCockpitHealth`) e outra para resolução de referências (`SvAmazonCaseReferenceSearch`). O resumo passa a ser calculado no servidor em uma única leitura coerente e o browser deixa de paginar todos os casos para descobrir o estado do sistema. TBR é tratado como rastreio/referência da devolução baseado em evidência, separado do rastreio de entrega ao cliente. A UI mantém JavaScript sem framework e usa progressive disclosure para detalhe financeiro, mensagens, evidências e histórico.

**Tech Stack:** PHP 8.3, MySQL/PDO, JavaScript sem framework, CSS responsivo, Gmail API já existente, SP-API já existente, GitHub Actions, auto-deploy existente e Opera Browser Connector para homologação visual real.

**Spec:** `docs/superpowers/specs/2026-09-10-autonomy-cockpit-ux-design.md`, aprovada pelo proprietário em 2026-09-10. Este plano amplia e, quando houver conflito de apresentação, substitui as partes de UX de `docs/superpowers/plans/2026-09-10-cockpit-operational-dashboard.md`; não altera as regras de negócio/safety desse plano nem a arquitetura de autenticação/TOTP.

## Global Constraints

- Antes da primeira edição de implementação, invocar `superpowers:using-git-worktrees`, criar um worktree exclusivo deste chat e sincronizar a branch de trabalho com o `main` mais recente. Registrar `CHAT_CLI_SESSION_ID`, HEAD/base SHA, `git status`, worktrees, stashes, PRs e Actions antes de mutar arquivos. Nunca reutilizar sessão CLI de outro chat.
- Antes de cada patch, reler o arquivo e o trecho-alvo. Usar edição cirúrgica SEARCH/REPLACE com uma ocorrência exata; se o trecho mudou por trabalho concorrente, recalcular o patch sobre a versão nova.
- Preservar trabalho concorrente. Em especial, não alterar a arquitetura Seller Central TOTP/autenticador, seeds, cookies, credenciais, browser host ou provisionamento remoto nesta entrega.
- Manter D+45, elegibilidade real da Amazon, reconciliação financeira, write gates, idempotência, learned rules, tenant isolation e demais invariantes de `AGENTS.md`, `docs/REGRAS-DE-ENTREGA.md` e `docs/MEMORIA-DO-PROJETO.md`.
- Não alterar cadência de negócio nesta entrega: Gmail/SP-API/Finances/Returns/Seller Central/policy/scheduler permanecem em 12 horas, gatilhos por data conhecida permanecem pontuais, health permanece independente e `review_operations` permanece em 2 horas enquanto existirem revisões abertas.
- Nenhum teste de UI pode criar reivindicação SAFE-T, recurso, e-mail, chamado ou outro write externo fictício. Writes reais só podem ocorrer quando já forem ação legítima de um caso real e passarem pelos gates normais.
- TBR não vira chave primária nem nova coluna obrigatória. Ele é evidência de rastreio/referência de devolução (`return_tracking_id` / `return_tracking_ids`) e nunca deve ser confundido com `customer_tracking_ids` da entrega original ao cliente.
- Toda leitura de caso, evento, cursor, review, regra e evidência continua tenant/connection-scoped. Nenhum endpoint aceita `tenant_id` ou `amazon_connection_id` do request.
- Não armazenar corpo bruto novo de Gmail apenas para melhorar a interface. Mostrar somente narrativas/trechos já persistidos de forma segura; texto histórico ausente deve aparecer como indisponível, nunca ser reconstruído.
- Sem `innerHTML` para conteúdo de API/usuário. Sem enum, reason code, exception code, nome de job ou JSON bruto na experiência normal.
- Vermelho é reservado a falha/urgência real; espera normal e trabalho automático não usam aparência de erro.
- Deploy somente pelo auto-gate existente (`scripts/auto-deploy.sh` via serviço/timer). Não copiar arquivos manualmente para produção.
- Testes automatizados são necessários, mas conclusão funcional exige o fluxo real renderizado na UI autenticada de `returns.shopvivaliz.com.br`.

## File Structure After This Plan

- `includes/amazon-returns/CockpitHealth.php`: transforma summary, runtime health, source cursors e filas em uma projeção de saúde/operador sem texto técnico.
- `includes/amazon-returns/CaseReferenceSearch.php`: resolução tenant-scoped compartilhada de pedido, NF, TBR, SAFE-T, rastreio, SKU e ASIN.
- `includes/amazon-returns/GmailReturnReferenceLookup.php`: fallback de leitura sob demanda para localizar um pedido por TBR em mensagens Amazon já acessíveis via Gmail API, sem write externo.
- `admin/amazon-returns/assets/cockpit-summary.js`: renderização do banner de autonomia, trabalho humano, trabalho automático, quatro métricas financeiras, problemas operacionais, conectores, prazos e fallback last-known-good.
- `admin/amazon-returns/assets/cockpit-operational.js`: lista/detalhe operacional, valores, mensagens, evidências, TBR e explicação da decisão.
- `admin/amazon-returns/assets/cockpit-operational-bootstrap.js`: somente patches/integração de chrome operacional; a antiga varredura paginada do resumo é removida.
- `admin/amazon-returns/api/summary.php`: contrato único de resumo coerente.
- `admin/amazon-returns/api/cases.php`, `api/case.php`, `api/intake-lookup.php`, `api/review.php`: consumidores das projeções compartilhadas.

---

### Task 1: Resumo coerente, saúde e último ciclo bem-sucedido

**Files:**
- Create: `includes/amazon-returns/CockpitHealth.php`
- Modify: `includes/amazon-returns/CaseRepository.php` (`summary()`, adicionar `automationPreview()` e `upcomingDeadlines()`)
- Modify: `includes/amazon-returns/Runtime.php` (`health()` recebe relógio opcional apenas para determinismo de teste)
- Modify: `workers/amazon-returns/daemon.php` (`runOnce()` registra observabilidade em source cursors)
- Modify: `admin/amazon-returns/api/summary.php`
- Create: `tests/cockpit-summary-health-test.php`
- Modify: `tests/amazon-returns-runtime-test.php`
- Verify unchanged semantics: `tests/review-operations-test.php`, `tests/bridge-liveness-health-test.php`, `tests/amazon-returns-tenant-isolation-test.php`

**Interfaces:**

`SvAmazonCockpitHealth::build(SvAmazonTenantPersistence $p, SvAmazonReturnsConfig $config, array $caseSummary, DateTimeImmutable $now): array` returns:

```php
[
    'operator_status' => 'NORMAL|DEGRADED|USER_ACTION_REQUIRED',
    'human_action_count' => 0,
    'automatic_work_count' => 0,
    'concluded_count' => 0,
    'operational_problem_count' => 0,
    'last_successful_cycle_at' => null,
    'connectors' => [
        'amazon' => ['status'=>'OK|DEGRADED|UNKNOWN','observed_at'=>null,'reason'=>'...'],
        'gmail' => ['status'=>'OK|DEGRADED|UNKNOWN','observed_at'=>null,'reason'=>'...'],
        'seller_central' => ['status'=>'OK|DEGRADED|NOT_REQUIRED|UNKNOWN','observed_at'=>null,'reason'=>'...'],
    ],
    'operational_problems' => [],
]
```

`SvAmazonReturnCaseRepository::summary()` adds `concluded_cases`, `automatic_work_cases` and affected-value fields for health gates. `automationPreview(6)` returns only non-concluded cases without an open review. `upcomingDeadlines(8)` returns case/order/state/due kind/due timestamp/outstanding amount sorted overdue first, then nearest deadline.

The summary API preserves current compatibility keys (`checked_at`, current money breakdown, `health_gates`, `pending_reviews`) and adds:

```json
{
  "as_of": "2026-09-10T20:00:00+00:00",
  "operator_status": "DEGRADED",
  "human_action_count": 0,
  "automatic_work_count": 131,
  "concluded_count": 17,
  "operational_problem_count": 3,
  "last_successful_cycle_at": "2026-09-10T18:00:00+00:00",
  "money": {
    "at_risk": "6456.00",
    "awaiting_credit": "5506.64",
    "in_dispute": "385.96",
    "recovered": "2290.36",
    "breakdown": {}
  },
  "connectors": {},
  "operational_problems": [],
  "automation_preview": [],
  "deadlines": []
}
```

- [ ] **Step 1: Prepare the isolated execution baseline**

Use `superpowers:using-git-worktrees`. In the new chat-specific worktree, record the current `main` SHA and merge/rebase the approved design branch on the latest `main` without force-push. Run the current focal baseline before editing:

```bash
php tests/cockpit-api-contract-test.php
php tests/cockpit-operational-audit-test.php
php tests/cockpit-ui-contract-test.php
php tests/amazon-returns-runtime-test.php
php tests/review-operations-test.php
php tests/bridge-liveness-health-test.php
```

Expected: current tests pass. Any pre-existing failure is captured before feature changes and investigated rather than hidden.

- [ ] **Step 2: Write the failing summary/health test**

Create `tests/cockpit-summary-health-test.php` with pure status cases and source-contract assertions. Core assertions:

```php
require_once __DIR__.'/../includes/amazon-returns/CockpitHealth.php';

function cshSame(mixed $want,mixed $got,string $why):void{
    if($want!==$got)throw new RuntimeException($why.' want='.json_encode($want).' got='.json_encode($got));
}
function cshAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}

cshSame('NORMAL', SvAmazonCockpitHealth::operatorStatus(0, 0), 'Healthy automation must be NORMAL.');
cshSame('DEGRADED', SvAmazonCockpitHealth::operatorStatus(0, 2), 'Operational faults without human work must be DEGRADED.');
cshSame('USER_ACTION_REQUIRED', SvAmazonCockpitHealth::operatorStatus(1, 2), 'Human action takes precedence.');

$summary=(string)file_get_contents(__DIR__.'/../admin/amazon-returns/api/summary.php');
foreach(['as_of','operator_status','human_action_count','automatic_work_count','concluded_count','operational_problem_count','last_successful_cycle_at','connectors','operational_problems','automation_preview','deadlines'] as $key){
    cshAssert(str_contains($summary,"'{$key}'"),'Summary contract missing '.$key);
}
$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
cshAssert(str_contains($daemon,"'OPERATIONAL_TASK'"),'Daemon must persist per-task operational observation.');
cshAssert(str_contains($daemon,"'cycle_success'"),'Daemon must persist the last fully successful cycle.');
```

Also assert that `review_operations` remains 7200 seconds and all approved business cadences remain 43200 seconds.

- [ ] **Step 3: Run the new test and confirm the intended red state**

Run:

```bash
php tests/cockpit-summary-health-test.php
```

Expected: FAIL because `CockpitHealth.php` and the new summary contract do not exist yet. A pass before implementation means the test is not discriminating enough and must be corrected.

- [ ] **Step 4: Implement daemon observability without changing business scheduling**

After each due task completes, persist only task name, timestamp and sanitized status:

```php
$taskStatus=strtoupper(trim((string)($results[$task]['status'] ?? 'UNKNOWN')));
try{
    $this->persistence->cursors->save(
        'OPERATIONAL_TASK',
        $task,
        $now->format(DATE_ATOM),
        ['status'=>$taskStatus]
    );
}catch(Throwable $observabilityError){
    error_log('[amazon-returns-operational-observability] '.$observabilityError::class);
}
```

Immediately before saving runtime state, compute once and persist the cycle attempt. Only an `OK` overall cycle updates `cycle_success`:

```php
$overallStatus=$this->overallStatus($results);
try{
    $this->persistence->cursors->save('OPERATIONAL','cycle_attempt',$now->format(DATE_ATOM),['status'=>$overallStatus]);
    if($overallStatus==='OK'){
        $this->persistence->cursors->save('OPERATIONAL','cycle_success',$now->format(DATE_ATOM),['status'=>'OK']);
    }
}catch(Throwable $observabilityError){
    error_log('[amazon-returns-operational-cycle] '.$observabilityError::class);
}
```

Return `$overallStatus` instead of recomputing it. Do not add or remove due tasks, do not change cadence, and do not include message bodies, credentials or exception text in cursor metadata.

- [ ] **Step 5: Make runtime liveness deterministic for the presenter**

Change only the signature and clock source:

```php
public static function health(
    SvAmazonTenantPersistence $p,
    SvAmazonReturnsConfig $config,
    ?DateTimeImmutable $now=null
): array {
    $now ??= new DateTimeImmutable('now',new DateTimeZone('UTC'));
    // existing body
}
```

Pass `$now` to `SvAmazonBridgeLiveness::evaluate()` instead of constructing a second current timestamp. Existing two-argument callers remain compatible.

- [ ] **Step 6: Extend repository summary and previews**

In `summary()`, keep existing financial calculations and add server-side counts. `automatic_work_cases` must mean a non-concluded case without an open review, not `total - review` inferred in JavaScript. Add affected amounts for each gate using the same `$exposure` expression.

Add `automationPreview(int $limit=6)` with tenant/connection predicates and `NOT EXISTS` for an open review. Select only the fields the summary UI needs. Add `upcomingDeadlines(int $limit=8)` using `appeal_deadline_at`, `next_action_at` and `eligibility_at`, with a `due_kind` selected deterministically. Both queries must sort urgent rows first and use `$exposure DESC` as a business-value tie-breaker.

- [ ] **Step 7: Implement `SvAmazonCockpitHealth`**

Use the existing `SvAmazonReturnsRuntime::health()` and source cursors. Business-source freshness for 12-hour jobs is healthy through 18 hours, leaving one half-cycle of grace. `seller_central` uses `seller_central_browser` liveness; `NOT_REQUIRED` is displayed as on-demand and is not an operational fault.

`operatorStatus()` is deliberately pure:

```php
public static function operatorStatus(int $humanActions,int $operationalProblems): string
{
    if($humanActions>0)return 'USER_ACTION_REQUIRED';
    if($operationalProblems>0)return 'DEGRADED';
    return 'NORMAL';
}
```

Build `operational_problems` as issue categories rather than summing affected cases. For example, four unclassified cases are one problem category carrying `affected_cases=4`. Include case-health gates, dead letters, and degraded/unknown required connectors. Each problem item contains `key`, `affected_cases`, optional `affected_amount`, `owner` (`SYSTEM` or `USER`), safe `status`, and safe timestamps. Never pass raw exception text or secret-bearing metadata.

- [ ] **Step 8: Replace the summary API assembly with one coherent snapshot**

In `summary.php`, start a transaction before reading cases/reviews/cursors/outbox and commit after assembling the payload so all headline counts come from one repeatable-read snapshot. Do not call `SvAmazonReturnProjector::project()` from summary because it writes projections.

Build the four primary monetary values:

```php
$breakdown=[/* existing nine keys, formatted as decimal strings */];
$money=$breakdown;
$money['awaiting_credit']=$breakdown['approved_awaiting_credit'];
$money['in_dispute']=number_format(
    (float)$breakdown['safe_t_submitted']+(float)$breakdown['denied']+
    (float)$breakdown['appeal']+(float)$breakdown['support'],
    2,'.',''
);
$money['breakdown']=$breakdown;
```

Return `as_of` and keep `checked_at` as a compatibility alias. Keep old summary keys during this release so an old cached browser asset cannot break during deploy.

- [ ] **Step 9: Prove focused behavior and regression safety**

Run:

```bash
php tests/cockpit-summary-health-test.php
php tests/amazon-returns-runtime-test.php
php tests/review-operations-test.php
php tests/bridge-liveness-health-test.php
php tests/amazon-returns-tenant-isolation-test.php
php tests/cockpit-api-contract-test.php
```

Expected: PASS. Explicitly verify `review_operations=7200` and business routines `=43200` remain unchanged.

- [ ] **Step 10: Commit the coherent summary slice**

```bash
git add includes/amazon-returns/CockpitHealth.php includes/amazon-returns/CaseRepository.php includes/amazon-returns/Runtime.php workers/amazon-returns/daemon.php admin/amazon-returns/api/summary.php tests/cockpit-summary-health-test.php tests/amazon-returns-runtime-test.php
git diff --cached --check
git commit -m "feat: expose coherent cockpit health summary"
```

### Task 2: Dashboard de autonomia, hierarquia financeira e degraded-mode resiliente

**Files:**
- Create: `admin/amazon-returns/assets/cockpit-summary.js`
- Modify: `admin/amazon-returns/index.php`
- Modify: `admin/amazon-returns/assets/cockpit.js` (`loadSummary()` delegates to the new module)
- Modify: `admin/amazon-returns/assets/cockpit-operational-bootstrap.js` (remove full-case summary scan)
- Modify: `admin/amazon-returns/assets/cockpit-operational.css`
- Modify: `tests/cockpit-ui-contract-test.php`
- Modify: `tests/cockpit-operational-audit-test.php`
- Create: `tests/cockpit-summary-ui-test.php`

**Interfaces:**

`window.AmazonReturnsSummary.load()` owns summary fetch/render/cache. It must render:
- autonomy banner;
- `Precisa de você?`;
- `Sistema tratando agora`;
- four primary financial cards;
- detail financial breakdown under `<details>`;
- operational problems;
- connector state;
- deadlines.

It stores a safe last-known-good summary in `sessionStorage` under `amazonReturns:lastGoodSummary:v1` with a 20-minute display TTL. Storage failure is non-fatal.

- [ ] **Step 1: Write failing UI contract tests**

`tests/cockpit-summary-ui-test.php` must assert:

```php
$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/admin/amazon-returns/index.php');
$summary=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit-summary.js');
$bootstrap=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit-operational-bootstrap.js');

foreach(['autonomy-status','user-work','automation-work','money-headlines','money-breakdown','operational-problems','deadline-list','connector-health'] as $id){
    if(!str_contains($page,'id="'.$id.'"'))throw new RuntimeException('Missing dashboard region '.$id);
}
foreach(['Operando normalmente','Sistema requer atenção','Sua intervenção é necessária','Nenhuma ação sua é necessária','Sistema tratando agora','Em risco','Aguardando crédito','Em disputa','Recuperado','Últimos dados disponíveis'] as $text){
    if(!str_contains($summary,$text))throw new RuntimeException('Missing operator copy '.$text);
}
if(str_contains($bootstrap,'while(all.length<total'))throw new RuntimeException('Browser must not scan every case to build the top summary.');
if(str_contains($summary,'innerHTML'))throw new RuntimeException('Summary renderer cannot use innerHTML.');
```

Also require the exact search hint to include `TBR` in `index.php`.

- [ ] **Step 2: Run tests to confirm red**

```bash
php tests/cockpit-summary-ui-test.php
php tests/cockpit-operational-audit-test.php
```

Expected: FAIL because the new regions/module do not exist and the old full pagination scan is still present.

- [ ] **Step 3: Restructure `index.php` around operator questions**

Replace the current independent `#operational-overview`, nine equal financial cards and large generic gate panel with these regions in this order:

```html
<section id="autonomy-status" class="autonomy-status" aria-live="polite"></section>
<section id="user-work" class="operator-section" aria-label="Precisa de você?"></section>
<section id="automation-work" class="operator-section" aria-label="Sistema tratando agora"></section>
<section id="money-headlines" class="money-headlines" aria-label="Resumo financeiro"></section>
<details id="money-breakdown" class="money-breakdown"><summary>Ver detalhes financeiros</summary><div id="money-breakdown-items"></div></details>
<section id="operational-problems" class="operator-section" aria-label="Saúde operacional"></section>
<section id="deadline-list" class="operator-section" aria-label="Prazos importantes"></section>
<section id="connector-health" class="connector-health" aria-label="Conexões do sistema"></section>
```

Keep existing cockpit tabs/review count/case list intact. Load `cockpit-summary.js` before `cockpit.js`, all with `defer`, and version the new asset. Change the search help to:

`Pedido, NF, TBR, SAFE-T, rastreio, SKU ou ASIN`.

- [ ] **Step 4: Implement safe summary fetch/cache/render**

The module uses `textContent`/`createElement` only. Cache flow:

```js
const CACHE_KEY='amazonReturns:lastGoodSummary:v1';
const CACHE_TTL_MS=20*60*1000;

function saveLastGood(payload){
  try{sessionStorage.setItem(CACHE_KEY,JSON.stringify({saved_at:Date.now(),payload}));}catch{}
}
function readLastGood(){
  try{
    const parsed=JSON.parse(sessionStorage.getItem(CACHE_KEY)||'null');
    if(!parsed||Date.now()-Number(parsed.saved_at||0)>CACHE_TTL_MS)return null;
    return parsed.payload||null;
  }catch{return null;}
}
```

On fresh success, render and cache. On failure with cache, retain all last-known values and prepend:

`Últimos dados disponíveis, atualizados às <hora>. A atualização mais recente falhou e será tentada novamente.`

On failure without cache, show an explicit unavailable state that does not look like zero problems.

Map status keys only to operator copy:

```js
const statusCopy={
  NORMAL:'Operando normalmente',
  DEGRADED:'Sistema requer atenção',
  USER_ACTION_REQUIRED:'Sua intervenção é necessária'
};
```

- [ ] **Step 5: Render responsibility separately from health**

`#user-work` uses only `human_action_count`. If zero, show exactly `Nenhuma ação sua é necessária.`. If positive, show count plus a button that activates the existing Reviews tab.

`#operational-problems` uses `operational_problem_count` and the category items. If there are operational faults but `human_action_count===0`, the banner subcopy must explicitly say that the system owns them, for example:

`Você não precisa agir. O sistema identificou 3 problemas operacionais e está tratando ou tentando novamente.`

A category with `owner==='USER'` is rendered as a user action and must also be reflected in `human_action_count`; inconsistent payloads are displayed as degraded rather than silently normalized to healthy.

- [ ] **Step 6: Render automatic work, four money headlines, connectors and deadlines**

Primary money cards are only `Em risco`, `Aguardando crédito`, `Em disputa`, `Recuperado`. The existing nine categories remain in `#money-breakdown-items` under disclosure.

`#automation-work` renders the server preview with order, human state label, outstanding exposure and known next date/condition. It does not infer that no visible row means no automatic work; the headline count still comes from the server.

Connector copy maps `OK` to `Funcionando`, `DEGRADED` to `Requer atenção`, `NOT_REQUIRED` to `Sob demanda` and `UNKNOWN` to `Sem confirmação recente`. Raw `reason` keys never appear.

Deadlines are labeled `Atrasado`, `Hoje`, `Amanhã` or `Próximos 7 dias` from timestamps and identify order + amount + plain next-step label.

- [ ] **Step 7: Remove the second all-case browser scan**

Delete `loadOperationalOverview()` logic that loops `/api/cases.php?page=N&per_page=100`. `cockpit-operational-bootstrap.js` may still patch list/detail behavior, but it no longer owns top summary counts.

Rewrite base `loadSummary()` in `cockpit.js` as a small delegation:

```js
async function loadSummary(){
  if(!window.AmazonReturnsSummary){
    showError('Não foi possível iniciar o resumo operacional.');
    return;
  }
  await window.AmazonReturnsSummary.load();
}
```

This preserves calls from review refresh logic while keeping one renderer/fetch path.

- [ ] **Step 8: Style for hierarchy and calm healthy states**

Use `cockpit-operational.css` for status banner, four-card grid, compact zero states, connector pills, issue rows and deadline rows. Preserve touch targets `min-height:44px`. At widths below 980px, primary cards become two columns; below 600px, one column. Only `.status-degraded`, `.problem-overdue`, `.problem-failed` and true user-urgent states use red/error accents.

- [ ] **Step 9: Run focused UI contracts**

```bash
php tests/cockpit-summary-ui-test.php
php tests/cockpit-ui-contract-test.php
php tests/cockpit-operational-audit-test.php
php tests/user-ui-language-contract-test.php
node --check admin/amazon-returns/assets/cockpit-summary.js
node --check admin/amazon-returns/assets/cockpit-operational.js
node --check admin/amazon-returns/assets/cockpit-operational-bootstrap.js
```

Expected: PASS.

- [ ] **Step 10: Commit dashboard slice**

```bash
git add admin/amazon-returns/index.php admin/amazon-returns/assets/cockpit-summary.js admin/amazon-returns/assets/cockpit.js admin/amazon-returns/assets/cockpit-operational-bootstrap.js admin/amazon-returns/assets/cockpit-operational.css tests/cockpit-summary-ui-test.php tests/cockpit-ui-contract-test.php tests/cockpit-operational-audit-test.php
git diff --cached --check
git commit -m "feat: make cockpit autonomy and health explicit"
```

### Task 3: Busca universal compartilhada e TBR como evidência de devolução

**Files:**
- Create: `includes/amazon-returns/CaseReferenceSearch.php`
- Create: `includes/amazon-returns/GmailReturnReferenceLookup.php`
- Modify: `includes/amazon-returns/CaseRepository.php` (`search()` aceita `case_ids`)
- Modify: `includes/amazon-returns/GmailParser.php`
- Modify: `includes/amazon-returns/GmailEventSink.php`
- Modify: `includes/amazon-returns/Projector.php`
- Modify: `includes/amazon-returns/CockpitTimeline.php`
- Modify: `admin/amazon-returns/api/cases.php`
- Create: `tests/case-reference-search-test.php`
- Modify: `tests/amazon-returns-gmail-test.php`
- Modify: `tests/cockpit-search-native-pdo-test.php`
- Modify: `tests/cockpit-api-contract-test.php`
- Modify: `tests/cockpit-timeline-test.php`

**Interfaces:**

```php
final class SvAmazonCaseReferenceSearch
{
    public const ORDER='ORDER';
    public const INVOICE='INVOICE';
    public const RETURN_TRACKING='RETURN_TRACKING';
    public const REFERENCE='REFERENCE';

    public static function kind(string $term): string;
    /** @return list<int> */
    public static function caseIds(PDO $db,SvAmazonTenantContext $context,string $term): array;
}
```

```php
final class SvAmazonGmailReturnReferenceLookup
{
    public function __construct(private SvAmazonGmailApiClient $gmail) {}
    /** @return array{order_id:string,event:array<string,mixed>}|null */
    public function find(string $tbr): ?array;
}
```

- [ ] **Step 1: Write failing reference and Gmail tests**

In `tests/case-reference-search-test.php`:

```php
require_once __DIR__.'/../includes/amazon-returns/CaseReferenceSearch.php';
crsSame('ORDER',SvAmazonCaseReferenceSearch::kind('702-1234567-7654321'),'Order classification.');
crsSame('INVOICE',SvAmazonCaseReferenceSearch::kind('123456'),'NF classification.');
crsSame('RETURN_TRACKING',SvAmazonCaseReferenceSearch::kind('tbr015328001'),'TBR classification.');
crsSame('REFERENCE',SvAmazonCaseReferenceSearch::kind('B0ABC12345'),'Generic reference classification.');
```

The fake PDO test must inspect executed SQL and prove both `tenant_id=:tenant_id` and `amazon_connection_id=:amazon_connection_id` are present, that event evidence is searched only through known reference fields, and that duplicate case IDs are de-duplicated.

Extend `tests/amazon-returns-gmail-test.php`:

```php
$returnWithTbr=message(
    'm-return-tbr',
    'Notificação de autorização de devolução referente ao pedido de número 702-1111111-2222222',
    'Acompanhe a devolução pelo código TBR015328001.'
);
$parsed=$parser->parse($returnWithTbr);
gmSame('TBR015328001',$parsed[0]['return_tracking_id']??null,'Return authorization must preserve TBR separately.');
$payload=SvAmazonGmailEventSink::payloadForTest($parsed[0],$parsed[0]['order_id']);
gmSame(['TBR015328001'],$payload['return_tracking_ids']??null,'TBR must be return tracking evidence.');
gmSame([], $payload['customer_tracking_ids']??null,'TBR must not become customer delivery tracking.');
```

If exposing `payloadForTest()` would create production API solely for a test, instead assert through a persisted event fixture or a focused existing public helper; do not weaken visibility just for testing.

- [ ] **Step 2: Run the focused tests and confirm red**

```bash
php tests/case-reference-search-test.php
php tests/amazon-returns-gmail-test.php
```

Expected: FAIL because the resolver does not exist and return authorization currently discards TBR.

- [ ] **Step 3: Implement TBR extraction without mixing delivery tracking**

In `GmailParser::parse()`, add `$returnTrackingId=null` and only populate it for return authorization from an explicit token:

```php
if($eventType==='RETURN_AUTHORIZED_EMAIL'
    && preg_match('/\b(TBR[A-Z0-9-]{6,30})\b/i',$combined,$trackingMatch)===1){
    $returnTrackingId=strtoupper($trackingMatch[1]);
}
```

Return `return_tracking_id=>$returnTrackingId` in the sanitized event.

In `GmailEventSink::payload()`, add a separate array:

```php
$returnTracking=trim((string)($event['return_tracking_id'] ?? ''));
'return_tracking_ids'=>$returnTracking!==''?[strtoupper($returnTracking)]:[],
```

Do not put this value in `customer_tracking_ids`.

- [ ] **Step 4: Project and expose return tracking evidence**

In `Projector::initialFacts()` add `return_tracking_ids=>[]`. For `RETURN_AUTHORIZED_EMAIL` and any `RETURN_REPORT_OBSERVED` event that already carries `return_tracking_id`/`return_tracking_ids`, merge the values through the existing sanitized list helper. Existing historical events without this field remain valid.

Add `return_tracking_id`, `return_tracking_ids`, `invoice_number`, `return_reason`, `tracking_id` and `carrier` to the safe `CockpitTimeline::EVENT_FIELDS` allowlist only where those keys are already sanitized domain evidence. This exposes evidence, not arbitrary payload fields.

- [ ] **Step 5: Implement the tenant-scoped reference resolver**

`kind()` rules:

```php
if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/',$term)===1)return self::ORDER;
if(preg_match('/^TBR[A-Z0-9-]{6,30}$/i',$term)===1)return self::RETURN_TRACKING;
if(preg_match('/^[0-9]{1,20}$/',$term)===1)return self::INVOICE;
return self::REFERENCE;
```

`caseIds()` unions IDs from:
- direct case fields: order, SAFE-T, SKU, ASIN;
- `SvAmazonInvoiceSearch::caseIds()`;
- scoped event payload reference paths: `return_tracking_id`, `return_tracking_ids`, `customer_tracking_ids`, `tracking_id`, `tracking_ids`, `invoice_number`, `sales_invoice_number`.

Use unique PDO parameter names for every occurrence. For TBR and exact structured identifiers, prefer exact normalized matching; for free search, use escaped partial matching. Never search arbitrary message bodies/JSON keys.

- [ ] **Step 6: Add `case_ids` to repository search safely**

Allow `case_ids` only as an internal array filter. Empty array adds `1=0`. Validate every ID as positive integer and bind unique `:case_id_0`, `:case_id_1` parameters. Do not accept `case_ids` directly from `$_GET`; only `cases.php` can derive it through the resolver.

Add `$outstanding DESC` after human/overdue/deadline ordering so equal-urgency cases prioritize financial exposure.

- [ ] **Step 7: Replace the current projected 1000-row text scan in `cases.php`**

When `q` exists:

```php
$term=(string)$filters->filters()['q'];
$caseIds=SvAmazonCaseReferenceSearch::caseIds($db,$context,$term);
$sqlFilters=$filters->filters();
unset($sqlFilters['q']);
$sqlFilters['case_ids']=$caseIds;
```

Then use normal paginated repository search and project only returned cases. Keep action filtering behavior compatible, but remove the special full-tenant `q` post-filter loop. Expose `return_tracking_ids` and convenience `return_tracking_id` in list payload.

If local TBR resolution is empty, `cases.php` may perform an on-demand Gmail read through `SvAmazonGmailReturnReferenceLookup`; if it resolves an order already present locally, resolve that order to case IDs. Do not create/sync a case from the GET list endpoint.

- [ ] **Step 8: Implement Gmail TBR fallback as read-only evidence lookup**

Validate TBR before forming the Gmail search. Search the exact token through existing `SvAmazonGmailApiClient::searchMessages()` and pass each normalized Amazon message through the modified `SvAmazonGmailParser`. Return only an event where `event_type==='RETURN_AUTHORIZED_EMAIL'` and `return_tracking_id` exactly matches the requested TBR. The parser already rejects non-Amazon senders.

Do not persist inside `GmailReturnReferenceLookup`; persistence is the caller's explicit responsibility.

- [ ] **Step 9: Prove reference behavior**

```bash
php tests/case-reference-search-test.php
php tests/amazon-returns-gmail-test.php
php tests/cockpit-search-native-pdo-test.php
php tests/cockpit-api-contract-test.php
php tests/cockpit-timeline-test.php
php tests/amazon-returns-tenant-isolation-test.php
```

Expected: PASS, including no duplicate placeholders under native PDO and no cross-tenant reference match.

- [ ] **Step 10: Commit universal reference slice**

```bash
git add includes/amazon-returns/CaseReferenceSearch.php includes/amazon-returns/GmailReturnReferenceLookup.php includes/amazon-returns/CaseRepository.php includes/amazon-returns/GmailParser.php includes/amazon-returns/GmailEventSink.php includes/amazon-returns/Projector.php includes/amazon-returns/CockpitTimeline.php admin/amazon-returns/api/cases.php tests/case-reference-search-test.php tests/amazon-returns-gmail-test.php tests/cockpit-search-native-pdo-test.php tests/cockpit-api-contract-test.php tests/cockpit-timeline-test.php
git diff --cached --check
git commit -m "feat: unify case reference search with return tracking"
```

### Task 4: Intake usa a mesma busca e sempre mostra preview antes do save

**Files:**
- Modify: `admin/amazon-returns/api/intake-lookup.php`
- Modify: `admin/amazon-returns/intake.php`
- Modify: `tests/intake-ux-daily-cadence-test.php`
- Modify: `tests/invoice-intake-live-lookup-contract-test.php`
- Create: `tests/intake-reference-search-test.php`

**Interfaces:**

Primary request becomes:

```json
{"query":"702-1234567-7654321","csrf_token":"..."}
```

For one deploy generation, keep accepting legacy `order_id` and `sales_invoice_number` if `query` is absent so an old browser asset cannot break during rolling deploy.

Response cases are always projected and include `return_tracking_ids`, `outstanding_amount`/financial context needed for preview, regardless of whether the source was local or remote.

- [ ] **Step 1: Write failing intake reference tests**

Assert source code and request behavior require `query`, retain legacy compatibility, include `SvAmazonCaseReferenceSearch`, and expose operator help `Pedido, NF ou TBR / rastreio da devolução`.

Core fixture expectations:

```php
intakeSame('RETURN_TRACKING',SvAmazonCaseReferenceSearch::kind('TBR015328001'),'TBR input must use return-reference path.');
intakeAssert(str_contains($endpoint,'SvAmazonCaseReferenceSearch::caseIds'),'Intake and cockpit must share local resolver.');
intakeAssert(str_contains($endpoint,'SvAmazonGmailReturnReferenceLookup'),'Historical TBR can be resolved on demand.');
intakeAssert(str_contains($page,'preview'),'A matched case is previewed before confirmation.');
```

- [ ] **Step 2: Run intake tests and confirm red**

```bash
php tests/intake-reference-search-test.php
php tests/intake-ux-daily-cadence-test.php
php tests/invoice-intake-live-lookup-contract-test.php
```

Expected: new reference test FAIL; existing tests remain a baseline for order/NF behavior.

- [ ] **Step 3: Normalize one query server-side**

Read `query` first. If absent, map exactly one legacy input into it. Classify via `SvAmazonCaseReferenceSearch::kind()`.

Local-first flow:
1. `caseIds()` lookup;
2. fetch every owned case ID;
3. project each case before returning it;
4. return `source=local` when found.

Do not return raw unprojected `forOrder()` rows on the local fast path.

- [ ] **Step 4: Preserve order and NF remote behavior**

For `ORDER`, if no local match, use the current immediate `syncOrder()` + financial refresh path.

For `INVOICE`, keep `SvAmazonInvoiceSearch` then `SvAmazonInvoiceRemoteLookup`; when a remote NF resolves an order, continue through the existing order sync and immutable `SALES_INVOICE_LINKED` evidence path.

Do not change invoice authorization handling or create a new write gate.

- [ ] **Step 5: Add TBR on-demand historical recovery**

For `RETURN_TRACKING` with no local match:
1. query Gmail through `SvAmazonGmailReturnReferenceLookup`;
2. if no Amazon return message resolves it, return `cases=[]` with a clear no-match response;
3. if an order is resolved, persist only the sanitized parsed return-authorization event through `SvAmazonGmailEventSink::persist()`;
4. synchronize the resolved Amazon order if it is not already local;
5. project and return the owned case(s).

This is a user-initiated read/reconciliation path, not a scheduled new business cadence and not an external write to Amazon/Gmail.

- [ ] **Step 6: Replace two lookup inputs with one operator search field**

`intake.php` uses one search field labeled `Localizar devolução` with help:

`Use número do pedido Amazon, NF de venda ou TBR / rastreio da devolução.`

Keep the lookup and save requests CSRF-protected. On no match, preserve the input value and say which identifiers are accepted.

- [ ] **Step 7: Render a confirmation preview before enabling state-changing controls**

For each matched case render, using `textContent` only:
- pedido;
- TBR/rastreio da devolução when available;
- SKU and ASIN;
- quantidade esperada;
- reembolso/retorno context when known;
- current physical status.

Only after the user selects the correct matched case reveal quantity/condition/notes and `Confirmar recebimento`. Preserve existing idempotency and double-click guard in `api/intake.php`.

- [ ] **Step 8: Run intake regression suite**

```bash
php tests/intake-reference-search-test.php
php tests/intake-ux-daily-cadence-test.php
php tests/invoice-intake-live-lookup-contract-test.php
php tests/amazon-invoice-live-lookup-test.php
php tests/amazon-invoice-evidence-test.php
php tests/amazon-returns-admin-test.php
```

Expected: PASS.

- [ ] **Step 9: Commit intake slice**

```bash
git add admin/amazon-returns/api/intake-lookup.php admin/amazon-returns/intake.php tests/intake-reference-search-test.php tests/intake-ux-daily-cadence-test.php tests/invoice-intake-live-lookup-contract-test.php
git diff --cached --check
git commit -m "feat: unify return intake lookup and preview"
```

### Task 5: Lista de casos com semântica financeira correta, TBR e próximo passo

**Files:**
- Modify: `admin/amazon-returns/assets/cockpit-operational.js`
- Modify: `admin/amazon-returns/assets/cockpit-operational.css`
- Modify: `tests/cockpit-ui-contract-test.php`
- Modify: `tests/cockpit-operational-audit-test.php`

- [ ] **Step 1: Write failing zero-outstanding and TBR UI contracts**

Add assertions that require `operatorFinancialSummary`, TBR rendering and explicit responsibility labels. Include a negative assertion that the renderer cannot append the literal `saldo ainda a recuperar` unconditionally.

Recommended pure helper behavior to encode in the source contract:

```js
function operatorFinancialSummary(c){
  const outstanding=Math.max(0,Number(c?.outstanding_amount||0));
  if(outstanding>0)return {value:brl(outstanding),label:'saldo ainda a recuperar'};
  const recovered=Number(c?.reconciled_credit_amount||0);
  const pending=['SAFE_T_APPROVED','APPEAL_APPROVED','CREDIT_PENDING'].includes(String(c?.state||''));
  if(recovered>0&&pending)return {value:brl(0),label:'Crédito identificado; aguardando confirmação final.'};
  return {value:brl(0),label:'Nenhum saldo financeiro em aberto.'};
}
```

- [ ] **Step 2: Run contract tests and confirm red**

```bash
php tests/cockpit-ui-contract-test.php
php tests/cockpit-operational-audit-test.php
```

Expected: FAIL on unconditional zero-balance copy/TBR requirements.

- [ ] **Step 3: Implement zero-balance-safe financial rendering**

Use the helper in every list row and relevant summary component. Do not change persisted case state solely for presentation. A zero outstanding amount with no positive reconciled credit must not claim that a credit was identified; it gets `Nenhum saldo financeiro em aberto.`.

- [ ] **Step 4: Add TBR and next automatic step to case rows**

Show first `return_tracking_ids[0]` as `TBR / devolução <id>` when present. Keep SAFE-T separate. Responsibility becomes a compact, explicit `Sistema`, `Você` or `Concluído` display derived from existing responsibility logic. Under status, show the first meaningful next step/date/condition without raw state/action enum.

- [ ] **Step 5: Keep urgency truthful**

Reuse the existing completed-write suppression for appeal deadlines. A red `Providência automática atrasada` requires:
- due time genuinely in the past;
- action still requires execution;
- no pending/processing/succeeded matching external write;
- case not already in a post-action/concluded state.

- [ ] **Step 6: Run focused tests**

```bash
php tests/cockpit-ui-contract-test.php
php tests/cockpit-operational-audit-test.php
php tests/user-ui-language-contract-test.php
node --check admin/amazon-returns/assets/cockpit-operational.js
```

Expected: PASS.

- [ ] **Step 7: Commit list semantics slice**

```bash
git add admin/amazon-returns/assets/cockpit-operational.js admin/amazon-returns/assets/cockpit-operational.css tests/cockpit-ui-contract-test.php tests/cockpit-operational-audit-test.php
git diff --cached --check
git commit -m "feat: clarify case list responsibility and balances"
```

### Task 6: Detalhe do caso com mensagens, evidências e “Por que o sistema decidiu isso?”

**Files:**
- Modify: `admin/amazon-returns/api/case.php`
- Modify: `includes/amazon-returns/CockpitTimeline.php`
- Modify: `admin/amazon-returns/assets/cockpit-operational.js`
- Modify: `admin/amazon-returns/assets/cockpit-operational.css`
- Modify: `tests/cockpit-api-contract-test.php`
- Modify: `tests/cockpit-timeline-test.php`
- Create: `tests/cockpit-case-explanation-test.php`

**Contract:** Detail must make available projected `return_tracking_ids`, sales invoice, return reason when persisted, `last_external_write`, `last_read_back`, latest rule application reference, safe message items, all important dates and financial values.

- [ ] **Step 1: Write failing case explanation contracts**

Require the rendered headings/copy:

```text
O que aconteceu
O que o sistema fez
O que acontece agora
Mensagens com a Amazon
Evidências
Por que o sistema decidiu isso?
Datas importantes
Valores
Ver histórico completo
```

Require API/timeline support for `return_tracking_ids`, `return_reason`, persisted write narrative and `review_excerpt`. Assert that `MISSING_HISTORICAL_SNAPSHOT` is not transformed into fabricated text.

- [ ] **Step 2: Run tests and confirm red**

```bash
php tests/cockpit-case-explanation-test.php
php tests/cockpit-api-contract-test.php
php tests/cockpit-timeline-test.php
```

Expected: new explanation contract FAIL before implementation.

- [ ] **Step 3: Enrich case detail from already persisted evidence**

In `case.php`, project the case once, derive invoice as today, expose return tracking from projection, and find the latest applicable rule application from the already loaded rule applications. Do not execute a decision or external read as part of opening detail.

Keep important date fields semantic: `order_at`, `refund_at`, `seller_debit_at`, actual `physical_received_at`, `eligibility_at`, `next_action_at`, `appeal_deadline_at`, `last_read_back.occurred_at`.

- [ ] **Step 4: Build a safe message projection from the timeline**

Add a small frontend selector over timeline items. Include only:
- external-write items with persisted `write_snapshot` v2 narrative/message;
- Amazon response items with safe stored response/review excerpts;
- Gmail SAFE-T review response excerpts already persisted.

Do not show hashes, idempotency keys or raw unavailable markers as message body. For a historical write without snapshot, show a neutral line such as `O conteúdo histórico dessa mensagem não foi armazenado.`.

- [ ] **Step 5: Render decision explanation under progressive disclosure**

Add:

```html
<details class="decision-explanation">
  <summary>Por que o sistema decidiu isso?</summary>
</details>
```

Inside it, use projected facts + `reasonLabel(current_reason)` + rule application presence. Suggested structure:
- `Fatos decisivos`: delivered/refund/receipt/credit/tracking facts actually present;
- `Regra aplicada`: plain-language current reason or `Uma decisão aprendida anteriormente foi aplicada a este caso.`;
- `Conclusão`: `operatorNextStep(c)`;
- `Ainda em acompanhamento`: only facts truly unresolved.

Do not expose similarity signature, raw learned-rule effect JSON or internal reason token.

- [ ] **Step 6: Keep timeline collapsed and audit-complete**

Continue using `condenseTimeline()` for repeated sync/finance observations in the operator view. External writes, Amazon responses, financial reconciliation, human decisions, learned-rule applications and errors are never grouped away. The raw immutable events remain untouched server-side.

- [ ] **Step 7: Run detail regression suite**

```bash
php tests/cockpit-case-explanation-test.php
php tests/cockpit-api-contract-test.php
php tests/cockpit-timeline-test.php
php tests/cockpit-ui-contract-test.php
php tests/cockpit-operational-audit-test.php
node --check admin/amazon-returns/assets/cockpit-operational.js
```

Expected: PASS.

- [ ] **Step 8: Commit detail slice**

```bash
git add admin/amazon-returns/api/case.php includes/amazon-returns/CockpitTimeline.php admin/amazon-returns/assets/cockpit-operational.js admin/amazon-returns/assets/cockpit-operational.css tests/cockpit-api-contract-test.php tests/cockpit-timeline-test.php tests/cockpit-case-explanation-test.php
git diff --cached --check
git commit -m "feat: explain case decisions with messages and evidence"
```

### Task 7: Revisão como pergunta de negócio, sem reabrir decisões já resolvidas

**Files:**
- Modify: `admin/amazon-returns/api/review.php`
- Modify: `admin/amazon-returns/assets/cockpit.js`
- Modify: `admin/amazon-returns/assets/operator-language.js`
- Modify: `admin/amazon-returns/assets/review-focus.js` only if focus/zero-state needs adjustment
- Modify: `tests/review-ui-contract-test.php`
- Modify: `tests/review-open-ui-contract-test.php`
- Modify: `tests/resolved-review-ui-guard-test.php`
- Verify: `tests/review-operations-test.php`

- [ ] **Step 1: Write failing review presentation assertions**

Require the review panel to present this order:
1. `Qual decisão precisa ser tomada?`
2. `O que aconteceu`
3. `O que já foi verificado`
4. `Mensagens com a Amazon`
5. `Impacto financeiro e prazo`
6. `Recomendação`
7. `O que o sistema aprenderá`
8. `Casos semelhantes afetados` before reusable confirmation.

Assert no raw `reason`, `facts` JSON, action enum or internal signature is visible.

- [ ] **Step 2: Run review tests and confirm red**

```bash
php tests/review-ui-contract-test.php
php tests/review-open-ui-contract-test.php
php tests/resolved-review-ui-guard-test.php
```

Expected: review presentation contract FAIL while stale-review guards remain green.

- [ ] **Step 3: Project the review case consistently**

In `api/review.php`, after guarding `status==='OPEN'`, return `SvAmazonReturnProjector::project(...)` for the case instead of an unprojected row. Add computed outstanding amount and the already-safe timeline/message data needed by review. Do not mutate review state during GET.

- [ ] **Step 4: Turn the review reason into one business question**

Add a deterministic `humanReviewQuestion(review,ctx,caseData)` mapper in UI. Examples:
- missing refund initiator → `Quem realizou o reembolso ao cliente?`
- ambiguous Amazon response → `A resposta da Amazon permite continuar a recuperação ou precisamos aguardar?`
- learned rule conflict → `Qual regra deve prevalecer para este tipo de caso?`
- generic fallback → `Qual é a próxima providência segura para este caso?`

The raw reason token never appears.

- [ ] **Step 5: Show recommendation and learning impact safely**

Display action through `actionLabel()`, a concise sanitized rationale through existing operator-language helpers, confidence as qualitative copy, uncertainties only after humanization, and `affected_case_preview` as counts/order references already authorized for the tenant. Do not show canonical signature or effect JSON.

- [ ] **Step 6: Preserve stale-review auto-resolution behavior**

`REVIEW_NOT_OPEN`/`RULE_ALREADY_RESOLVED` still closes the stale panel, refreshes review queue, refreshes summary and tells the user that the case was resolved automatically. Do not recreate a review from the UI.

Run `tests/review-operations-test.php` and confirm reminders still send immediately on a new episode, not before 2 hours, repeat after 2 hours while true reviews remain, and stop/reset when the queue drains.

- [ ] **Step 7: Run review regression suite**

```bash
php tests/review-ui-contract-test.php
php tests/review-open-ui-contract-test.php
php tests/resolved-review-ui-guard-test.php
php tests/review-operations-test.php
php tests/user-ui-language-contract-test.php
```

Expected: PASS.

- [ ] **Step 8: Commit review slice**

```bash
git add admin/amazon-returns/api/review.php admin/amazon-returns/assets/cockpit.js admin/amazon-returns/assets/operator-language.js admin/amazon-returns/assets/review-focus.js tests/review-ui-contract-test.php tests/review-open-ui-contract-test.php tests/resolved-review-ui-guard-test.php
git diff --cached --check
git commit -m "feat: present reviews as clear business decisions"
```

### Task 8: Auditoria de consistência, linguagem e responsividade sobre fixtures adversariais

**Files:**
- Modify: `tests/cockpit-operational-audit-test.php`
- Modify: `tests/user-ui-language-contract-test.php`
- Create: `tests/cockpit-autonomy-consistency-test.php`
- Modify only as validated findings require: files from Tasks 1-7

- [ ] **Step 1: Add adversarial fixtures/contract cases**

Cover at least:
- zero human reviews + nonzero operational issue → `DEGRADED`, not `NORMAL`;
- one human review + operational issue → `USER_ACTION_REQUIRED`;
- R$ 0 outstanding + reconciled credit + pending confirmation → no `saldo ainda a recuperar`;
- R$ 0 outstanding + no evidence of credit → no false `Crédito identificado`;
- overdue appeal with already-sent matching write → no false overdue warning;
- TBR distinct from customer delivery tracking;
- local order search, NF search, TBR search and tracking search all resolve through shared path;
- temporary summary fetch failure + valid cached payload → values stay visible with freshness warning;
- summary fetch failure + no cache → explicit unavailable, never zero;
- raw enum/reason/exception strings cannot reach normal UI text;
- review count zero has compact `Nenhuma ação sua é necessária.` state;
- timeline collapsed by default.

- [ ] **Step 2: Run the audit and confirm any remaining red findings**

```bash
php tests/cockpit-autonomy-consistency-test.php
php tests/cockpit-operational-audit-test.php
php tests/user-ui-language-contract-test.php
```

Expected: any remaining inconsistency fails a specific assertion rather than being accepted as cosmetic.

- [ ] **Step 3: Fix only reproduced inconsistencies**

For each failure, use one surgical patch plus a test demonstrating red→green. Do not broaden into unrelated engine/auth/TOTP refactors.

- [ ] **Step 4: Run all focused UI/API suites together**

```bash
php tests/cockpit-summary-health-test.php
php tests/cockpit-summary-ui-test.php
php tests/case-reference-search-test.php
php tests/intake-reference-search-test.php
php tests/cockpit-case-explanation-test.php
php tests/cockpit-autonomy-consistency-test.php
php tests/cockpit-api-contract-test.php
php tests/cockpit-search-native-pdo-test.php
php tests/cockpit-timeline-test.php
php tests/cockpit-ui-contract-test.php
php tests/cockpit-operational-audit-test.php
php tests/intake-ux-daily-cadence-test.php
php tests/invoice-intake-live-lookup-contract-test.php
php tests/review-ui-contract-test.php
php tests/review-open-ui-contract-test.php
php tests/resolved-review-ui-guard-test.php
php tests/review-operations-test.php
php tests/user-ui-language-contract-test.php
```

Expected: PASS.

- [ ] **Step 5: Commit audit findings**

Stage only validated changes and tests, run `git diff --cached --check`, then:

```bash
git commit -m "test: harden autonomy cockpit consistency"
```

If no implementation change is required after the audit, commit only the new/strengthened tests.

### Task 9: Full CI, independent review, merge, auto-deploy and real production UI acceptance

**Files:**
- Modify only for validated review/production findings.
- Update: `docs/superpowers/specs/2026-09-10-autonomy-cockpit-ux-design.md` status to approved/implemented only after actual completion.
- Update: this plan checkboxes only as execution evidence is completed.

- [ ] **Step 1: Run repository-wide validation locally in the isolated worktree**

Execute the same checks as CI, with fresh output:

```bash
set -e
for test in tests/*.php; do php "$test"; done
php scripts/audit-tenant-sql.php
php tests/amazon-returns-tenant-isolation-test.php
node --test tests/*.test.mjs
python3 tests/totp-current.test.py
python3 -m py_compile scripts/amazon-returns/totp-current.py
find includes api admin workers scripts -name '*.php' -print0 | xargs -0 -n1 php -l
node --check admin/amazon-returns/assets/cockpit-summary.js
node --check admin/amazon-returns/assets/cockpit-operational.js
node --check admin/amazon-returns/assets/cockpit-operational-bootstrap.js
node --check scripts/amazon-returns/safe-t-status-parser.mjs
node --check scripts/amazon-returns/seller-central-auth.mjs
node --check scripts/amazon-returns/seller-central-safe-t-read-worker.mjs
node --check scripts/amazon-returns/seller-central-bridge-worker.mjs
bash -n scripts/install-service.sh
bash -n scripts/auto-deploy.sh
bash -n scripts/provision-production.sh
bash -n scripts/verify-live-tenant-foundation.sh
bash -n scripts/amazon-returns/run-seller-central-daily.sh
bash -n scripts/provision-seller-central-browser-host.sh
bash -n scripts/provision-amazon-totp-authenticator.sh
git diff --check
```

Expected: all PASS. The TOTP tests are regression-only; do not alter TOTP code to make this feature pass.

- [ ] **Step 2: Invoke `superpowers:requesting-code-review`**

Ask an independent reviewer to inspect the complete diff against the approved spec, focusing on:
- contradictory user/system responsibility;
- financial false claims;
- TBR/customer-tracking conflation;
- tenant isolation and request-scoping;
- hidden N+1/full-tenant browser scans;
- stale summary semantics;
- review regression/reopening;
- privacy leakage from Gmail/timeline;
- accessibility and mobile density.

Implement only findings that are reproduced/valid. Every accepted fix gets a focused regression test and the relevant suite is rerun.

- [ ] **Step 3: Publish one validated PR and verify its exact head**

Push the implementation branch, create/update one PR against `main`, and record the PR head SHA. Ensure the approved spec and this plan are included in the same eventual integration so the documentation is not abandoned on a side branch.

Before merge:

```bash
git fetch origin
# compare current PR head with the locally validated commit
```

Check GitHub Actions for that exact SHA. If `main` advanced, rebase/merge safely, rerun affected/full tests and obtain green checks for the new head. Do not merge an older validated SHA after later code changes.

- [ ] **Step 4: Merge the validated head and clear task-owned pending state**

Merge only after required checks/review are green. Confirm:
- PR is merged, not merely closed;
- `main` contains the spec, plan, code and tests;
- no task-owned PR remains open/draft/conflicted;
- no task-owned Action remains failed/pending;
- no unique valid work remains only in a worktree/stash/local branch.

- [ ] **Step 5: Follow the repository auto-deploy gate**

Read `AGENTS-VM-ACCESS.md` before VM work. Use the chat-specific CLI namespace and approved access order. Do not manually copy the release.

Confirm:
- auto-deploy selected the merge SHA (or a later descendant containing it);
- `/home/ubuntu/amazon-returns-deploy/current/.release-sha` matches the actually deployed release;
- `amazon-returns-safet.service` is active;
- deploy journal has no unresolved gate failure;
- health endpoint succeeds;
- no new dead letters or repeated task failures were introduced by the deployment.

If deploy fails, investigate and patch through the repository/PR/CI loop; do not bypass the gate.

- [ ] **Step 6: Perform real rendered UI acceptance in production**

Use the authenticated Opera Browser Connector session at `https://returns.shopvivaliz.com.br/admin/amazon-returns/`. The test evidence must come from actual screenshots/accessibility tree and user-visible transitions, not direct DB/API assertions.

Verify in this order:
1. Top of dashboard lets an operator answer within about 10 seconds: `Preciso fazer algo?`, `O sistema está saudável?`, `Quanto dinheiro ainda está exposto?`.
2. Human-action count and operational-problem count are visibly separate. If operational problems exist while reviews=0, the screen explicitly says the user does not need to act and the system owns the issues.
3. Four primary financial metrics have clear hierarchy; detailed breakdown is secondary.
4. `Sistema tratando agora` shows real active/waiting cases and what happens next.
5. Connector health and last successful cycle/freshness are visible without raw technical status.
6. Search by a real Amazon order returns the expected case.
7. Search by a real NF returns the expected case when evidence exists.
8. Search by a real TBR returns the related case. Prefer an already known real TBR; if none is locally projected, use the on-demand Gmail reconciliation path with a real Amazon TBR message.
9. Search by tracking/SKU/ASIN remains functional.
10. Open a real case and verify order date, customer refund date, seller debit when available, physical receipt when available, financial values, TBR/return tracking, customer delivery tracking, NF and SAFE-T are semantically separated.
11. Verify a case with outstanding R$ 0,00 does not say `saldo ainda a recuperar`; verify it also does not claim identified credit unless positive reconciled-credit evidence exists.
12. `Mensagens com a Amazon` shows persisted outbound narratives and available Amazon response excerpts; missing historical text is labeled unavailable rather than invented.
13. `Por que o sistema decidiu isso?` is collapsed by default and explains facts/rule/next step in plain Portuguese.
14. Full timeline is collapsed by default and repeated polling/sync does not dominate it.
15. `Registrar devolução recebida` finds a real case by order, NF or TBR and displays preview before any save. Do not confirm a fake physical receipt.
16. Reviews tab shows the correct zero state when no true review exists. If a genuine ambiguous review exists, inspect its business-question layout without making a fabricated decision.
17. A deterministic delivered-tracking/refunded-customer pattern does not appear as a human review solely because customer text says not received. If historical orders `702-9784843-4752235` or `702-0707321-6872209` still exist, use them read-only; otherwise use an equivalent real case discovered in the UI.
18. Reload once normally. If a safe way to observe a transient summary refresh failure occurs naturally, verify last-known-good behavior; do not induce a production outage merely to test degraded mode.

Capture screenshots of the dashboard top, one case detail, intake preview, and review/zero-review state as acceptance evidence without exposing secrets.

- [ ] **Step 7: Treat any production UI discrepancy as an open defect**

For each discrepancy found in Step 6:
1. reproduce it in the real UI;
2. add a failing focused regression test where technically applicable;
3. patch surgically;
4. rerun focused + full CI;
5. merge the fix;
6. wait for auto-deploy;
7. repeat the affected real UI step.

Do not mark this plan complete while a reproduced UI contradiction remains.

- [ ] **Step 8: Invoke `superpowers:verification-before-completion`**

Provide the verifier with fresh evidence for:
- full tests/lint/audit;
- final PR and merge SHA;
- deployed release SHA;
- service/deploy health;
- task-owned PR/Action cleanliness;
- final production UI screenshots and acceptance checklist.

Only after verification passes may the spec status be changed from approved design to implemented/validated and the task be described as concluded.

- [ ] **Step 9: Record final delivery evidence**

The final task report records, without secrets:
- initial base SHA;
- implementation branch;
- commits by slice;
- PR number/head SHA;
- merge SHA;
- deployed SHA;
- tests and CI result;
- production validation timestamp;
- exact UI flows verified;
- any real external writes that happened independently because they were legitimate scheduled case actions, clearly separated from test activity.

The success condition is product-level: the seller can see that routine cases continue autonomously, can distinguish system faults from personal decisions, can find returns by the identifiers they actually possess, and can understand each important case without supervising backend machinery.