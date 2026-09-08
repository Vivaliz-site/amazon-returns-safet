import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import http from 'node:http';
import net from 'node:net';
import vm from 'node:vm';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { fileURLToPath } from 'node:url';
import { parseSafeTStatus } from '../scripts/amazon-returns/safe-t-status-parser.mjs';

const worker = fileURLToPath(new URL('../scripts/amazon-returns/seller-central-safe-t-read-worker.mjs', import.meta.url));
const source = fs.readFileSync(worker, 'utf8');
const cdpStart = source.indexOf('class Cdp {');
const cdpEnd = source.indexOf('\nfunction authState');
assert.ok(cdpStart >= 0 && cdpEnd > cdpStart, 'CDP fixture markers must match the real read-worker source');
const cdpSource = source.slice(cdpStart, cdpEnd);

test('reader worker is host-neutral and uses shared authentication recovery', () => {
  assert.match(source, /seller-central-auth\.mjs/);
  assert.match(source, /SELLER_CENTRAL_STATUS_WORKER_ID/);
  assert.match(source, /SELLER_CENTRAL_BROWSER/);
  assert.match(source, /ensureSellerCentralAuthenticated/);
  assert.doesNotMatch(source, /C:\\Users\\FRED\\/);
});
const Cdp = vm.runInNewContext(`${cdpSource}\nCdp`, { Error });
class FakeSocket extends EventTarget { send() {} close() { this.dispatchEvent(new Event('close')); } }
for (const event of ['close', 'error']) {
  test(`CDP ${event} rejects every pending command and clears the queue`, async () => {
    const socket = new FakeSocket();
    const cdp = new Cdp(socket);
    const requests = [cdp.send('Page.navigate'), cdp.send('Runtime.evaluate')];
    socket.dispatchEvent(new Event(event));
    for (const request of requests) await assert.rejects(request, /CDP WebSocket/);
    assert.equal(cdp.pending.size, 0);
  });
}

test('real denied-page layout preserves denied appeal without a status label', () => {
  const observation = parseSafeTStatus('Detalhes da reivindica\u00e7\u00e3o: 11111-22222-3333333\nNegado\nID da reivindica\u00e7\u00e3o SAFE-T\n11111-22222-3333333\nRecorrer por\nTue, Sep 8, 2026, 02:28 PM\nAnalisamos seu recurso. Ap\u00f3s a an\u00e1lise do recurso, negamos sua solicita\u00e7\u00e3o de reembolso.');
  assert.equal(observation.claim_status, 'DENIED');
  assert.equal(observation.appeal_denied, true);
  assert.equal(observation.safe_t_id, '11111-22222-3333333');
  assert.equal(observation.appeal_deadline_at, '2026-09-08T14:28:00-03:00');
});

async function until(condition, label) {
  const end = Date.now() + 5000;
  while (!condition()) {
    assert.ok(Date.now() < end, `Timed out: ${label}`);
    await new Promise(resolve => setTimeout(resolve, 20));
  }
}
async function stop(child) {
  if (!child || child.exitCode !== null || child.signalCode !== null) return;
  const closed = once(child, 'close');
  child.kill();
  await closed;
}

