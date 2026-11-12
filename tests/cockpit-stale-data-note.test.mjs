import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source=readFileSync(new URL('../admin/amazon-returns/assets/cockpit-operational.js',import.meta.url),'utf8');
const code=source.slice(0,source.indexOf('function renderOperationalList('));
const context={brl:n=>String(n),date:x=>String(x),stateLabel:x=>x};
vm.createContext(context);
vm.runInContext(code,context);

const NOW=Date.parse('2026-11-12T02:00:00Z');
const hoursAgo=h=>new Date(NOW-h*3600*1000).toISOString().slice(0,19).replace('T',' ');

test('an active case read more than 36 hours ago is flagged stale',()=>{
  assert.equal(context.operatorDataIsStale({state:'SAFE_T_SUBMITTED',last_read_back:{occurred_at:hoursAgo(37)}},NOW),true);
});
test('an active case read within 36 hours is not stale',()=>{
  assert.equal(context.operatorDataIsStale({state:'SAFE_T_SUBMITTED',last_read_back:{occurred_at:hoursAgo(35)}},NOW),false);
});
test('terminal cases never expect a new read so they are never flagged stale',()=>{
  for(const state of ['CLOSED_LOSS','RECOVERED','APPEAL_DENIED_FINAL']){
    assert.equal(context.operatorDataIsStale({state,last_read_back:{occurred_at:hoursAgo(500)}},NOW),false,state);
  }
  assert.equal(context.operatorDataIsStale({state:'AWAITING_RETURN',closed_at:'2026-11-12 00:54:14',last_read_back:{occurred_at:hoursAgo(500)}},NOW),false);
});
test('missing or malformed read timestamps never raise a stale warning',()=>{
  assert.equal(context.operatorDataIsStale({state:'SAFE_T_SUBMITTED'},NOW),false);
  assert.equal(context.operatorDataIsStale({state:'SAFE_T_SUBMITTED',last_read_back:{occurred_at:'garbage'}},NOW),false);
  assert.equal(context.operatorDataIsStale(null,NOW),false);
});
