import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import { scanSafeTExactOrder } from '../scripts/amazon-returns/safe-t-discovery-exact-search.mjs';

const order = '111-2222222-3333333';
const claim = '64204-51742-6364222';

test('native UI exact-search is wired into the read-only worker', () => {
  const source=fs.readFileSync(new URL('../scripts/amazon-returns/seller-central-safe-t-read-worker.mjs',import.meta.url),'utf8');
  assert.match(source,/scanSafeTExactOrder\(/);
  assert.match(source,/#list-claims-search-panel-input/);
  assert.match(source,/#list-claims-search-panel-submit/);
  assert.match(source,/SAFE_T_EXACT_SEARCH_CONTROL_UNAVAILABLE/);
});

test('existing claim is detected without SAFE-T submission', async () => {
  let searches=0;
  const result=await scanSafeTExactOrder({orderId:order,
    triggerSearch:async value=>{assert.equal(value,order); searches++; return true;},
    readMatches:async value=>{assert.equal(value,order);return {query_confirmed:true,matches:[claim]};},
    pause:async()=>{}});
  assert.deepEqual(result,{status:'FOUND',external_id:claim,pages_scanned:1});
  assert.equal(searches,1);
});

test('empty native results NEVER prove absence of an external claim', async () => {
  let waits=0;
  const res=await scanSafeTExactOrder({orderId:order,triggerSearch:async()=>true,
    readMatches:async()=>({query_confirmed:true,matches:[],no_results_visible:true}),
    pause:async()=>{waits++},maxChecks:3});
  assert.deepEqual(res,{status:'UI_DRIFT',reason:'SAFE_T_EXACT_SEARCH_ABSENCE_UNVERIFIED',pages_scanned:1});
  assert.equal(waits,2);
});

test('missing native control fails closed without searching',async()=>{
  let calls=0;
  const res=await scanSafeTExactOrder({orderId:order,triggerSearch:async()=>false,
    readMatches:async()=>{calls++;return {};},pause:async()=>{}});
  assert.equal(res.reason,'SAFE_T_EXACT_SEARCH_CONTROL_UNAVAILABLE');
  assert.equal(res.status,'UI_DRIFT');
  assert.equal(calls,0);
});

test('ambiguous and malformed IDs never turn into a claim',async()=>{
  for(const [matches,reason] of [
    [[claim,'11111-22222-3333333'],'MULTIPLE_SAFE_T_CLAIMS_FOR_ORDER'],
    [['invalid'],'SAFE_T_EXACT_SEARCH_ID_UNREADABLE'],
  ]){
    const res=await scanSafeTExactOrder({orderId:order,triggerSearch:async()=>true,
      readMatches:async()=>({query_confirmed:true,matches}),pause:async()=>{}});
    assert.equal(res.reason,reason);
    assert.notEqual(res.status,'FOUND');
  }
});

test('loading before an eventual positive result is handled with bounded reads',async()=>{
  let calls=0;
  const res=await scanSafeTExactOrder({orderId:order,triggerSearch:async()=>true,
    readMatches:async()=>++calls===3?{query_confirmed:true,matches:[claim]}:{query_confirmed:false,matches:[claim]},
    pause:async()=>{},maxChecks:5});
  assert.equal(res.status,'FOUND');
  assert.equal(calls,3);
});

test('invalid order never touches provider and maxChecks is bounded',async()=>{
  let called=false;
  const res=await scanSafeTExactOrder({orderId:'invalid',triggerSearch:async()=>{called=true;return true;},readMatches:async()=>{}});
  assert.equal(res.status,'FAILED');assert.equal(called,false);
  await assert.rejects(scanSafeTExactOrder({orderId:order,triggerSearch:async()=>true,readMatches:async()=>({query_confirmed:false,matches:[]}),maxChecks:26}),RangeError);
});
