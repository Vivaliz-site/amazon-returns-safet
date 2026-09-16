import fs from 'node:fs';
import assert from 'node:assert/strict';

const worker=fs.readFileSync(new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs',import.meta.url),'utf8');
const match=worker.match(/const viewCase=async caseId=>\{([\s\S]*?)\n    \};/);
assert.ok(match,'viewCase implementation must remain extractable for rate-limit regression coverage');

const source=`const viewCase=async caseId=>{${match[1]}\n}; viewCase;`;
const originalFetch=globalThis.fetch;
const originalSetTimeout=globalThis.setTimeout;
try{
  globalThis.setTimeout=(fn)=>{fn();return 1;};
  let calls=0;
  globalThis.fetch=async()=>{
    calls++;
    if(calls===1)return {ok:false,status:429,json:async()=>({})};
    return {ok:true,status:200,json:async()=>({viewCaseMetaData:{caseStatus:'Open'}})};
  };
  const viewCase=(0,eval)(source);
  const detail=await viewCase('12345678');
  assert.equal(calls,2,'ViewCase HTTP 429 must be retried instead of failing the whole support lookup');
  assert.equal(detail?.viewCaseMetaData?.caseStatus,'Open');
} finally {
  globalThis.fetch=originalFetch;
  globalThis.setTimeout=originalSetTimeout;
}
console.log('seller-support-viewcase-rate-limit-test: OK');
