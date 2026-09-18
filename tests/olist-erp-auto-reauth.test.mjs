import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { classifyOlistLocation, ensureOlistAuthenticated } from '../scripts/amazon-returns/olist-erp-auth.mjs';

test('classifies ERP and Tiny identity locations without exposing credentials', () => {
  assert.equal(classifyOlistLocation('https://erp.olist.com/devolucoes_vendas#list'), 'ERP');
  assert.equal(classifyOlistLocation('https://erp.olist.com/login/'), 'AUTH');
  assert.equal(classifyOlistLocation('https://erp.olist.com/'), 'ERP_ENTRY');
  assert.equal(classifyOlistLocation('https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth'), 'AUTH');
  assert.equal(classifyOlistLocation('https://id.olist.com/login'), 'AUTH');
  assert.equal(classifyOlistLocation('https://example.com/'), 'OTHER');
});

test('does not touch credentials when an ERP session is already authenticated', async () => {
  const calls = [];
  const page = { url: () => 'https://erp.olist.com/devolucoes_vendas#list', goto: async (...args) => calls.push(['goto', ...args]) };
  const result = await ensureOlistAuthenticated(page, { email: 'must-not-be-used', password: 'must-not-be-used' });
  assert.equal(result.status, 'AUTHENTICATED');
  assert.equal(result.reason, 'SESSION_REUSED');
  assert.deepEqual(calls, []);
});

test('confirms the ERP concurrent-session entry without consuming Tiny fallback credentials', async () => {
  const calls = [];
  const login = {
    isVisible: async () => true,
    click: async () => calls.push(['click', 'login']),
  };
  const page = {
    current: 'https://erp.olist.com/',
    url() { return this.current; },
    getByRole: () => ({ first: () => login }),
    waitForURL: async predicate => {
      page.current = 'https://erp.olist.com/devolucoes_vendas#list';
      assert.equal(predicate(page.current), true);
    },
    goto: async (...args) => calls.push(['goto', ...args]),
  };
  const result = await ensureOlistAuthenticated(page, { email: '', password: '' });
  assert.deepEqual(result, { status: 'AUTHENTICATED', reason: 'CONCURRENT_SESSION_CONFIRMED' });
  assert.deepEqual(calls, [['click', 'login']]);
});

test('fails closed when Tiny login is visible but fallback credentials are unavailable', async () => {
  const page = { url: () => 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth' };
  const result = await ensureOlistAuthenticated(page, { email: '', password: '' });
  assert.deepEqual(result, { status: 'AUTH_REQUIRED', reason: 'FALLBACK_CREDENTIALS_MISSING' });
});

test('persistent browser host wires reauth from protected environment without printing secrets', () => {
  const host = fs.readFileSync(new URL('../scripts/amazon-returns/olist-erp-browser-host.cjs', import.meta.url), 'utf8');
  assert.match(host, /olist-erp-auth\.mjs/);
  assert.match(host, /OLIST_ERP_LOGIN_EMAIL/);
  assert.match(host, /OLIST_ERP_LOGIN_PASSWORD/);
  assert.match(host, /ensureOlistAuthenticated/);
  assert.match(host, /framenavigated/);
  assert.match(host, /domcontentloaded/);
  assert.match(host, /if\s*\(reauthInFlight\)\s*\{\s*await reauthInFlight/);
  assert.match(host, /ERP_ENTRY/);
  assert.match(host, /pruneDuplicateOlistPages/);
  assert.match(host, /candidate\.close/);
  assert.doesNotMatch(host, /console\.(log|error).*OLIST_ERP_LOGIN_(EMAIL|PASSWORD)/);
});
