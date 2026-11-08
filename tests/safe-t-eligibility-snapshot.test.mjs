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

test('a second visible Amazon order in the same result invalidates identity',async()=>{
  const other='702-1234567-1234567';
  const doc=candidateDocument({orderText:'Consulta '+orderId+'; resultado '+other,items:[checkbox()]});
  const result=await safeTEligibilitySnapshot(browser([doc]),orderId);
  assert.equal(result.orderMatched,false);
  assert.equal(result.selectableCount,1);
});

test('repeating only the same exact order ID preserves read-only detection',async()=>{
  const doc=candidateDocument({orderText:'Pedido '+orderId+'; detalhes '+orderId,items:[checkbox()]});
  const result=await safeTEligibilitySnapshot(browser([doc]),orderId);
  assert.equal(result.orderMatched,true);
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

test('input echo never proves a selected SAFE-T item belongs to requested order',async()=>{
  const stale=candidateDocument({orderText:'Pedido 702-1234567-1234567',items:[checkbox()]});
  const original=stale.querySelectorAll.bind(stale);
  stale.querySelectorAll=selector=>selector==='kat-input,input'
    ? [{tagName:'INPUT',value:orderId}] : original(selector);
  const result=await safeTEligibilitySnapshot(browser([stale]),orderId);
  assert.equal(result.orderMatched,false);
  assert.equal(result.itemCount,1);
  assert.equal(result.selectableCount,1);
});

test('a search field echo with no displayed order remains unverified',async()=>{
  const empty=candidateDocument({orderText:'Solicitacao SAFE-T',items:[checkbox()]});
  const original=empty.querySelectorAll.bind(empty);
  empty.querySelectorAll=selector=>selector==='kat-input,input'
    ? [{tagName:'INPUT',value:orderId}] : original(selector);
  const result=await safeTEligibilitySnapshot(browser([empty]),orderId);
  assert.equal(result.orderMatched,false);
});


test('cross-origin frame cannot be silently ignored alongside apparently eligible order',async()=>{
  const eligible=candidateDocument({orderText:'Pedido '+orderId,items:[checkbox()]});
  const root={
    body:{innerText:'Seller Central'},
    querySelectorAll(selector){return selector==='iframe'?
      [{contentDocument:eligible},{get contentDocument(){throw new Error('cross-origin');}}]:[];},
    querySelector(){return null;},
  };
  const result=await safeTEligibilitySnapshot(
    {evaluate:async expr=>vm.runInNewContext(expr,{document:root,JSON})},orderId);
  assert.deepEqual(result,{ambiguous:true});
});

test('iframe with null contentDocument blocks apparent eligibility',async()=>{
  const eligible=candidateDocument({orderText:'Pedido '+orderId,items:[checkbox()]});
  const root={
    body:{innerText:'Seller Central'},
    querySelectorAll(selector){return selector==='iframe'?
      [{contentDocument:eligible},{contentDocument:null}]:[];},
    querySelector(){return null;},
  };
  const result=await safeTEligibilitySnapshot(
    {evaluate:async expr=>vm.runInNewContext(expr,{document:root,JSON})},orderId);
  assert.deepEqual(result,{ambiguous:true});
});
