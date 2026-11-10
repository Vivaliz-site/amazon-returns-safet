import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source=readFileSync(new URL('../admin/amazon-returns/assets/cockpit.js',import.meta.url),'utf8');
const begin=source.indexOf('function renderSupportReferences(items){');
const end=source.indexOf('async function openCase(caseId){',begin);
assert.ok(begin>=0 && end>begin,'The support section renderer must be present.');

const text=(tag,value='',className='')=>({
  tag,value,className,children:[],
  append(...children){this.children.push(...children);}
});
const context={text};
runInNewContext(source.slice(begin,end)+';this.renderSupportReferences=renderSupportReferences;',context);
const rendered=context.renderSupportReferences([
  {id:'22000000002',is_primary:false,seller_central_verified:false},
  {id:'22000000001',is_primary:true,seller_central_verified:true},
  {id:'invalid-html',is_primary:false,seller_central_verified:true},
]);
const lines=rendered.children.filter(item=>item.tag==='p').map(item=>String(item.value));
assert.equal(lines.length,2,'Both valid support IDs must render and malformed IDs must not.');
assert.match(lines[0],/22000000002/);
assert.match(lines[0],/situação ainda não confirmada no Seller Central/);
assert.match(lines[1],/22000000001/);
assert.match(lines[1],/vínculo principal do cadastro/);
assert.match(lines[1],/situação observada no Seller Central/);
const absent=context.renderSupportReferences([]);
assert.equal(absent.children.filter(item=>item.tag==='p').length,1,'No evidence must have an explicit empty state.');
console.log('cockpit-support-refs-ui: OK');
