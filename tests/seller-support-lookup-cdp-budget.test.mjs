import fs from 'node:fs';
import assert from 'node:assert/strict';

const worker = fs.readFileSync(new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url), 'utf8');

assert.match(worker, /const SUPPORT_CASE_LOOKUP_COMMAND_TIMEOUT_MS\s*=/, 'Seller Support history lookup needs a dedicated bounded CDP command timeout.');
assert.match(worker, /const SUPPORT_CASE_LOOKUP_SCAN_BUDGET_MS\s*=/, 'Seller Support history lookup needs an internal scan budget below the CDP timeout.');
assert.match(worker, /static async connect\(commandTimeoutMs = 15000\)/, 'Cdp.connect must accept a scoped timeout without changing the global default.');
assert.match(worker, /return new Cdp\(ws, page\.id, commandTimeoutMs\)/, 'The scoped timeout must reach the Cdp instance.');
assert.match(worker, /Cdp\.connect\(SUPPORT_CASE_LOOKUP_COMMAND_TIMEOUT_MS\)/, 'Only the Seller Support lookup target should use the longer timeout.');
assert.match(worker, /LOOKUP_SCAN_BUDGET_EXHAUSTED/, 'The browser-side history scan must fail closed before the CDP command timeout.');

const timeoutMatch = worker.match(/const SUPPORT_CASE_LOOKUP_COMMAND_TIMEOUT_MS\s*=.*?\|\|\s*(\d+)\)/s);
const budgetMatch = worker.match(/const SUPPORT_CASE_LOOKUP_SCAN_BUDGET_MS\s*=.*?\|\|\s*(\d+)\)/s);
if (timeoutMatch && budgetMatch) {
  assert.ok(Number(timeoutMatch[1]) >= 60000, 'Lookup timeout must be long enough for paced ViewCase reconciliation.');
  assert.ok(Number(budgetMatch[1]) < Number(timeoutMatch[1]), 'Internal scan budget must expire before the CDP timeout.');
}

console.log('seller-support-lookup-cdp-budget-test: OK');
