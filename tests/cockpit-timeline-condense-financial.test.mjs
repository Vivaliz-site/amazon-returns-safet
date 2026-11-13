import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source=readFileSync(new URL('../admin/amazon-returns/assets/cockpit-operational.js',import.meta.url),'utf8');
const start=source.indexOf('function operationalTimelineTitle(');
const end=source.indexOf('function renderCondensedTimeline(');
const context={humanText:x=>String(x)};
vm.createContext(context);
vm.runInContext(source.slice(start,end),context);

function day(d,n,title){return Array.from({length:n},(_,i)=>({title,category:'OBSERVATION',occurred_at:`2026-11-${d} ${String(i%24).padStart(2,'0')}:${String(i%60).padStart(2,'0')}:00`}));}

test('verificações financeiras periódicas viram um marco por dia',()=>{
  const items=[
    ...day('10',40,'FINANCIAL_REFRESH_CONFIRMED'),...day('10',30,'FINANCIAL_RECONCILIATION_CONFIRMED'),...day('10',30,'FINANCIAL_RECONCILIATION_CHECKED'),
    ...day('11',40,'FINANCIAL_REFRESH_CONFIRMED'),
    {title:'SAFE_T_SUBMIT',category:'EXTERNAL_WRITE',occurred_at:'2026-11-10 12:00:00'},
    {title:'REFUND_CONFIRMED',category:'OBSERVATION',occurred_at:'2026-11-09 08:00:00'},
  ];
  const out=context.condenseTimeline(items);
  assert.equal(out.length,6,'4 grupos diários + 2 marcos únicos');
  const refresh10=out.find(i=>i.title==='FINANCIAL_REFRESH_CONFIRMED'&&i.occurred_at.startsWith('2026-11-10'));
  assert.equal(refresh10.repeat_count,40);
  assert.equal(out.filter(i=>i.title==='SAFE_T_SUBMIT').length,1,'escrita externa nunca é agrupada');
  assert.equal(out.filter(i=>i.title==='REFUND_CONFIRMED').length,1);
});
test('histórico só monta os cartões quando é aberto',()=>{
  const body=source.slice(end,source.indexOf('function caseMessageItems('));
  assert.match(body,/addEventListener\('toggle'/,'renderização deve ser sob demanda');
});
