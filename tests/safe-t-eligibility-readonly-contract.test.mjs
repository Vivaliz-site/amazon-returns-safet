import assert from 'node:assert/strict';
import fs from 'node:fs';
import { test } from 'node:test';

const root = new URL('../', import.meta.url);
const wrapper = fs.readFileSync(new URL('scripts/amazon-returns/run-seller-central-daily.sh', root),'utf8');
const worker = fs.readFileSync(new URL('scripts/amazon-returns/seller-central-bridge-worker.mjs', root),'utf8');

test('read-only eligibility mode must exit before any write bridge drain', () => {
  const orderIndex = wrapper.indexOf('--eligibility-check-only');
  assert.ok(orderIndex >= 0,'missing read-only eligibility mode');
  const writeDrainIndex = wrapper.indexOf('"$bridge_mode"');
  assert.ok(writeDrainIndex > orderIndex);
  const segment = wrapper.slice(orderIndex,writeDrainIndex);
  assert.match(segment,/exit\s+\$?\??/);
  assert.match(segment,/seller-central-bridge-worker\.mjs/);
});

test('eligibility probe must never invoke write job or submit/next controls', () => {
  const entry = worker.indexOf('async function safeTEligibilityProbe(');
  const end = worker.indexOf('\nasync function ',entry + 10);
  assert.ok(entry >= 0 && end > entry,'read-only eligibility probe must be defined');
  const code = worker.slice(entry,end);
  assert.doesNotMatch(code,/\b(safeTSubmit|safeTAppeal|supportOpen|supportUpdate|clickSafeTNextButton|clickKat\(['"]kat-button\.SubmitButton)/);
  assert.match(code,/clickSafeTEligibilityButton/);
  assert.ok(worker.includes("process.argv.indexOf('--eligibility-check-only')"),'read-only mode must be explicit');
  assert.ok(worker.includes("process.env.SELLER_CENTRAL_ELIGIBILITY_MANAGED!=='1'"),'unmanaged invocation cannot touch canonical browser');
  assert.match(wrapper,/SELLER_CENTRAL_ELIGIBILITY_MANAGED=1/);
  assert.ok(worker.includes('managedCycleLockHeld()'),'the actual inherited FLOCK must be verified');
  assert.ok(worker.includes('Cdp.connect({managedExistingOnly:true})'),'read-only canary must never spawn another browser');
  assert.ok(worker.includes('safeTEligibilitySnapshot(cdp,orderId)'),'nested-frame result must be parsed');
  assert.match(wrapper,/export SELLER_CENTRAL_CYCLE_DEADLINE_MS=/,'preserve main cycle-budget guard for ordinary drains');
});
