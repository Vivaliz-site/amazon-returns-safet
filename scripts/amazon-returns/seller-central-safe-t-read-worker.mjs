#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { parseSafeTStatus } from './safe-t-status-parser.mjs';

const ENDPOINT = process.env.SELLER_CENTRAL_STATUS_BRIDGE_ENDPOINT || 'https://shopvivaliz.com.br/api/amazon-returns/status-bridge.php';
const TOKEN_FILE = process.env.SELLER_CENTRAL_BRIDGE_TOKEN_FILE || 'C:\\ShopVivaliz\\amazon-returns-bridge\\bridge.token';
const CDP_BASE = process.env.SELLER_CENTRAL_CDP_URL || 'http://127.0.0.1:9225';
const PROFILE = process.env.SELLER_CENTRAL_PROFILE || 'C:\\ShopVivaliz\\amazon-returns-bridge\\profile';
const LOCALAPPDATA = process.env.LOCALAPPDATA || '';
const OPERA = process.env.SELLER_CENTRAL_OPERA || [
  path.join(LOCALAPPDATA, 'Programs', 'Opera developer', 'opera.exe'),
  path.join(LOCALAPPDATA, 'Programs', 'Opera', 'opera.exe'),
].find(candidate => candidate && fs.existsSync(candidate)) || '';
const POLL_MS = Math.max(15000, Number(process.env.SELLER_CENTRAL_STATUS_POLL_MS || 30000));
const SAFE_T_BASE = 'https://sellercentral.amazon.com.br/safet-claims';

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const clean = value => String(value ?? '').replace(/\s+/g, ' ').trim();
const sha = value => createHash('sha256').update(String(value ?? '')).digest('hex');

function token() {
  const value = fs.readFileSync(TOKEN_FILE, 'utf8').trim();
  if (value.length < 32) throw new Error('bridge token missing or too short');
  return value;
}

async function bridge(operation, payload = {}) {
  const response = await fetch(ENDPOINT, {
    method: 'POST',
    headers: {
      authorization: `Bearer ${token()}`,
      'content-type': 'application/json',
      accept: 'application/json',
      'user-agent': 'ShopVivaliz-SafeTStatusBridge/1.0',
    },
    body: JSON.stringify({ operation, ...payload }),
    signal: AbortSignal.timeout(45000),
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(`status bridge HTTP ${response.status}: ${clean(body.status)}`);
  return body;
}

async function cdpReady() {
  try {
    const response = await fetch(`${CDP_BASE}/json/version`, { signal: AbortSignal.timeout(2500) });
    const data = await response.json();
    return Boolean(data.webSocketDebuggerUrl);
  } catch {
    return false;
  }
}

async function ensureBrowser() {
  if (await cdpReady()) return;
  if (!OPERA || !fs.existsSync(OPERA) || !fs.existsSync(PROFILE)) throw new Error('Seller Central Opera profile unavailable');
  const port = new URL(CDP_BASE).port || '9225';
  const child = spawn(OPERA, [
    '--headless=new',
    '--disable-gpu',
    `--remote-debugging-port=${port}`,
    `--user-data-dir=${PROFILE}`,
    '--no-first-run',
    '--no-default-browser-check',
  ], { detached: true, stdio: 'ignore', windowsHide: true });
  child.unref();
  for (let attempt = 0; attempt < 20; attempt++) {
    await sleep(500);
    if (await cdpReady()) return;
  }
  throw new Error('Seller Central Opera did not expose CDP');
}

class Cdp {
  constructor(ws) {
    this.ws = ws;
    this.id = 0;
    this.pending = new Map();
    ws.addEventListener('message', event => {
      const message = JSON.parse(event.data);
      if (!message.id || !this.pending.has(message.id)) return;
      const waiter = this.pending.get(message.id);
      this.pending.delete(message.id);
      message.error ? waiter.reject(new Error(message.error.message || 'CDP error')) : waiter.resolve(message.result);
    });
    ws.addEventListener('close', () => this.rejectPending(new Error('CDP WebSocket closed')));
    ws.addEventListener('error', () => this.rejectPending(new Error('CDP WebSocket error')));
  }

  rejectPending(error) {
    const reason = error instanceof Error ? error : new Error(String(error || 'CDP connection closed'));
    for (const waiter of this.pending.values()) waiter.reject(reason);
    this.pending.clear();
  }

  static async connect() {
    await ensureBrowser();
    const tabs = await (await fetch(`${CDP_BASE}/json`)).json();
    const page = tabs.find(tab => tab.type === 'page');
    if (!page?.webSocketDebuggerUrl) throw new Error('no CDP page target');
    const ws = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
      ws.addEventListener('open', resolve, { once: true });
      ws.addEventListener('error', reject, { once: true });
    });
    return new Cdp(ws);
  }

  send(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = ++this.id;
      this.pending.set(id, { resolve, reject });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }

  async evaluate(expression) {
    const result = await this.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error('browser expression failed');
    return result.result?.value;
  }

  async navigate(url, waitMs = 5000) {
    await this.send('Page.navigate', { url });
    await sleep(waitMs);
  }

  async pageState(limit = 30000) {
    const raw = await this.evaluate(`JSON.stringify({href:location.href,title:document.title,text:(document.body?.innerText||'').slice(0,${limit})})`);
    return JSON.parse(raw || '{}');
  }

  close() { try { this.ws.close(); } catch {} }
}

