import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source=readFileSync(new URL('../admin/amazon-returns/assets/cockpit-operational.js',import.meta.url),'utf8');
// RED no código anterior: 38.90 era transformado silenciosamente em 3890.
const previous=value=>value.replace(/\./g,'').replace(',','.');
assert.equal(previous('38.90'),'3890','Reproduzir o defeito histórico de escala.');

const start=source.indexOf('function normalizeAdminAmountInput(value){');
const end=source.indexOf('function renderAdminCaseControls(data){',start);
assert.ok(start>=0&&end>start,'É necessário normalizador de valor monetário explícito e auditável.');
const context={};
runInNewContext(source.slice(start,end)+'\nthis.normalize=normalizeAdminAmountInput;',context);
const normalize=context.normalize;

for(const [input,expected] of [
  ['38.90','38.90'],
  ['38,90','38.90'],
  ['0.05','0.05'],
  ['1234','1234'],
  ['1234,56','1234.56'],
  ['1.234','1234'],
  ['1,234','1234'],
  ['1.234,56','1234.56'],
  ['1,234.56','1234.56'],
  ['1.234.567,89','1234567.89'],
  ['1,234,567.89','1234567.89'],
  ['-38,90','-38.90'],
  ['abc38,90','abc38,90'],
  ['1.234,567','1.234,567'],
])assert.equal(normalize(input),expected,'Valor convertido incorretamente: '+input);

assert.ok(source.includes('normalizeAdminAmountInput(raw)'),'O formulário administrativo precisa usar a normalização testada.');
assert.ok(!source.includes("raw.replace(/\./g,'').replace(',','.')"),'A conversão antiga que multiplica por 100 não pode permanecer.');
console.log('cockpit-admin-amount-normalize: OK');