test('only one reader pulls jobs; heartbeat still works and exit releases its lock', { timeout: 20000 }, async () => {
  let pulls = 0;
  const server = http.createServer(async (req, res) => {
    let text = '';
    for await (const chunk of req) text += chunk;
    const { operation } = JSON.parse(text);
    if (operation === 'pull') pulls++;
    res.writeHead(200, { 'content-type': 'application/json', connection: 'close' });
    res.end(JSON.stringify({ status: operation === 'heartbeat' ? 'OK' : 'NO_JOB' }));
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  const reserve = net.createServer();
  reserve.listen(0, '127.0.0.1');
  await once(reserve, 'listening');
  const lockPort = reserve.address().port;
  await new Promise(resolve => reserve.close(resolve));
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'safet-reader-test-'));
  const token = path.join(tmp, 'bridge.token');
  fs.writeFileSync(token, 'test-only-not-a-real-credential-'.repeat(3));
  const children = [];
  function start(args = []) {
    const child = spawn(process.execPath, [worker, ...args], { windowsHide: true, env: {
      ...process.env, SELLER_CENTRAL_BRIDGE_TOKEN_FILE: token,
      SELLER_CENTRAL_STATUS_BRIDGE_ENDPOINT: `http://127.0.0.1:${server.address().port}/status`,
      SELLER_CENTRAL_STATUS_LOCK_PORT: String(lockPort),
    }, stdio: ['ignore', 'pipe', 'pipe'] });
    child.output = '';
    child.stdout.on('data', data => { child.output += data; });
    child.stderr.on('data', data => { child.output += data; });
    children.push(child);
    return child;
  }
  try {
    const first = start();
    await until(() => pulls === 1, 'first reader pull');
    const duplicate = start();
    await until(() => pulls > 1 || duplicate.exitCode !== null, 'duplicate rejection');
    assert.equal(pulls, 1, 'a duplicate reader must exit BEFORE claiming any job or touching CDP');
    assert.equal(duplicate.exitCode, 0);
    assert.match(duplicate.output, /worker_already_running/);
    const heartbeat = start(['--heartbeat']);
    await until(() => heartbeat.exitCode !== null, 'heartbeat exit');
    assert.equal(heartbeat.exitCode, 0);
    assert.match(heartbeat.output, /"status":"OK"/);
    assert.equal(pulls, 1);
    await stop(first);
    const replacement = start(['--once']);
    await until(() => replacement.exitCode !== null, 'replacement exit');
    assert.equal(replacement.exitCode, 0);
    assert.equal(pulls, 2, 'process exit must release the lock without manual cleanup');
  } finally {
    await Promise.all(children.map(stop));
    await new Promise(resolve => server.close(resolve));
    fs.rmSync(tmp, { recursive: true, force: true });
  }
});

test('post-appeal page with seller appeal after denial is pending', () => {
  const body = [
    'Detalhes da reivindicação: 45092-65513-7280005',
    'Negamos sua reivindicação SAFE-T referente ao pedido 702-4847212-7165801.',
    'Sua reivindicação não foi registrada dentro do prazo do recurso.',
    'Pedido 702-4847212-7165801, SAFE-T 45092-65513-7280005. Solicito reavaliação da decisão.',
  ].join('\n');
  const observation = parseSafeTStatus(body, { safe_t_id: '45092-65513-7280005', order_id: '702-4847212-7165801' });
  assert.equal(observation.claim_status, 'PENDING');
  assert.equal(observation.appeal_submitted, true);
});

test('new Amazon denial after seller appeal remains denied', () => {
  const body = [
    'Negamos sua reivindicação SAFE-T referente ao pedido 702-4847212-7165801.',
    'Pedido 702-4847212-7165801, SAFE-T 45092-65513-7280005. Solicito reavaliação da decisão.',
    'Analisamos seu recurso e negamos sua solicitação de reembolso.',
  ].join('\n');
  const observation = parseSafeTStatus(body, { safe_t_id: '45092-65513-7280005', order_id: '702-4847212-7165801' });
  assert.equal(observation.claim_status, 'DENIED');
});


test('read worker is host-neutral and delegates authentication to the shared helper', () => {
  assert.match(source, /from '\.\/seller-central-auth\.mjs'/);
  assert.match(source, /SELLER_CENTRAL_STATUS_WORKER_ID/);
  assert.match(source, /SELLER_CENTRAL_BROWSER/);
  assert.match(source, /SELLER_CENTRAL_OPERA/);
  assert.match(source, /ensureSellerCentralAuthenticated/);
  assert.doesNotMatch(source, /StrictHostKeyChecking=no/);
});
