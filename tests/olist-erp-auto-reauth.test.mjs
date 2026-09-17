import test from 'node:test';
import assert from 'node:assert/strict';
import { classifyOlistLocation, ensureOlistAuthenticated } from '../scripts/amazon-returns/olist-erp-auth.mjs';

test('classifies ERP and Tiny identity locations without exposing credentials', () => {
  assert.equal(classifyOlistLocation('https://erp.olist.com/devolucoes_vendas#list'), 'ERP');
  assert.equal(classifyOlistLocation('https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth'), 'AUTH');
  assert.equal(classifyOlistLocation('https://id.olist.com/login'), 'AUTH');
  assert.equal(classifyOlistLocation('https://example.com/'), 'OTHER');
});

test('does not touch credentials when an ERP session is already authenticated', async () => {
  const calls = [];
  const page = {
    url: () => 'https://erp.olist.com/devolucoes_vendas#list',
    goto: async (...args) => calls.push(['goto', ...args]),
  };
  const result = await ensureOlistAuthenticated(page, {
    email: 'must-not-be-used',
    password: 'must-not-be-used',
  });
  assert.equal(result.status, 'AUTHENTICATED');
  assert.equal(result.reason, 'SESSION_REUSED');
  assert.deepEqual(calls, []);
});

test('fails closed when Tiny login is visible but fallback credentials are unavailable', async () => {
  const page = { url: () => 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth' };
  const result = await ensureOlistAuthenticated(page, { email: '', password: '' });
  assert.deepEqual(result, { status: 'AUTH_REQUIRED', reason: 'FALLBACK_CREDENTIALS_MISSING' });
});
