import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source=readFileSync(new URL('../admin/amazon-returns/assets/cockpit-operational.js',import.meta.url),'utf8');
const code=source.slice(0,source.indexOf('function renderOperationalList('));
const context={brl:n=>'R$ '+Number(n).toFixed(2).replace('.',','),date:x=>String(x),stateLabel:x=>x};
vm.createContext(context);
vm.runInContext(code,context);

test('SAFE-T partially credited is not described as wholly awaiting payment',()=>{
  const c={state:'CREDIT_PENDING',current_action:'CHECK_FINANCES',
    reconciled_credit_amount:'46.88',outstanding_amount:'20.42',physical_status:'NOT_RECEIVED'};
  assert.equal(context.operatorStatus(c),'Crédito parcial identificado; diferença em análise');
  assert.equal(context.operatorFinancialSummary(c).label,'diferença nominal após crédito; tarifas a conferir');
  assert.match(context.operatorNextStep(c),/crédito já identificado/);
  const facts=context.operatorUnresolvedFacts(c).join(' ');
  assert.match(facts,/diferença nominal de R\$ 20,42/);
  assert.doesNotMatch(facts,/crédito da Amazon ainda precisa ser confirmado/);
  assert.doesNotMatch(facts,/R\$ 20,42 a recuperar/);
});
test('cases with no credit remain pending and settled cases do not invent differences',()=>{
  const zero={state:'CREDIT_PENDING',current_action:'CHECK_FINANCES',
    reconciled_credit_amount:'0.00',outstanding_amount:'20.42'};
  assert.equal(context.operatorStatus(zero),'Aguardando crédito da Amazon');
  assert.equal(context.operatorFinancialSummary(zero).label,'saldo ainda a recuperar');
  const recovered={state:'RECOVERED',reconciled_credit_amount:'12.57',outstanding_amount:'0'};
  assert.equal(context.operatorStatus(recovered),'Valor recuperado');
  assert.doesNotMatch(context.operatorUnresolvedFacts(recovered).join(' '),/diferença nominal/);
});
