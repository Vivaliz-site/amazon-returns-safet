import fs from 'node:fs';
import assert from 'node:assert/strict';

const worker=fs.readFileSync(new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs',import.meta.url),'utf8');
const match=worker.match(/function supportLookupFailureReason\(error, auth = null\) \{([\s\S]*?)\n\}/);
assert.ok(match,'Seller Support lookup must expose a dedicated safe failure classifier instead of collapsing failures to UNKNOWN.');
const source=`function supportLookupFailureReason(error, auth = null) {${match[1]}\n}; supportLookupFailureReason;`;
const fn=(0,eval)(source);
assert.equal(fn(Object.assign(new Error('SUPPORT_CASE_LOOKUP_UNAVAILABLE'),{lookupReason:'DETAIL_LOOKUP_FAILED'})), 'DETAIL_LOOKUP_FAILED');
assert.equal(fn(new Error('SUPPORT_CASE_LOOKUP_UNAVAILABLE'),{status:'AUTH_REQUIRED'}), 'AUTH_REQUIRED');
assert.equal(fn(new Error('CDP command timed out: Runtime.evaluate')), 'CDP_COMMAND_TIMEOUT');
assert.equal(fn(new Error('browser expression failed: TypeError')), 'BROWSER_EXPRESSION_FAILED');
assert.notEqual(fn(new Error('SUPPORT_CASE_LOOKUP_UNAVAILABLE')), 'UNKNOWN');
console.log('seller-support-lookup-reason-propagation-test: OK');
