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

test('a Portuguese Seller Central claim heading Negado wins over historic pending conversation', () => {
  const body = `Amazon Seller Central
Detalhes da reivindicação: 11111-22222-3333333
Ver todas as reivindicações SAFE-T
701-0000000-0000001
Negado
ID da reivindicação SAFE-T
11111-22222-3333333
Data da reivindicação
Mon, Aug 10, 2026, 08:24 PM
Recorrer por
Tue, Aug 18, 2026, 11:41 AM
Aug 10
Estamos analisando sua reivindicação. Em análise
Aug 11
Estamos encerrando a reivindicação SAFE-T referente ao pedido.`;
  const result = parseSafeTStatus(body, expected);
  assert.equal(result.claim_status, 'DENIED');
  assert.equal(result.appeal_deadline_at, '2026-08-18T11:41:00-03:00');
});

test('claim header status is not inferred from quoted text elsewhere', () => {
  const body = `Historico de suporte: pedido 701-0000000-0000001 negado.
Detalhes da reivindicação: 11111-22222-3333333
Pedido 701-0000000-0000001
Uma mensagem sobre negado e aprovado
Em análise da equipe no momento.`;
  assert.notEqual(parseSafeTStatus(body, expected).claim_status, 'DENIED');
});

test('current withdrawn heading is authoritative even when older response says Negado', () => {
  const body = `Detalhes da reivindicação: 11111-22222-3333333
701-0000000-0000001
Retirado
ID da reivindicação SAFE-T
11111-22222-3333333
Recorrer por
Wed, Nov 12, 2026, 11:51 AM
Comentário histórico: Negado
Em análise do recurso.`;
  const result = parseSafeTStatus(body, expected);
  assert.equal(result.claim_status, 'WITHDRAWN');
  assert.equal(result.appeal_deadline_at, '2026-11-12T11:51:00-03:00');
});
