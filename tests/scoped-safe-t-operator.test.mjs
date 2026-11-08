import test from 'node:test';
import assert from 'node:assert/strict';
import { activateScopedSafeTOpening, ScopedSafeTError, SCOPES, EXPECTED_ACTIONS } from '../scripts/amazon-returns/scoped-safe-t-operator.mjs';

const NOW = Date.parse('2026-11-07T12:00:00Z');
const CLAIM = '12345-12345-1234567';
const grant = n => ({
  verifiedBy: 'canonical-github-owner-approval-verifier', owner: 'shopvivaliz-human',
  approvedOnHead: true, merged: true, grantNumber: n,
  grant: { operation: 'activate_scoped_safe_t_opening', resource: 'amazon-returns:safet:marina',
    principal: 'amazon-returns-safet-service', host: 'marina-a1-4c24g', tenant_id: 1,
    amazon_connection_id: 1, one_use: true, allowed_case_ids: [...SCOPES[n].caseIds],
    expires_at_utc: SCOPES[n].expiresAt, allowed_write_actions: ['SAFE_T_SUBMIT'] }
});
const row = id => ({ id, refund_at: '2026-09-18 08:00:00', program: 'FBA_ONSITE',
  refund_initiator: 'AMAZON_INITIATED', physical_status: 'NOT_RECEIVED', quantity_received: 0,
  refund_amount: '50.94', reconciled_credit_amount: '0.00', safe_t_id: null, support_case_id: null });
function build(overrides = {}) {
  const calls = [], state = { consumed: 0, attempted: 0, restored: 0, released: 0 };
  const adapter = {
    async verifyCanonicalOwnerGrant(n) { calls.push('grant'); return grant(n); },
    async verifyServicePrincipal() { calls.push('principal'); return true; },
    async verifyCrashRollbackProvisioned() { calls.push('crash-rollback'); return true; },
    async verifyScopedWorkerIsolation() { calls.push('worker-isolation'); return true; },
    async acquireSingleWriterLease() { calls.push('lease'); return {id:'owned',fencing:9}; },
    async readCaseSnapshot({caseIds}) { calls.push('cases'); return caseIds.map(row); },
    async verifyWarehouseNonreceipt() { calls.push('warehouse'); return true; },
    async verifyFinancesDebit() { calls.push('finances'); return true; },
    async verifyAmazonInitiated() { calls.push('initiator'); return true; },
    async verifyOfficialEligibility() { calls.push('amazon-eligibility'); return true; },
    async verifyNoExternalClaimOrSupport() { calls.push('no-claim'); return true; },
    async consumeOneUseGrant() { calls.push('consume'); state.consumed++; return true; },
    async executeViaExistingScopedWorker() { calls.push('execute'); state.attempted++; return {status:'ACCEPTED',externalId:CLAIM}; },
    async verifyAcceptedClaim() { calls.push('readback'); return true; },
    async restoreGlobalKillSwitch() { calls.push('restore'); state.restored++; },
    async verifyGlobalWritesDisabled() { calls.push('writes-off'); return true; },
    async releaseSingleWriterLease() { calls.push('release'); state.released++; },
    ...overrides,
  };
  return { calls, state, adapter };
}
const run = (adapter,n=3556,time=NOW) => activateScopedSafeTOpening({grantNumber:n,adapter,now:()=>time});
const denies = async (fn,code) => assert.rejects(fn, e=>e instanceof ScopedSafeTError && e.code===code);

