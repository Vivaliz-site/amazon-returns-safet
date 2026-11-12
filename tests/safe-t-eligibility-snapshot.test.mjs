import assert from 'node:assert/strict';
import vm from 'node:vm';
import {test} from 'node:test';
import {safeTEligibilitySnapshot} from '../scripts/amazon-returns/safe-t-eligibility-snapshot.mjs';

const orderId='702-2491487-8294621';
const getComputedStyle=el=>el.computedStyle||{display:'block',visibility:'visible'};
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
  return {evaluate:async expression=>vm.runInNewContext(expression,{document:root,JSON,getComputedStyle})};
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

// Seller Central renders the order link inside kat-* shadow roots, so innerText
// never carries the identity. These fixtures model that rendered tree.
const textNode=value=>({nodeType:3,nodeValue:value});
const element=(tagName,{children=[],shadow=null,hidden=false,visible=true,display='block',visibility='visible'}={})=>({
  nodeType:1,tagName,hidden,childNodes:children,
  shadowRoot:shadow?{childNodes:shadow}:null,
  computedStyle:{display:visible?display:'none',visibility},
});
function renderedDocument({tree=[],items=[],claim=''}={}){
  const doc=candidateDocument({orderText:'Solicitar reembolso SAFE-T',items,claim});
  doc.body=Object.assign(doc.body,{nodeType:1,tagName:'BODY',hidden:false,childNodes:tree});
  return doc;
}

test('order link rendered in a shadow root verifies identity without innerText',async()=>{
  const doc=renderedDocument({
    items:[checkbox()],
    tree:[
      element('KAT-LINK',{shadow:[textNode(orderId)]}),
      element('SCRIPT',{children:[textNode('134-1234567-5479')]}),
      element('DIV',{children:[textNode('134-1234567-5479')],visible:false}),
    ],
  });
  const result=await safeTEligibilitySnapshot(browser([doc]),orderId);
  assert.equal(result.orderMatched,true);
  assert.equal(result.itemCount,1);
  assert.equal(result.selectableCount,1);
});

test('existing claim is kept alongside a shadow-rendered order link',async()=>{
  const doc=renderedDocument({
    claim:'64204-51742-6364222',
    tree:[element('KAT-LINK',{shadow:[textNode(orderId)]})],
  });
  const result=await safeTEligibilitySnapshot(browser([doc]),orderId);
  assert.equal(result.orderMatched,true);
  assert.equal(result.existingClaim,'64204-51742-6364222');
});

test('a different order rendered in a shadow root never verifies the requested order',async()=>{
  const doc=renderedDocument({
    items:[checkbox()],
    tree:[element('KAT-LINK',{shadow:[textNode('702-1234567-1234567')]})],
  });
  const result=await safeTEligibilitySnapshot(browser([doc]),orderId);
  assert.equal(result.orderMatched,false);
});

test('two visible orders, one in a shadow root, still invalidate identity',async()=>{
  const doc=renderedDocument({
    items:[checkbox()],
    tree:[
      element('KAT-LINK',{shadow:[textNode(orderId)]}),
      element('SPAN',{children:[textNode('702-1234567-1234567')]}),
    ],
  });
  const result=await safeTEligibilitySnapshot(browser([doc]),orderId);
  assert.equal(result.orderMatched,false);
});

test('the requested order inside a non-rendered node is not an identity',async()=>{
  const doc=renderedDocument({
    items:[checkbox()],
    tree:[
      element('DIV',{children:[textNode(orderId)],visible:false}),
      element('DIV',{children:[textNode(orderId)],hidden:true}),
      element('TEMPLATE',{children:[textNode(orderId)]}),
    ],
  });
  const result=await safeTEligibilitySnapshot(browser([doc]),orderId);
  assert.equal(result.orderMatched,false);
});

test('an unreadable shadow tree contributes no identity and never throws',async()=>{
  const poisoned=element('KAT-LINK',{});
  Object.defineProperty(poisoned,'shadowRoot',{get(){throw new Error('boom');}});
  const doc=renderedDocument({items:[checkbox()],tree:[poisoned]});
  const result=await safeTEligibilitySnapshot(browser([doc]),orderId);
  assert.equal(result.orderMatched,false);
  assert.equal(result.itemCount,1);
});

test('unslotted light children of a shadow host are not rendered and carry no identity',async()=>{
  const doc=renderedDocument({
    items:[checkbox()],
    tree:[element('KAT-LINK',{
      shadow:[textNode(orderId)],
      children:[textNode('134-1234567-5479')],
    })],
  });
  const result=await safeTEligibilitySnapshot(browser([doc]),orderId);
  assert.equal(result.orderMatched,true);
});

test('light children rendered through a slot do count as visible identity',async()=>{
  const slotted=textNode('702-1234567-1234567');
  const slot=Object.assign(element('SLOT'),{assignedNodes:()=>[slotted]});
  const doc=renderedDocument({
    items:[checkbox()],
    tree:[element('KAT-LINK',{
      shadow:[textNode(orderId),slot],
      children:[slotted],
    })],
  });
  const result=await safeTEligibilitySnapshot(browser([doc]),orderId);
  assert.equal(result.orderMatched,false);
});

test('slot fallback content renders only when nothing is assigned',async()=>{
  const fallbackOnly=Object.assign(element('SLOT',{children:[textNode(orderId)]}),{assignedNodes:()=>[]});
  const doc=renderedDocument({items:[checkbox()],tree:[element('KAT-LINK',{shadow:[fallbackOnly]})]});
  const result=await safeTEligibilitySnapshot(browser([doc]),orderId);
  assert.equal(result.orderMatched,true);
});

test('display:contents hosts still render their shadow text',async()=>{
  const doc=renderedDocument({
    items:[checkbox()],
    tree:[element('KAT-LINK',{shadow:[textNode(orderId)],display:'contents'})],
  });
  const result=await safeTEligibilitySnapshot(browser([doc]),orderId);
  assert.equal(result.orderMatched,true);
});

test('visibility:hidden text is not an identity but a visible child still renders',async()=>{
  const hiddenOnly=renderedDocument({
    items:[checkbox()],
    tree:[element('DIV',{children:[textNode(orderId)],visibility:'hidden'})],
  });
  assert.equal((await safeTEligibilitySnapshot(browser([hiddenOnly]),orderId)).orderMatched,false);
  const visibleChild=renderedDocument({
    items:[checkbox()],
    tree:[element('DIV',{visibility:'hidden',children:[element('SPAN',{children:[textNode(orderId)]})]})],
  });
  assert.equal((await safeTEligibilitySnapshot(browser([visibleChild]),orderId)).orderMatched,true);
});
