import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import http from 'node:http';
import net from 'node:net';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { fileURLToPath } from 'node:url';

const worker = fileURLToPath(new URL('../scripts/amazon-returns/seller-central-safe-t-read-worker.mjs', import.meta.url));

async function freePort() {
  const server = net.createServer();
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  const port = server.address().port;
  await new Promise(resolve => server.close(resolve));
  return port;
}

test('drain mode empties queued jobs and exits on NO_JOB without touching the browser', { timeout: 12000 }, async () => {
  let pulls = 0;
  let results = 0;
  const server = http.createServer(async (req, res) => {
    let raw = '';
    for await (const chunk of req) raw += chunk;
    const body = JSON.parse(raw);
    let payload;
    if (body.operation === 'pull') {
      pulls++;
      payload = pulls <= 2 ? {
        status: 'JOB',
        job: {
          job_id: pulls,
          action: 'NO_BROWSER_TEST',
          idempotency_key: 'a'.repeat(64),
        },
      } : { status: 'NO_JOB' };
    } else if (body.operation === 'result') {
      results++;
      payload = { status: 'ACK' };
    } else {
      payload = { status: 'OK' };
    }
    res.writeHead(200, { 'content-type': 'application/json', connection: 'close' });
    res.end(JSON.stringify(payload));
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'safet-drain-test-'));
  const token = path.join(tmp, 'bridge.token');
  fs.writeFileSync(token, 'test-only-not-a-real-credential-'.repeat(3));
  const lockPort = await freePort();
  const child = spawn(process.execPath, [worker, '--drain'], {
    windowsHide: true,
    env: {
      ...process.env,
      SELLER_CENTRAL_BRIDGE_TOKEN_FILE: token,
      SELLER_CENTRAL_STATUS_BRIDGE_ENDPOINT: `http://127.0.0.1:${server.address().port}/status`,
      SELLER_CENTRAL_STATUS_LOCK_PORT: String(lockPort),
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let output = '';
  child.stdout.on('data', chunk => { output += chunk; });
  child.stderr.on('data', chunk => { output += chunk; });
  const [code] = await once(child, 'close');
  await new Promise(resolve => server.close(resolve));
  fs.rmSync(tmp, { recursive: true, force: true });
  assert.equal(code, 0, output);
  assert.equal(pulls, 3, 'drain must stop immediately after the first NO_JOB response');
  assert.equal(results, 2, 'drain must acknowledge every queued job before exit');
  assert.doesNotMatch(output, /fatal|worker_error/i);
});

test('drain retries a transient result delivery failure without abandoning the claimed read job', { timeout: 12000 }, async () => {
  let pulls = 0;
  let resultAttempts = 0;
  const server = http.createServer(async (req, res) => {
    let raw = '';
    for await (const chunk of req) raw += chunk;
    const body = JSON.parse(raw);
    if (body.operation === 'pull') {
      pulls++;
      res.writeHead(200, { 'content-type': 'application/json', connection: 'close' });
      res.end(JSON.stringify(pulls === 1 ? {
        status: 'JOB',
        job: { job_id: 77, action: 'NO_BROWSER_TEST', idempotency_key: 'b'.repeat(64) },
      } : { status: 'NO_JOB' }));
      return;
    }
    if (body.operation === 'result') {
      resultAttempts++;
      if (resultAttempts === 1) {
        res.writeHead(503, { 'content-type': 'application/json', connection: 'close' });
        res.end(JSON.stringify({ status: 'TEMPORARY_FAILURE' }));
        return;
      }
      res.writeHead(200, { 'content-type': 'application/json', connection: 'close' });
      res.end(JSON.stringify({ status: 'ACK' }));
      return;
    }
    res.writeHead(200, { 'content-type': 'application/json', connection: 'close' });
    res.end(JSON.stringify({ status: 'OK' }));
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'safet-drain-result-retry-'));
  const token = path.join(tmp, 'bridge.token');
  fs.writeFileSync(token, 'test-only-not-a-real-credential-'.repeat(3));
  const lockPort = await freePort();
  const child = spawn(process.execPath, [worker, '--drain'], {
    windowsHide: true,
    env: {
      ...process.env,
      SELLER_CENTRAL_BRIDGE_TOKEN_FILE: token,
      SELLER_CENTRAL_STATUS_BRIDGE_ENDPOINT: `http://127.0.0.1:${server.address().port}/status`,
      SELLER_CENTRAL_STATUS_LOCK_PORT: String(lockPort),
      SELLER_CENTRAL_STATUS_RESULT_RETRY_MS: '20',
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let output = '';
  child.stdout.on('data', chunk => { output += chunk; });
  child.stderr.on('data', chunk => { output += chunk; });
  const [code] = await once(child, 'close');
  await new Promise(resolve => server.close(resolve));
  fs.rmSync(tmp, { recursive: true, force: true });
  assert.equal(code, 0, output);
  assert.equal(resultAttempts, 2, 'the same claimed job result must be retried before drain advances');
  assert.equal(pulls, 2, 'worker may pull again only after result acknowledgement succeeds');
  assert.doesNotMatch(output, /fatal/i);
});