function authState(state) {
  const combined = `${state.href || ''}\n${state.title || ''}\n${state.text || ''}`.toLowerCase();
  if (combined.includes('/signin') || combined.includes('iniciar sessão') || combined.includes('sign in') || combined.includes('acessar amazon')) return 'AUTH_REQUIRED';
  if (combined.includes('captcha') || combined.includes('digite os caracteres')) return 'HUMAN_CHALLENGE';
  return 'OK';
}

function result(status, extra = {}) {
  return { status, submitted: false, external_id: null, retry_safe: false, block_reason: null, next_allowed_at: null, reason: null, evidence: {}, read: null, ...extra };
}

function evidence(state) {
  const safe = {
    ui_contract: 'safet-status-v1',
    current_url: state.href || '',
    title: state.title || '',
    body_sha256: sha(state.text || ''),
  };
  return { ...safe, snapshot_sha256: sha(JSON.stringify(safe)) };
}

async function safeTRead(job) {
  const safeTId = clean(job.case?.safe_t_id);
  const orderId = clean(job.case?.order_id);
  if (!/^\d{5}-\d{5}-\d{7}$/.test(safeTId)) return result('FAILED', { reason: 'SAFE_T_ID_REQUIRED' });
  const cdp = await Cdp.connect();
  try {
    await cdp.navigate(`${SAFE_T_BASE}/claim/${encodeURIComponent(safeTId)}`, 5500);
    const state = await cdp.pageState();
    const auth = authState(state);
    if (auth === 'AUTH_REQUIRED') return result('AUTH_REQUIRED', { reason: 'SESSION_NOT_AUTHENTICATED', evidence: evidence(state) });
    if (auth === 'HUMAN_CHALLENGE') return result('HUMAN_CHALLENGE', { reason: 'CAPTCHA_PRESENT', evidence: evidence(state) });
    const read = parseSafeTStatus(state.text || '', { safe_t_id: safeTId, order_id: orderId });
    return result('ACCEPTED', {
      external_id: safeTId,
      retry_safe: true,
      reason: read.claim_status === 'UNKNOWN' ? 'SAFE_T_STATUS_UNKNOWN' : 'SAFE_T_STATUS_READ',
      evidence: evidence(state),
      read,
    });
  } finally {
    cdp.close();
  }
}

function log(event, data = {}) {
  process.stdout.write(`${JSON.stringify({
    at: new Date().toISOString(), event,
    job_id: data.job_id ?? null,
    action: data.action ?? null,
    status: data.status ?? null,
    external_id: data.external_id ?? null,
    claim_status: data.read?.claim_status ?? null,
  })}\n`);
}

async function runOnce() {
  const pulled = await bridge('pull', { worker_id: 'fred-win-safe-t-status' });
  if (pulled.status === 'NO_JOB') return false;
  if (pulled.status !== 'JOB' || !pulled.job) throw new Error(`unexpected pull status ${clean(pulled.status)}`);
  const job = pulled.job;
  log('job_received', job);
  let readResult;
  try {
    if (job.action !== 'SAFE_T_READ') readResult = result('FAILED', { reason: 'UNSUPPORTED_READ_ACTION' });
    else readResult = await safeTRead(job);
  } catch (error) {
    readResult = result('FAILED', { reason: `UNHANDLED_${error?.name || 'ERROR'}`, retry_safe: false });
  }
  await bridge('result', { job_id: job.job_id, idempotency_key: job.idempotency_key, result: readResult });
  log('job_result', { ...job, ...readResult });
  return true;
}

async function main() {
  if (process.argv.includes('--heartbeat')) {
    process.stdout.write(`${JSON.stringify(await bridge('heartbeat', { worker_id: 'fred-win-safe-t-status' }))}\n`);
    return;
  }
  if (process.argv.includes('--once')) {
    await runOnce();
    return;
  }
  log('worker_started');
  while (true) {
    try {
      const processed = await runOnce();
      if (!processed) await sleep(POLL_MS);
    } catch (error) {
      log('worker_error', { status: error?.name || 'Error' });
      await sleep(Math.max(POLL_MS, 30000));
    }
  }
}

main().catch(error => {
  log('fatal', { status: error?.name || 'Error' });
  process.exitCode = 1;
});
