import test from 'node:test';
import assert from 'node:assert/strict';
import { parseSafeTStatus } from '../scripts/amazon-returns/safe-t-status-parser.mjs';

test('SAFE-T readback exposes seller and Amazon messages in source order', () => {
  const body = [
    'Negamos sua reivindicação SAFE-T referente ao pedido 702-4847212-7165801.',
    'Pedido 702-4847212-7165801, SAFE-T 45092-65513-7280005. Solicito reavaliação da decisão.',
    'Analisamos seu recurso e negamos sua solicitação de reembolso.',
  ].join('\n');
  const read = parseSafeTStatus(body, {
    safe_t_id: '45092-65513-7280005',
    order_id: '702-4847212-7165801',
  });
  assert.equal(read.claim_status, 'DENIED');
  assert.deepEqual(read.communications.map(x => x.actor), ['AMAZON', 'SELLER', 'AMAZON']);
  assert.match(read.communications[0].body, /Negamos sua reivindicação/);
  assert.match(read.communications[1].body, /Solicito reavaliação/);
  assert.match(read.communications[2].body, /Analisamos seu recurso/);
});

test('SAFE-T conversation deduplicates repeated identical blocks', () => {
  const line = 'Negamos sua reivindicação SAFE-T referente ao pedido 702-4847212-7165801.';
  const read = parseSafeTStatus([line, line].join('\n'), {
    safe_t_id: '45092-65513-7280005',
    order_id: '702-4847212-7165801',
  });  assert.equal(read.communications.length, 1);
});

test('SAFE-T readback without conversation returns an empty array', () => {
  const read = parseSafeTStatus([
    'ID da reivindicação SAFE-T 45092-65513-7280005',
    'Status da reivindicação',
    'Pendente',
  ].join('\n'), {
    safe_t_id: '45092-65513-7280005',
    order_id: '702-4847212-7165801',
  });
  assert.deepEqual(read.communications, []);
});