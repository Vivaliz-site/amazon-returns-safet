import assert from 'node:assert/strict';
import { safeTExternalWindow } from '../scripts/amazon-returns/safe-t-external-window.mjs';
const now = Date.parse('2026-10-30T23:30:00Z');
for (const [program, order_at, refund_at, expected] of [
  ['FBA_ONSITE', '2026-08-11 16:13:06', '2026-09-07 23:33:01', '2026-11-06 23:33:01'],
  ['DELIVERY_BY_AMAZON', '2026-08-25 13:55:32', '2026-09-09 18:38:59', '2026-11-08 18:38:59'],
]) {
  const job = { case: { program, order_at, refund_at } };
  const result = safeTExternalWindow(job, now);
  assert.equal(result.allowed, false, program + ' blocks until D60');
  assert.equal(result.next_allowed_at, expected);
  assert.equal(safeTExternalWindow(job, Date.parse(expected.replace(' ', 'T') + 'Z')).allowed, true);
}
assert.equal(safeTExternalWindow({ case: { program: 'FBA_ONSITE', order_at: '2026-04-20 23:59:59', refund_at: '2026-09-07 23:33:01' } }, now).allowed, true);
assert.equal(safeTExternalWindow({ case: { program: 'FBA_ONSITE', refund_at: '2026-09-07 23:33:01' } }, now).allowed, false);
assert.equal(safeTExternalWindow({ case: { program: 'FBA_ONSITE', order_at: '2026-08-11 16:13:06', refund_at: '2026-09-99 23:33:01' } }, now).allowed, false);
assert.equal(safeTExternalWindow({ case: { program: 'UNKNOWN' } }, now).allowed, false);
assert.equal(safeTExternalWindow({ case: { program: 'STANDARD' } }, now).allowed, true);
console.log('safe-t-external-window-test: OK');
