import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source=readFileSync(new URL('../admin/amazon-returns/assets/cockpit-operational.js',import.meta.url),'utf8');
const code=source.slice(0,source.indexOf('function renderOperationalList('));
const context={brl:n=>'R$ '+Number(n).toFixed(2).replace('.',','),date:x=>String(x),stateLabel:x=>x};
vm.createContext(context);
vm.runInContext(code,context);

test('revisão operacional aberta não é decisão do proprietário',()=>{
  const c={state:'CREDIT_PENDING',review_status:'OPEN',requires_human_decision:false,current_action:'WAIT'};
  assert.equal(context.operatorResponsibility(c),'Nenhuma ação sua é necessária');
  assert.equal(context.operatorCompactResponsibility(c),'Sistema');
  assert.notEqual(context.operatorStatus(c),'Precisa da sua decisão');
});
test('revisão que exige decisão humana continua marcada como Você',()=>{
  const c={state:'SAFE_T_DENIED',review_status:'OPEN',requires_human_decision:true,current_action:'BLOCKED_REVIEW'};
  assert.equal(context.operatorResponsibility(c),'Sua decisão é necessária');
  assert.equal(context.operatorCompactResponsibility(c),'Você');
});
test('payload legado sem o campo preserva o comportamento anterior',()=>{
  assert.equal(context.operatorResponsibility({review_status:'OPEN'}),'Sua decisão é necessária');
  assert.equal(context.operatorResponsibility({current_action:'HUMAN_REVIEW'}),'Sua decisão é necessária');
});
