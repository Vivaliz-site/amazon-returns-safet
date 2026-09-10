import test from 'node:test';
import assert from 'node:assert/strict';
import { parseSafeTStatus } from '../scripts/amazon-returns/safe-t-status-parser.mjs';

test('live Seller Central Granted status and Appeal by deadline are parsed', () => {
  const body = [
    'Claim details: 91582-36431-8749346',
    'Granted',
    'SAFE-T claim ID 91582-36431-8749346',
    'Claim date Sun, Aug 16, 2026, 11:07 AM',
    'Reason I did not receive the return',
    'Appeal by Mon, Aug 24, 2026, 02:50 AM',
    'A reivindicação SAFE-T foi concedida e emitimos um crédito de BRL 47,06.',
  ].join('\n');
  const parsed = parseSafeTStatus(body, {
    safe_t_id: '91582-36431-8749346',
    order_id: '702-5404465-2676215',
  });
  assert.equal(parsed.claim_status, 'APPROVED');
  assert.equal(parsed.appeal_deadline_at, '2026-08-24T02:50:00-03:00');
});
