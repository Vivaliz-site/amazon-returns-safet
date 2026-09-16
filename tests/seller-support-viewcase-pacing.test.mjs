import fs from 'node:fs';
import assert from 'node:assert/strict';

const worker=fs.readFileSync(new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs',import.meta.url),'utf8');
const match=worker.match(/const viewCase=async caseId=>\{([\s\S]*?)\n    \};/);
assert.ok(match,'viewCase implementation must remain extractable for pacing regression coverage');

const source=`const viewCase=async caseId=>{${match[1]}\n}; viewCase;`;
const originalFetch=globalThis.fetch;
const originalSetTimeout=globalThis.setTimeout;
try{
  const delays=[];
  globalThis.setTimeout=(fn,ms)=>{delays.push(Number(ms)||0);fn();return 1;};
  globalThis.fetch=async()=>({ok:true,status:200,json:async()=>({viewCaseMetaData:{caseStatus:'Open'}})});
  const viewCase=(0,eval)(source);
  await viewCase('12345678');
  await viewCase('87654321');
  assert.ok(delays.filter(ms=>ms>=900).length>=2,'Each ViewCase detail request must be paced to avoid Seller Central burst throttling');
} finally {
  globalThis.fetch=originalFetch;
  globalThis.setTimeout=originalSetTimeout;
}
console.log('seller-support-viewcase-pacing-test: OK');