import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const operational=readFileSync(new URL('../admin/amazon-returns/assets/cockpit-operational.js',import.meta.url),'utf8');
const base=readFileSync(new URL('../admin/amazon-returns/assets/cockpit.js',import.meta.url),'utf8');

const opContext={brl:n=>'R$ '+Number(n).toFixed(2).replace('.',','),date:x=>String(x),stateLabel:x=>x};
vm.createContext(opContext);
vm.runInContext(operational.slice(0,operational.indexOf('function renderOperationalList(')),opContext);

test('a case closed as loss is not described as still recoverable',()=>{
  const closed={state:'CLOSED_LOSS',outstanding_amount:'36.38',reconciled_credit_amount:'0.00'};
  const summary=opContext.operatorFinancialSummary(closed);
  assert.equal(summary.value,'R$ 36,38');
  assert.match(summary.label,/caso encerrado como perda/);
  assert.doesNotMatch(summary.label,/ainda a recuperar/);
});
test('open cases keep the recoverable-balance wording',()=>{
  assert.equal(opContext.operatorFinancialSummary({state:'AWAITING_RETURN',outstanding_amount:'20.42',reconciled_credit_amount:'0.00'}).label,'saldo ainda a recuperar');
  assert.equal(opContext.operatorFinancialSummary({state:'CLOSED_LOSS',outstanding_amount:'0',reconciled_credit_amount:'0'}).label,'Nenhum saldo financeiro em aberto.');
});

function loadSelectView(){
  const fn=name=>base.split('\n').find(line=>line.startsWith(`function ${name}(`));
  const urls=[];
  const classList={add(){},remove(){},toggle(){}};
  const el={classList,setAttribute(){}};
  const context={
    state:{view:'cases',page:3,filters:{q:'702-2491487-8294621'}},
    history:{replaceState:(_a,_b,url)=>urls.push(url)},
    location:{pathname:'/admin/amazon-returns/'},
    document:{querySelector:()=>el,querySelectorAll:()=>[]},
    URLSearchParams,
    loadReviews(){},loadRules(){},loadCases(){},
  };
  vm.createContext(context);
  vm.runInContext(fn('syncUrl')+'\n'+fn('selectView'),context);
  return {context,urls};
}
test('switching to the reviews tab updates the URL so a reload stays on it',()=>{
  const {context,urls}=loadSelectView();
  context.selectView('reviews');
  assert.equal(urls.length,1);
  assert.match(urls[0],/view=reviews/);
  assert.match(urls[0],/page=1/);
});
test('switching to the rules tab updates the URL',()=>{
  const {context,urls}=loadSelectView();
  context.selectView('rules');
  assert.match(urls[0],/view=rules/);
});
