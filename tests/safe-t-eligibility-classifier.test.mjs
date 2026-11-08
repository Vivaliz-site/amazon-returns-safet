import assert from 'node:assert/strict';
import {test} from 'node:test';
import {classifySafeTEligibility as classify} from '../scripts/amazon-returns/safe-t-eligibility-classifier.mjs';

const orderId='702-2491487-8294621';
test('provider shows one eligible item',()=>{
  assert.deepEqual(classify({orderId,exactOrderChecked:true,itemCount:1,selectableCount:1}),{status:'ELIGIBLE',reason:'SAFE_T_OFFICIAL_ITEM_PRESENT'});
});
test('provider says claim already exists',()=>{
  assert.deepEqual(classify({orderId,exactOrderChecked:true,existingClaim:'64204-51742-6364222'}),{status:'ALREADY_EXISTS',reason:'SAFE_T_EXISTING_CLAIM_FOUND',external_id:'64204-51742-6364222'});
});
test('ambiguous claim ID never treated as absence',()=>{
  assert.equal(classify({orderId,exactOrderChecked:true,existingClaim:'malformed'}).status,'UI_DRIFT');
});
test('only explicit 75-day warning yields official ineligibility',()=>{
  assert.equal(classify({orderId,exactOrderChecked:true,pageText:'Voce excedeu 75 dias',itemCount:0}).status,'NOT_ELIGIBLE');
  assert.equal(classify({orderId,exactOrderChecked:true,pageText:'Nenhum item',itemCount:0}).status,'UI_DRIFT');
});
test('ambiguous quantities require review, no automatic claim',()=>{
  assert.equal(classify({orderId,exactOrderChecked:true,itemCount:2,selectableCount:2}).status,'REVIEW_REQUIRED');
});
test('unconfirmed query and invalid order fail closed',()=>{
  assert.equal(classify({orderId,exactOrderChecked:false,itemCount:1,selectableCount:1}).status,'UI_DRIFT');
  assert.equal(classify({orderId:'BAD',exactOrderChecked:true,itemCount:1,selectableCount:1}).status,'FAILED');
});

test('disabled quantity checkbox cannot be called eligible',()=>{
  assert.deepEqual(classify({orderId,exactOrderChecked:true,itemCount:1,selectableCount:0}),
    {status:'UI_DRIFT',reason:'SAFE_T_ITEM_NOT_SELECTABLE'});
});
