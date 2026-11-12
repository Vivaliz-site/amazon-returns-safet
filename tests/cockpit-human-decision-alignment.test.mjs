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
test('caso encerrado nunca é decisão do proprietário, mesmo com ação HUMAN_REVIEW residual',()=>{
  for(const state of ['CLOSED_LOSS','RECOVERED','RECEIVED_OK']){
    const c={state,current_action:'HUMAN_REVIEW',requires_human_decision:true};
    assert.equal(context.operatorResponsibility(c),'Caso concluído',state);
    assert.equal(context.operatorCompactResponsibility(c),'Concluído',state);
    assert.notEqual(context.operatorStatus(c),'Precisa da sua decisão',state);
  }
  const closed={state:'APPEAL_DENIED_FINAL',closed_at:'2026-11-12 21:53:22',current_action:'BLOCKED_REVIEW',review_status:'OPEN'};
  assert.equal(context.operatorResponsibility(closed),'Caso concluído');
});
