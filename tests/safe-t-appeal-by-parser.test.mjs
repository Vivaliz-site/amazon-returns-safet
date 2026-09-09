import test from 'node:test';
import assert from 'node:assert/strict';
import { parseSafeTStatus } from '../scripts/amazon-returns/safe-t-status-parser.mjs';

test('approved SAFE-T parses the official English Appeal by deadline', () => {
  const body = `Claim details: 91582-36431-8749346
Granted
SAFE-T claim ID
91582-36431-8749346
Claim date
Sun, Aug 16, 2026, 11:07 AM
Appeal by
Mon, Aug 24, 2026, 02:50 AM
Status da reivindicação
Aprovada
A reivindicação SAFE-T referente ao pedido 702-5404465-2676215 foi concedida.`;
  const read = parseSafeTStatus(body, {
    safe_t_id: '91582-36431-8749346',
    order_id: '702-5404465-2676215',
  });
  assert.equal(read.claim_status, 'APPROVED');
  assert.equal(read.appeal_deadline_at, '2026-08-24T02:50:00-03:00');
});

test('information request parses Reply by as the response deadline', () => {
  const body = `Claim details: 11111-22222-3333333
Status da reivindicação
Informações solicitadas
Reply by
Fri, Sep 11, 2026, 03:15 PM
Pedido 702-0000000-0000001`;
  const read = parseSafeTStatus(body, {
    safe_t_id: '11111-22222-3333333',
    order_id: '702-0000000-0000001',
  });
  assert.equal(read.claim_status, 'INFO_REQUESTED');
  assert.equal(read.appeal_deadline_at, '2026-09-11T15:15:00-03:00');
});