test('immutable scope has one enabled action and two separate canaries',()=>{
  assert.deepEqual(Object.values(EXPECTED_ACTIONS).filter(Boolean),[true]);
  assert.equal(SCOPES[3556].caseIds.length,8);assert.equal(SCOPES[3673].caseIds.length,5);
  assert(!SCOPES[3556].caseIds.includes(42471));assert(SCOPES[3673].caseIds.includes(42471));
});
test('never calls a writer without an installed trusted adapter',async()=>{
  await denies(()=>run({}), 'CERTIFIED_ADAPTER_REQUIRED');
  await denies(()=>activateScopedSafeTOpening({grantNumber:9999,adapter:build().adapter}), 'UNKNOWN_GRANT');
});
test('real gate: trusted approval on the HEAD and official merge are required',async()=>{
  for (const changed of [{merged:false},{approvedOnHead:false},{owner:'other'}]){
    const b=build({verifyCanonicalOwnerGrant:async n=>({...grant(n),...changed})});
    await denies(()=>run(b.adapter), 'OWNER_GRANT_NOT_VERIFIED');
    assert.equal(b.state.attempted,0);assert.equal(b.state.consumed,0);
  }
});
test('no forged/mixed canary, host or action',async()=>{
  for(const change of [
    g=>{g.allowed_case_ids=[...SCOPES[3556].caseIds,42471]},
    g=>{g.allowed_case_ids=SCOPES[3556].caseIds.slice(1)},
    g=>{g.allowed_case_ids[1]=g.allowed_case_ids[0]},
    g=>{g.host='excluded-host'},
    g=>{g.allowed_write_actions.push('SAFE_T_APPEAL')},
  ]){
    const b=build({verifyCanonicalOwnerGrant:async n=>{const s=grant(n);change(s.grant);return s}});
    await denies(()=>run(b.adapter),'OWNER_GRANT_NOT_VERIFIED');
    assert.equal(b.state.attempted,0);
  }
});
test('expiry is exclusive and never bypassed by starting after deadline',async()=>{
  const b=build();await denies(()=>run(b.adapter,3556,Date.parse(SCOPES[3556].expiresAt)),'OWNER_GRANT_EXPIRED');
  assert.equal(b.state.attempted,0);
});
test('service principal mismatch and global switch OFF are both fatal',async()=>{
  for (const [method,code] of [['verifyServicePrincipal','SERVICE_PRINCIPAL_UNVERIFIED'],
    ['verifyGlobalWritesDisabled','GLOBAL_WRITES_NOT_DISABLED']]){
    const b=build({[method]:async()=>false});await denies(()=>run(b.adapter),code);
    assert.equal(b.state.consumed,0);assert.equal(b.state.attempted,0);
  }
});
test('lease must be single-writer, durable and fenced',async()=>{
  const b=build({acquireSingleWriterLease:async()=>({id:'wrong',fencing:0})});
  await denies(()=>run(b.adapter),'EXCLUSIVE_LEASE_REQUIRED');assert.equal(b.state.attempted,0);
});
test('incomplete/mismatched canary snapshot denies before any one-use consume',async()=>{
  const b=build({readCaseSnapshot:async ({caseIds})=>caseIds.slice(1).map(row)});
  await denies(()=>run(b.adapter),'CANARY_SNAPSHOT_MISMATCH');
  assert.equal(b.state.attempted,0);assert.equal(b.state.consumed,0);
  assert.equal(b.state.restored,1);assert.equal(b.state.released,1);
});
test('UNKNOWN initiator and existing external claim must be skipped, not submitted',async()=>{
  const b=build({readCaseSnapshot:async ({caseIds})=>caseIds.map(id=>{
    const c=row(id);if(id===1314)c.refund_initiator='UNKNOWN';if(id===8803)c.safe_t_id='64204-51742-6364222';return c;
  })});
  const result=await run(b.adapter);
  assert.equal(result.status,'ACCEPTED_WITH_READBACK');
  assert.deepEqual(result.verdicts.filter(v=>v.status==='SKIPPED').map(v=>v.reason).sort(),
    ['AMAZON_INITIATOR_UNVERIFIED','EXISTING_SAFE_T']);
  assert.equal(b.state.attempted,6);assert.equal(b.state.restored,1);assert.equal(b.state.released,1);
});
test('missing official Amazon proof never consumes grant or submits',async()=>{
  const b=build({verifyNoExternalClaimOrSupport:async()=>false});
  const result=await run(b.adapter);assert.equal(result.status,'NO_AUTHORIZED_SUBMISSIONS');
  assert.equal(b.state.attempted,0);assert.equal(b.state.consumed,0);
  assert.equal(b.state.restored,1);assert.equal(b.state.released,1);
});
test('one-use consumption must succeed before any provider action',async()=>{
  const b=build({consumeOneUseGrant:async()=>false});
  await denies(()=>run(b.adapter),'GRANT_ALREADY_CONSUMED');
  assert.equal(b.state.attempted,0);assert.equal(b.state.restored,1);assert.equal(b.state.released,1);
});
test('provider click without accepted ID fails closed and never retries automatically',async()=>{
  const b=build({executeViaExistingScopedWorker:async()=>({status:'ACCEPTED',externalId:''})});
  await denies(()=>run(b.adapter),'SUBMIT_READBACK_UNVERIFIED');
  assert.equal(b.state.attempted,0);assert.equal(b.state.restored,1);assert.equal(b.state.released,1);
});
test('external readback must independently confirm accepted ID',async()=>{
  const b=build({verifyAcceptedClaim:async()=>false});
  await denies(()=>run(b.adapter),'EXTERNAL_CLAIM_NOT_CONFIRMED');
  assert.equal(b.state.restored,1);assert.equal(b.state.released,1);
});
test('restores global kill switch and releases lease after failure in provider',async()=>{
  const b=build({executeViaExistingScopedWorker:async()=>{throw new Error('provider unavailable')}});
  await assert.rejects(()=>run(b.adapter),/provider unavailable/);
  assert.equal(b.state.restored,1);assert.equal(b.state.released,1);
});
test('unverified kill-switch restore quarantines ownership instead of releasing lease',async()=>{
  const b=build();
  b.adapter.verifyGlobalWritesDisabled=async()=>b.state.restored===0;
  await denies(()=>run(b.adapter),'KILL_SWITCH_RESTORE_UNVERIFIED');
  assert.equal(b.state.restored,1);assert.equal(b.state.released,0);
});
test('throw from restore path also keeps the exclusive lease quarantined',async()=>{
  const b=build({restoreGlobalKillSwitch:async()=>{throw new Error('restore failed')}});
  await denies(()=>run(b.adapter),'KILL_SWITCH_RESTORE_UNVERIFIED');
  assert.equal(b.state.released,0);
});
test('preflight blocks stale dates, credits, received goods, wrong programs and support',async()=>{
  const invalid = [
    ['refund_at','2026-11-06 01:00:00'],['refund_at','2026-07-01 01:00:00'],
    ['refund_at','invalid'],['refund_initiator','UNKNOWN'],['program','STANDARD'],
    ['physical_status','PHYSICAL_RECEIVED'],['quantity_received',1],
    ['refund_amount',0],['reconciled_credit_amount',100],['support_case_id','support-ref'],
  ];
  for(const [key,value]of invalid){
    const b=build({readCaseSnapshot:async ({caseIds})=>caseIds.map(id=>({...row(id),[key]:value}))});
    const r=await run(b.adapter);
    assert.equal(r.status,'NO_AUTHORIZED_SUBMISSIONS',key);
    assert.equal(b.state.attempted,0,key);
    assert.equal(b.state.consumed,0,key);
  }
});

