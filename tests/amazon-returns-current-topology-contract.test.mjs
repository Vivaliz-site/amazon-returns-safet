import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const access = fs.readFileSync(new URL('../docs/AGENT-REMOTE-ACCESS.md', import.meta.url), 'utf8');

test('remote access entrypoint names Marina as sole production web host', () => {
  assert.match(access, /marina-a1-4c24g[^\n]*produção web/i);
  assert.match(access, /always-free-arm-1787907847-26[^\n]*backend/i);
  assert.doesNotMatch(access, /shopvivaliz-free-a1[^\n]*(?:produção web|host ativo|deploy ativo)/i);
});

test('the old source is explicitly forbidden and never offered as an access fallback', () => {
  assert.match(access, /shopvivaliz-free-a1[^\n]*VM antiga excluída/i);
  assert.match(access, /NUNCA[^\n]*acessar[^\n]*monitorar[^\n]*SSH/i);
  assert.match(access, /nunca.*contornar recusa/i);
});

test('browser, grants, D+45 and idempotency remain explicit safety conditions', () => {
  assert.match(access, /navegador corporativo[^\n]*somente na backend/i);
  assert.match(access, /D\+45 após reembolso ao comprador/i);
  assert.match(access, /idempotência/i);
  assert.match(access, /kill switch/i);
  assert.match(access, /sete canais externos/i);
  assert.match(access, /Nunca editar `current\//i);
});
