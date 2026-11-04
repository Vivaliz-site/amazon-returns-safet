import assert from 'node:assert/strict';
import fs from 'node:fs';
import { safeTExternalWindow } from '../scripts/amazon-returns/safe-t-external-window.mjs';

for (const [program, order_at, refund_at, expected] of [
  ['FBA_ONSITE', '2026-08-11 16:13:06', '2026-09-07 23:33:01', '2026-10-22 23:33:01'],
  ['DELIVERY_BY_AMAZON', '2026-08-25 13:55:32', '2026-09-09 18:38:59', '2026-10-24 18:38:59'],
]) {
  const job = { case: { program, order_at, refund_at } };
  const due = Date.parse(expected.replace(' ', 'T') + 'Z');
  const before = safeTExternalWindow(job, due - 1000);
  assert.equal(before.allowed, false, program + ' must not open before D+45');
  assert.equal(before.next_allowed_at, expected);
  assert.equal(before.reason, 'D45_OPENING_PENDING');
  assert.equal(safeTExternalWindow(job, due).allowed, true);
  assert.equal(safeTExternalWindow({ case: { program, refund_at } }, due).allowed, true,
    'A missing order timestamp must not introduce a second delay');
}
for (const program of ['FBA_ONSITE', 'DELIVERY_BY_AMAZON']) {
  const job = { case: { program, refund_at: '2026-08-01 00:00:00' } };
  const d90 = Date.parse('2026-10-30T00:00:00Z');
  assert.equal(safeTExternalWindow(job, d90 - 1000).allowed, true, `${program}: D+89 stays eligible`);
  assert.deepEqual(safeTExternalWindow(job, d90), { allowed: false, reason: 'D90_OPENING_EXPIRED', retry_safe: false }, `${program}: D+90 expires`);
  assert.equal(safeTExternalWindow(job, d90 + 86400000).allowed, false, `${program}: D+91 must not retry`);
}

const now = Date.parse('2026-10-30T23:30:00Z');
assert.equal(safeTExternalWindow({ case: { program: 'FBA_ONSITE', refund_at: '2026-09-07 23:33:01' } }, now).allowed, true);
assert.equal(safeTExternalWindow({ case: { program: 'FBA_ONSITE', refund_at: '2026-09-99 23:33:01' } }, now).allowed, false);
assert.equal(safeTExternalWindow({ case: { program: 'UNKNOWN' } }, now).allowed, false);
assert.equal(safeTExternalWindow({ case: { program: 'STANDARD' } }, now).allowed, true);

const worker = fs.readFileSync(new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url), 'utf8');
const firstGate = worker.indexOf('const openingWindow = safeTExternalWindow(job)');
const officialEligibility = worker.indexOf('const eligibilityClicked = await clickSafeTEligibilityButton(cdp)');
const providerItem = worker.indexOf('const hasItem = await cdp.evaluate');
const finalGate = worker.indexOf('const finalWindow = safeTExternalWindow(job)');
const irreversibleClick = worker.indexOf("cdp.clickKat('kat-button.SubmitButton')");
assert.ok(firstGate > 0 && officialEligibility > firstGate && providerItem > officialEligibility &&
  finalGate > providerItem && irreversibleClick > finalGate,
  'D+45 must reach the official eligibility UI; the final D+45 guard stays before the irreversible click');
assert.match(worker, /SELLER_CENTRAL_NOT_ELIGIBLE/);
assert.match(worker, /openingWindow\.reason === 'D90_OPENING_EXPIRED'/);
assert.match(worker, /finalWindow\.reason === 'D90_OPENING_EXPIRED'/);
assert.match(worker, /bridgeResult\('SUPERSEDED', \{ reason: (?:openingWindow|finalWindow)\.reason, retry_safe: false \}\)/);
assert.match(worker, /SERVER_WRITE_FLAG_OFF/);
console.log('safe-t-external-window-test: OK');