test('crash rollback and existing-worker isolation are mandatory before lease',async()=>{
  for(const [method,code] of [['verifyCrashRollbackProvisioned','CRASH_ROLLBACK_NOT_PROVISIONED'],
    ['verifyScopedWorkerIsolation','SINGLE_WORKER_ISOLATION_UNVERIFIED']]){
    const b=build({[method]:async()=>false});
    await denies(()=>run(b.adapter),code);
    assert.equal(b.state.attempted,0);
  }
});
test('fresh local snapshot and official proof rechecked before each irreversible submit',async()=>{
  let reads=0;
  const b=build({readCaseSnapshot:async ({caseIds})=>{
    reads++;
    return caseIds.map(id=>reads===2?{...row(id),safe_t_id:'64204-51742-6364222'}:row(id));
  }});
  await denies(()=>run(b.adapter),'CASE_CHANGED_BEFORE_SUBMIT');
  assert.equal(b.state.attempted,0);
  assert.equal(b.state.restored,1);
});
test('official proof withdrawal between preflight and submission blocks click',async()=>{
  let calls=0;
  const b=build({verifyNoExternalClaimOrSupport:async()=>{
    calls++;
    return calls<=SCOPES[3556].caseIds.length;
  }});
  await denies(()=>run(b.adapter),'OFFICIAL_PROOF_STALE');
  assert.equal(b.state.attempted,0);
  assert.equal(b.state.restored,1);
});

test('carrier-only transit states at D+45 reach independent warehouse and Amazon gates', async () => {
  for (const physical_status of ['IN_TRANSIT', 'CARRIER_DELIVERED_PENDING_PHYSICAL']) {
    const snapshot = ({caseIds}) => caseIds.map(id => ({...row(id), physical_status}));
    const unresolved = build({
      readCaseSnapshot: async snapshot,
      verifyWarehouseNonreceipt: async () => false,
    });
    const denied = await run(unresolved.adapter);
    assert.equal(denied.status, 'NO_AUTHORIZED_SUBMISSIONS', physical_status);
    assert(unresolved.calls.includes('warehouse'), 'independent WAREHOUSE check must run');
    assert.equal(unresolved.state.consumed, 0, 'carrier scan alone cannot consume grant');
    assert.equal(unresolved.state.attempted, 0, 'carrier scan alone cannot submit');
    const confirmed = build({readCaseSnapshot: async snapshot});
    const result = await run(confirmed.adapter);
    assert.equal(result.status, 'ACCEPTED_WITH_READBACK', physical_status);
    assert.equal(result.results.length, SCOPES[3556].caseIds.length);
    assert(confirmed.calls.includes('warehouse'));
    assert(confirmed.calls.includes('amazon-eligibility'));
  }
});

test('unknown intake status and any positive received quantity remain fail-closed', async () => {
  for (const [physical_status, quantity_received] of [
    ['UNKNOWN', 0], ['PHYSICAL_RECEIVED', 0], ['RECEIVED_OK', 0],
    ['RECEIVED_DISCREPANT', 0], ['IN_TRANSIT', 1],
  ]) {
    const b = build({readCaseSnapshot: async ({caseIds}) =>
      caseIds.map(id => ({...row(id), physical_status, quantity_received}))});
    const result = await run(b.adapter);
    assert.equal(result.status, 'NO_AUTHORIZED_SUBMISSIONS', physical_status);
    assert.equal(b.state.attempted, 0);
    assert.equal(b.state.consumed, 0);
  }
});
