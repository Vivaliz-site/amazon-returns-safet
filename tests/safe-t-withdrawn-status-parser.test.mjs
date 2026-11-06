import test from 'node:test';
import assert from 'node:assert/strict';
import { parseSafeTStatus } from '../scripts/amazon-returns/safe-t-status-parser.mjs';

const expected = {safe_t_id:'11111-22222-3333333', order_id:'701-0000000-0000001'};
test('withdrawal explicitly read from Portuguese Seller Central does not become denied or approved', () => {
  const body = `Claim details 11111-22222-3333333
Status da reivindicação
Retirado pelo vendedor
Comentário da Amazon
Retiramos sua reivindicação SAFE-T em seu nome. O pacote foi recebido.
As apelações devem ser feitas dentro de 5 dias úteis a partir da última resolução.`;
  const read = parseSafeTStatus(body, expected);
  assert.equal(read.claim_status,'WITHDRAWN');
  assert.equal(read.safe_t_id,expected.safe_t_id);
  assert.equal(read.order_id,expected.order_id);
  assert.match(read.decision_text,/Retiramos sua reivindicação/i);
  assert.equal(read.appeal_deadline_at,null,'Do not invent an appeal deadline from relative text');
  assert.equal(read.appeal_submitted,false);
  assert.equal(read.appeal_denied,false);
});
test('a later explicit approved label takes precedence over an older withdrawn message', () => {
  const body = 'Retiramos sua reivindicação SAFE-T em seu nome.\nStatus da reivindicação\nAprovada';
  assert.equal(parseSafeTStatus(body,expected).claim_status,'APPROVED');
});
test('a withdrawn label takes precedence over prior approval text', () => {
  const body = 'Sua reivindicação foi aprovada no passado.\nStatus da reivindicação\nRetirado';
  assert.equal(parseSafeTStatus(body,expected).claim_status,'WITHDRAWN');
});
