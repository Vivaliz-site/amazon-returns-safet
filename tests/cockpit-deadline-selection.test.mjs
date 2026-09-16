import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync(new URL('../admin/amazon-returns/assets/cockpit-operational.js',import.meta.url),'utf8');
const line=source.split('\n').find(value=>value.startsWith('function operatorNextRelevantDate('));
assert.ok(line,'operatorNextRelevantDate helper is required');
const context={};vm.createContext(context);vm.runInContext(line,context);

test('submitted and approved claims do not reuse opening eligibility as a pending deadline',()=>{
  assert.equal(context.operatorNextRelevantDate({state:'SAFE_T_SUBMITTED',current_action:'WAIT',eligibility_at:'2026-09-01 00:00:00'}),null);
  assert.equal(context.operatorNextRelevantDate({state:'SAFE_T_APPROVED',current_action:'CHECK_FINANCES',eligibility_at:'2026-09-01 00:00:00'}),null);
});

test('pending opening and appeal dates remain visible',()=>{
  assert.equal(context.operatorNextRelevantDate({state:'SAFE_T_READY',current_action:'SAFE_T_SUBMIT',eligibility_at:'2026-09-20 00:00:00'}),'2026-09-20 00:00:00');
  assert.equal(context.operatorNextRelevantDate({state:'APPEAL_REQUIRED',current_action:'SAFE_T_APPEAL',appeal_deadline_at:'2026-09-22 00:00:00'}),'2026-09-22 00:00:00');
  assert.equal(context.operatorNextRelevantDate({state:'REFUND_DETECTED',current_action:'WAIT',current_reason:'NOT_YET_ELIGIBLE',eligibility_at:'2026-09-25 00:00:00'}),'2026-09-25 00:00:00');
});

test('explicit next action date wins after an external action',()=>{
  assert.equal(context.operatorNextRelevantDate({state:'SAFE_T_SUBMITTED',current_action:'WAIT',eligibility_at:'2026-09-01 00:00:00',next_action_at:'2026-09-19 00:00:00'}),'2026-09-19 00:00:00');
});