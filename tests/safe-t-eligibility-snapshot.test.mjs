import assert from 'node:assert/strict';
import vm from 'node:vm';
import {test} from 'node:test';
import {safeTEligibilitySnapshot} from '../scripts/amazon-returns/safe-t-eligibility-snapshot.mjs';

const orderId='702-2491487-8294621';
function candidateDocument({orderText='',items=[],claim=''}={}){
  return {
    body:{innerText:orderText},
    querySelectorAll(selector){
      if(selector==='iframe')return [];
      if(selector==='kat-checkbox.QuantityCheckbox')return items;
      return [];
    },
    querySelector(selector){
      return selector==='kat-link.ClaimAlreadyExists'&&claim
        ?{getAttribute:()=>claim}:null;
    },
  };
}
function checkbox(){
  return {
    getBoundingClientRect(){return {width:10,height:10};},
    getClientRects(){return [{}];},
    disabled:false,
    hasAttribute(){return false;},
    getAttribute(){return null;},
    shadowRoot:{querySelector(){return {disabled:false,hasAttribute(){return false;}};}},
  };
}
function browser(docs){
  const root={
    body:{innerText:'Seller Central'},
    querySelectorAll(selector){return selector==='iframe'?docs.map(contentDocument=>({contentDocument})):[];},
    querySelector(){return null;},
  };
  return {evaluate:async expression=>vm.runInNewContext(expression,{document:root,JSON})};
}

test('reads the exact item from nested same-origin frame',async()=>{
  const doc=candidateDocument({orderText:'Pedido '+orderId,items:[checkbox()]});
  const result=await safeTEligibilitySnapshot(browser([doc]),orderId);
  assert.equal(result.orderMatched,true);
  assert.equal(result.itemCount,1);
  assert.equal(result.selectableCount,1);
  assert.equal(result.ambiguous,undefined);
});

test('recognizes an existing claim within that same frame',async()=>{
  const doc=candidateDocument({orderText:'Pedido '+orderId,claim:'64204-51742-6364222'});
  const result=await safeTEligibilitySnapshot(browser([doc]),orderId);
  assert.equal(result.existingClaim,'64204-51742-6364222');
});

test('two candidate result frames must be rejected rather than conflated',async()=>{
  const doc1=candidateDocument({orderText:'Pedido '+orderId,items:[checkbox()]});
  const doc2=candidateDocument({orderText:'Pedido '+orderId,items:[checkbox()]});
  assert.deepEqual(await safeTEligibilitySnapshot(browser([doc1,doc2]),orderId),{ambiguous:true});
});

test('inaccessible frame is never interpreted as no existing claim',async()=>{
  const root={
    body:{innerText:'Seller Central'},
    querySelectorAll(selector){
      if(selector==='iframe')return [{get contentDocument(){throw new Error('cross-origin');}}];
      return [];
    },
    querySelector(){return null;},
  };
  const result=await safeTEligibilitySnapshot({evaluate:async expr=>vm.runInNewContext(expr,{document:root,JSON})},orderId);
  assert.deepEqual(result,{ambiguous:true});
});
