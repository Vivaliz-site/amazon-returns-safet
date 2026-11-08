import test from 'node:test';
import assert from 'node:assert/strict';
import {
  D45_AUTO_SCOPE, SAFE_T_ONLY, D45AutoActivationError,
  assessD45Candidate, verifyD45AutoGrant, activateD45AutomaticSafeT,
} from '../scripts/amazon-returns/d45-safe-t-auto-activation.mjs';

const NOW=Date.parse('2026-11-08T08:00:00Z');
const CLAIM='12345-12345-1234567';
const age45=()=>new Date(NOW-45*86400000).toISOString().slice(0,19).replace('T',' ');
const row=(id=1263,overrides={})=>({
  id,tenant_id:1,amazon_connection_id:1,amazon_order_id:'702-2491487-8294621',
  refund_at:age45(),refund_initiator:'AMAZON_INITIATED',
  program:'DELIVERY_BY_AMAZON',physical_status:'NOT_RECEIVED',quantity_received:0,
  refund_amount:'36.38',reconciled_credit_amount:'0.00',safe_t_id:null,support_case_id:null,
  ...overrides,
});
const grant=(overrides={})=>({
  verifiedBy:'canonical-github-owner-approval-verifier',owner:'shopvivaliz-human',
  approvedOnHead:true,merged:true,grantNumber:3830,
  grant:{
    schema:'SHOPVIVALIZ_OWNER_PR_GRANT_V1',repository:'Vivaliz-site/site-shopvivaliz',
    pr_number:3830,operation:'activate_d45_safe_t_automation',
    resource:'amazon-returns:safet:marina',principal:'amazon-returns-safet-service',
    host:'marina-a1-4c24g',target_repository:'Vivaliz-site/amazon-returns-safet',
    tenant_id:1,amazon_connection_id:1,one_use:true,automatic_future_cases:true,
    min_days_since_refund:45,max_days_since_refund_exclusive:90,
    max_new_claims_total:20,max_new_claims_per_utc_day:5,
    expires_at_utc:'2026-11-30T23:59:00Z',capabilities:['safe_t_submit'],
    allowed_write_actions:['SAFE_T_SUBMIT'],
    other_write_actions_denied:Object.keys(SAFE_T_ONLY).filter(k=>k!=='SAFE_T_SUBMIT'),
    excluded_case_ids:[8803],activation_requires_certified_production_adapter:true,
  },
  ...overrides,
});
function rig(overrides={}){
  const calls=[];const state={submitted:0,quarantined:0,activation:0,reserved:0,restored:0,released:0};
  const adapter={
    async verifyCanonicalOwnerGrant(){calls.push('grant');return grant();},
    async verifyRestrictedPrincipal(){calls.push('principal');return true;},
    async verifyCrashRollbackWatchdog(){calls.push('watchdog');return true;},
    async verifyExistingWorkerIsolation(){calls.push('worker');return true;},
    async verifyGlobalWritesDisabled(){calls.push('switch');return true;},
    async acquireExclusiveWriterLease(){calls.push('lease');return {id:'owner',fencing:7};},
    async listCandidateCases(){calls.push('list');return [row()];},
    async readCase(){calls.push('reread');return row();},
    async verifyWarehouseNonreceipt(){calls.push('warehouse');return true;},
    async verifyFinancesUnrecoveredDebit(){calls.push('finances');return true;},
    async verifyAmazonRefundInitiator(){calls.push('initiator');return true;},
    async verifyNoExternalClaimOrSupport(){calls.push('duplicate');return true;},
    async readOfficialEligibility(args){calls.push('official');return {status:'ELIGIBLE',orderId:args.orderId,verifiedBy:'authenticated-seller-central'};},
    async recordOfficialWait(){calls.push('wait');},
    async verifyOrActivateOneUseLedger(){calls.push('activation');state.activation++;return {id:'persisted-grant-3830',grantNumber:3830,status:'ACTIVE',principal:'amazon-returns-safet-service'};},
    async reserveDurableSubmissionAttempt(){calls.push('reserve');state.reserved++;return {status:'RESERVED',id:'one-use-order-1263'};},
    async submitThroughExistingWorker(){calls.push('submit');state.submitted++;return {status:'ACCEPTED',externalId:CLAIM};},
    async verifyExternalClaimReadback(){calls.push('readback');return true;},
    async quarantineIndeterminateAttempt(){calls.push('quarantine');state.quarantined++;},
    async restoreGlobalKillSwitch(){calls.push('restore');state.restored++;},
    async releaseExclusiveWriterLease(){calls.push('release');state.released++;},
    ...overrides,
  };
  return {calls,state,adapter};
}
async function run(adapter, time=NOW){return activateD45AutomaticSafeT({adapter,now:()=>time});}
function denied(code){return e=>e instanceof D45AutoActivationError&&e.code===code;}

