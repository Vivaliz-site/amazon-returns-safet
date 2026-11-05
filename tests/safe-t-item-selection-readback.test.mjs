import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import { selectSafeTItemWithReadback } from '../scripts/amazon-returns/safe-t-item-selection.mjs';

const READY = checked=>({status:'READY',checked,point:{x:20,y:30}});
function mock(states,{clickResult=true}={}) {
  const log=[]; let step=0;
  return {
    log,
    cdp:{
      async evaluate(expression){log.push('evaluate');assert.match(expression,/kat-checkbox\.QuantityCheckbox/);return JSON.stringify(states[Math.min(step++,states.length-1)]);},
      async clickKat(selector){log.push('clickKat');assert.equal(selector,'kat-checkbox.QuantityCheckbox');return clickResult;},
      async send(method,params){log.push(method+':'+params.type);assert.equal(method,'Input.dispatchMouseEvent');assert.equal(params.x,20);assert.equal(params.y,30);}
    }
  };
}
const pause=async()=>{};

test('checked is acknowledged without a new click',async()=>{
  const m=mock([READY(true)]);
  assert.equal(await selectSafeTItemWithReadback(m.cdp,{pause}),'SELECTED');
  assert.deepEqual(m.log,['evaluate']);
});
test('synthetic click must be confirmed by readback',async()=>{
  const m=mock([READY(false),READY(true)]);
  assert.equal(await selectSafeTItemWithReadback(m.cdp,{pause}),'SELECTED');
  assert.deepEqual(m.log,['evaluate','clickKat','evaluate']);
});
test('trusted pointer is allowed once only after a proven unsuccessful synthetic click',async()=>{
  const m=mock([READY(false),READY(false),READY(true)]);
  assert.equal(await selectSafeTItemWithReadback(m.cdp,{pause}),'SELECTED');
  assert.equal(m.log.filter(x=>x==='clickKat').length,1);
  assert.deepEqual(m.log.filter(x=>x.startsWith('Input.dispatchMouseEvent:')),[
    'Input.dispatchMouseEvent:mouseMoved','Input.dispatchMouseEvent:mousePressed','Input.dispatchMouseEvent:mouseReleased']);
});
test('clicks without readback remain unverified and never proceed',async()=>{
  const m=mock([READY(false),READY(false),READY(false)]);
  assert.equal(await selectSafeTItemWithReadback(m.cdp,{pause}),'UNVERIFIED');
});
test('missing/ambiguous/disabled/unknown controls fail closed without click',async()=>{
  for(const state of [{status:'MISSING'},{status:'UNVERIFIED'},{status:'READY',checked:null}]){
    const m=mock([state]);
    assert.equal(await selectSafeTItemWithReadback(m.cdp,{pause}),state.status==='MISSING'?'MISSING':'UNVERIFIED');
    assert.deepEqual(m.log,['evaluate']);
  }
});
test('unreadable state after click does not trigger a second click',async()=>{
  const m=mock([READY(false),{status:'UNVERIFIED'}]);
  assert.equal(await selectSafeTItemWithReadback(m.cdp,{pause}),'UNVERIFIED');
  assert.equal(m.log.filter(x=>x.startsWith('Input.dispatchMouseEvent')).length,0);
});
test('rejected synthetic click never triggers trusted retry',async()=>{
  const m=mock([READY(false)],{clickResult:false});
  assert.equal(await selectSafeTItemWithReadback(m.cdp,{pause}),'UNVERIFIED');
  assert.deepEqual(m.log,['evaluate','clickKat']);
});
test('canonical worker integrates the guarded readback before Next and never bypasses the write gate',()=>{
  const workerPath=new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs',import.meta.url);
  const source=fs.readFileSync(workerPath,'utf8');
  const stage=source.split('async function safeTSubmit(cdp, job) {')[1]?.split('async function safeTAppeal(')[0] || '';
  assert.match(source,/import \{ selectSafeTItemWithReadback \} from '\.\/safe-t-item-selection\.mjs'/);
  assert.match(stage,/await selectSafeTItemWithReadback\(cdp\)/);
  assert.ok(stage.indexOf('await selectSafeTItemWithReadback(cdp)')<stage.indexOf('await clickSafeTNextButton(cdp)'));
  assert.match(stage,/SAFE_T_ITEM_CHECKBOX_MISSING/);
  assert.match(stage,/SAFE_T_ITEM_SELECTION_NOT_ACCEPTED/);
  assert.doesNotMatch(stage,/SAFE_T_SUBMIT\s*=\s*true|KILL_SWITCH\s*=\s*false/);
});