test('D+45 exact first attempt, no internal D+60 substitution, D+90 terminal',()=>{
  assert.equal(assessD45Candidate(row(),NOW),null);
  assert.equal(assessD45Candidate(row(1263,{refund_at:new Date(NOW-45*86400000+1000).toISOString().slice(0,19).replace('T',' ')}),NOW),'BEFORE_D45');
  assert.equal(assessD45Candidate(row(1263,{refund_at:new Date(NOW-89*86400000).toISOString().slice(0,19).replace('T',' ')}),NOW),null);
  assert.equal(assessD45Candidate(row(1263,{refund_at:new Date(NOW-90*86400000).toISOString().slice(0,19).replace('T',' ')}),NOW),'ON_OR_AFTER_D90');
});
test('only SAFE_T_SUBMIT can be enabled, #8803 permanently excluded',()=>{
  assert.deepEqual(Object.entries(SAFE_T_ONLY).filter(([,v])=>v).map(([k])=>k),['SAFE_T_SUBMIT']);
  assert.equal(assessD45Candidate(row(8803),NOW),'EXCLUDED_EXISTING_CLAIM');
  assert.equal(assessD45Candidate(row(42471),NOW),null);
});
test('refuse wrong tenant/order, existing claim/support, unknown initiator, full credit and physical receipt',()=>{
  const variants=[
    ['tenant_id',2,'WRONG_TENANT_OR_CONNECTION'],
    ['amazon_order_id','wrong','ORDER_ID_UNVERIFIED'],
    ['safe_t_id','64204-51742-6364222','EXISTING_CLAIM_OR_SUPPORT'],
    ['support_case_id','123456789','EXISTING_CLAIM_OR_SUPPORT'],
    ['refund_initiator','UNKNOWN','REFUND_INITIATOR_UNVERIFIED'],
    ['program','FBA','PROGRAM_REQUIRES_REVIEW'],
    ['physical_status','PHYSICAL_RECEIVED','WAREHOUSE_RECEIPT_UNVERIFIED'],
    ['physical_status','RECEIVED_DISCREPANT','PROGRAM_REQUIRES_REVIEW'],
    ['quantity_received',1,'WAREHOUSE_RECEIPT_UNVERIFIED'],
    ['refund_amount','0','UNRECOVERED_DEBIT_UNVERIFIED'],
    ['reconciled_credit_amount','36.38','UNRECOVERED_DEBIT_UNVERIFIED'],
  ];
  for(const [key,value,expected] of variants)
    assert.equal(assessD45Candidate(row(1263,{[key]:value}),NOW),expected,key);
});
test('unapproved, wrong grant or expired grant fail before any lease and external work',async()=>{
  const changes=[
    [{approvedOnHead:false},'OWNER_D45_GRANT_UNVERIFIED'],
    [{owner:'fredmourao-ai'},'OWNER_D45_GRANT_UNVERIFIED'],
    [{merged:false},'OWNER_D45_GRANT_UNVERIFIED'],
    [{grantNumber:3556},'OWNER_D45_GRANT_UNVERIFIED'],
    [{grant:{...grant().grant,max_new_claims_total:21}},'OWNER_D45_GRANT_UNVERIFIED'],
    [{grant:{...grant().grant,allowed_write_actions:['SAFE_T_SUBMIT','SAFE_T_APPEAL']}},'OWNER_D45_GRANT_UNVERIFIED'],
  ];
  for(const [change,code] of changes){const b=rig({verifyCanonicalOwnerGrant:async()=>grant(change)});await assert.rejects(()=>run(b.adapter),denied(code));assert.equal(b.state.submitted,0);assert(!b.calls.includes('lease'));}
  const b=rig();await assert.rejects(()=>run(b.adapter,Date.parse(D45_AUTO_SCOPE.expiresAt)),denied('OWNER_D45_GRANT_EXPIRED'));
  assert.equal(b.state.submitted,0);
});
test('refuse incomplete production adapter, inactive principal or missing watchdog',async()=>{
  await assert.rejects(()=>run({}),denied('CERTIFIED_PRODUCTION_ADAPTER_REQUIRED'));
  for(const [field,code] of [['verifyRestrictedPrincipal','RESTRICTED_PRINCIPAL_NOT_ACTIVE'],['verifyCrashRollbackWatchdog','CRASH_WATCHDOG_UNVERIFIED'],['verifyExistingWorkerIsolation','WORKER_SINGLE_WRITER_UNVERIFIED']]){
    const b=rig({[field]:async()=>false});await assert.rejects(()=>run(b.adapter),denied(code));assert.equal(b.state.submitted,0);assert.equal(b.state.restored,0);
  }
});
test('happy path claims only after atomic durable attempt and independent official readback',async()=>{
  const b=rig();const result=await run(b.adapter);
  assert.equal(result.status,'ACCEPTED_WITH_READBACK');assert.deepEqual(result.submitted,[{caseId:1263,externalId:CLAIM}]);
  assert.equal(b.state.activation,1);assert.equal(b.state.reserved,1);assert.equal(b.state.submitted,1);
  assert(b.calls.indexOf('reserve')<b.calls.indexOf('submit'));assert(b.calls.indexOf('submit')<b.calls.indexOf('readback'));
  assert.equal(b.state.restored,1);assert.equal(b.state.released,1);
});
test('provider requests D+60: save exact official wait, do not file early',async()=>{
  const b=rig({readOfficialEligibility:async x=>({status:'BLOCKED_UNTIL',nextAllowedAt:'2026-11-23T00:00:00Z',orderId:x.orderId})});
  const r=await run(b.adapter);assert.equal(r.status,'NO_CERTIFIED_PROVIDER_ELIGIBILITY');
  assert.equal(r.verdicts[0].status,'WAIT_PROVIDER');assert.equal(b.state.submitted,0);
  assert.equal(b.state.activation,0);assert(b.calls.includes('wait'));
});
test('unverified provider eligibility, a list-only NOT_FOUND, or wrong order can never file',async()=>{
  for(const result of [{status:'UI_DRIFT'},{status:'ALREADY_EXISTS'},
    {status:'ELIGIBLE',orderId:'701-1111111-2222222',verifiedBy:'authenticated-seller-central'},
    {status:'ELIGIBLE',orderId:row().amazon_order_id,verifiedBy:'unmanaged-screenshot'}]){
    const b=rig({readOfficialEligibility:async()=>result});
    const out=await run(b.adapter);assert.equal(out.status,'NO_CERTIFIED_PROVIDER_ELIGIBILITY');
    assert.equal(b.state.submitted,0);assert.equal(b.state.activation,0);
  }
});
test('no external proof prevents activation and any submission',async()=>{
  const b=rig({verifyFinancesUnrecoveredDebit:async()=>false});
  const r=await run(b.adapter);assert.equal(r.status,'NO_CERTIFIED_PROVIDER_ELIGIBILITY');
  assert.equal(b.state.submitted,0);assert.equal(b.state.activation,0);
});
test('post-list local state change and stale external proof deny irreversible click',async()=>{
  for(const changes of [
    {readCase:async()=>row(1263,{safe_t_id:CLAIM})},
    {verifyNoExternalClaimOrSupport:(()=>{let n=0;return async()=>++n===1;})()},
  ]){
    const b=rig(changes);await assert.rejects(()=>run(b.adapter),denied(changes.readCase?'CASE_CHANGED_BEFORE_SUBMIT':'EXTERNAL_PROOF_STALE'));
    assert.equal(b.state.submitted,0);assert.equal(b.state.restored,1);
  }
});
test('daily/total capacity and existing attempt are checked atomically in adapter before click',async()=>{
  for(const status of ['NO_CAPACITY','ALREADY_ATTEMPTED']){
    const b=rig({reserveDurableSubmissionAttempt:async params=>{
      assert.equal(params.maxPerUtcDay,5);assert.equal(params.maxTotal,20);
      assert.equal(params.dayUtc,'2026-11-08');return {status};}});
    const result=await run(b.adapter);assert.equal(result.status,'NO_NEW_SUBMISSIONS');
    assert.equal(b.state.submitted,0);assert.equal(b.state.restored,1);
  }
});
test('ambiguous provider result quarantines durable attempt; never retries or claims success',async()=>{
  const b=rig({submitThroughExistingWorker:async()=>({status:'UNKNOWN',externalId:null})});
  await assert.rejects(()=>run(b.adapter),denied('SAFE_T_EXTERNAL_ID_UNVERIFIED'));
  assert.equal(b.state.quarantined,1);assert.equal(b.state.restored,1);assert.equal(b.state.released,1);
});
test('false independent readback quarantines and prevents claims of accepted result',async()=>{
  const b=rig({verifyExternalClaimReadback:async()=>false});
  await assert.rejects(()=>run(b.adapter),denied('SAFE_T_PROVIDER_READBACK_UNVERIFIED'));
  assert.equal(b.state.quarantined,1);assert.equal(b.state.restored,1);
});
test('kill-switch restoration not confirmed -> retain lease instead of admitting another writer',async()=>{
  const b=rig({verifyGlobalWritesDisabled:(()=>{let n=0;return async()=>++n!==3;})()});
  await assert.rejects(()=>run(b.adapter),denied('KILL_SWITCH_RESTORE_UNVERIFIED'));
  assert.equal(b.state.restored,1);assert.equal(b.state.released,0);
});
test('invalid candidate batch duplicate IDs must be rejected without reservation',async()=>{
  const b=rig({listCandidateCases:async()=>[row(),row()]});
  await assert.rejects(()=>run(b.adapter),denied('CANDIDATE_BATCH_UNVERIFIED'));
  assert.equal(b.state.reserved,0);assert.equal(b.state.restored,1);
});
test('five previously attempted cases cannot starve the next eligible case',async()=>{
  const b=rig({
    listCandidateCases:async()=>Array.from({length:6},(_,i)=>row(1263+i)),
    readCase:async({caseId})=>row(caseId),
    reserveDurableSubmissionAttempt:async({caseId})=>caseId===1268?
      {status:'RESERVED',id:'last-eligible'}:{status:'ALREADY_ATTEMPTED'},
  });
  const result=await run(b.adapter);
  assert.equal(result.status,'ACCEPTED_WITH_READBACK');
  assert.deepEqual(result.submitted.map(x=>x.caseId),[1268]);
  assert.equal(b.state.submitted,1);
  assert.equal(b.state.restored,1);
  assert.equal(b.state.released,1);
});

test('carrier transit and pending-delivery states do not delay D+45 official check',async()=>{
  for(const status of ['IN_TRANSIT','CARRIER_DELIVERED_PENDING_PHYSICAL','RETURNING_TO_SELLER','UNKNOWN']){
    assert.equal(assessD45Candidate(row(1263,{physical_status:status}),NOW),null,status);
    const b=rig({
      listCandidateCases:async()=>[row(1263,{physical_status:status})],
      readCase:async()=>row(1263,{physical_status:status}),
      verifyWarehouseNonreceipt:async()=>false,
    });
    const result=await run(b.adapter);
    assert.equal(result.status,'NO_CERTIFIED_PROVIDER_ELIGIBILITY');
    assert.equal(b.state.submitted,0);
  }
});
