#!/usr/bin/env node
import fs from 'node:fs';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { createHash } from 'node:crypto';
import { parseSafeTStatus } from './safe-t-status-parser.mjs';
import { classifyAmazonAuthState, ensureSellerCentralAuthenticated } from './seller-central-auth.mjs';

const ENDPOINT = process.env.SELLER_CENTRAL_STATUS_BRIDGE_ENDPOINT || 'https://returns.shopvivaliz.com.br/api/amazon-returns/status-bridge.php';
const TOKEN_FILE = process.env.SELLER_CENTRAL_BRIDGE_TOKEN_FILE || '';
const CDP_BASE = process.env.SELLER_CENTRAL_CDP_URL || 'http://127.0.0.1:9225';
const PROFILE = process.env.SELLER_CENTRAL_PROFILE || '';
const BROWSER = process.env.SELLER_CENTRAL_BROWSER || process.env.SELLER_CENTRAL_OPERA || '';
const STATUS_WORKER_ID = process.env.SELLER_CENTRAL_STATUS_WORKER_ID || 'seller-central-status';
const POLL_MS = Math.max(15000, Number(process.env.SELLER_CENTRAL_STATUS_POLL_MS || 30000));
const resultRetryRaw = Number(process.env.SELLER_CENTRAL_STATUS_RESULT_RETRY_MS || 1000);
const RESULT_RETRY_MS = Number.isFinite(resultRetryRaw) ? Math.max(10, Math.min(5000, Math.trunc(resultRetryRaw))) : 1000;
const RESULT_RETRY_ATTEMPTS = 3;
const QUIESCE_MARKER = process.env.AMAZON_RETURNS_QUIESCE_MARKER || '/run/amazon-returns-seller-central.quiesce';
const SAFE_T_BASE = 'https://sellercentral.amazon.com.br/safet-claims';
const CASE_LOBBY = 'https://sellercentral.amazon.com.br/cu/case-lobby';

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

function quiesceRequested() {
  try { return fs.existsSync(QUIESCE_MARKER); } catch { return false; }
}
const clean = value => String(value ?? '').replace(/\s+/g, ' ').trim();
const sha = value => createHash('sha256').update(String(value ?? '')).digest('hex');

function token() {
  const direct = String(process.env.SELLER_CENTRAL_BRIDGE_TOKEN ?? '').trim();
  if (direct) {
    if (direct.length < 32) throw new Error('bridge token missing or too short');
    return direct;
  }
  let value = '';
  try { value = fs.readFileSync(TOKEN_FILE, 'utf8').trim(); } catch {}
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
  if (!BROWSER || !fs.existsSync(BROWSER) || !fs.existsSync(PROFILE)) throw new Error('Seller Central browser profile unavailable');
  const port = new URL(CDP_BASE).port || '9225';
  const child = spawn(BROWSER, [
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
  throw new Error('Seller Central browser did not expose CDP');
}

async function closeCdpTarget(targetId) {
  const id = String(targetId || '').trim();
  if (!id) return;
  await fetch(`${CDP_BASE}/json/close/${encodeURIComponent(id)}`, {
    signal: AbortSignal.timeout(2500),
  }).catch(() => null);
}

class Cdp {
  constructor(ws, targetId = null, commandTimeoutMs = 15000) {
    this.ws = ws;
    this.targetId = targetId;
    this.commandTimeoutMs = Math.max(10, Number(commandTimeoutMs) || 15000);
    this.id = 0;
    this.pending = new Map();
    ws.addEventListener('message', event => {
      const message = JSON.parse(event.data);
      if (!message.id || !this.pending.has(message.id)) return;
      const waiter = this.pending.get(message.id);
      this.pending.delete(message.id);
      clearTimeout(waiter.timer);
      message.error ? waiter.reject(new Error(message.error.message || 'CDP error')) : waiter.resolve(message.result);
    });
    ws.addEventListener('close', () => this.rejectPending(new Error('CDP WebSocket closed')));
    ws.addEventListener('error', () => this.rejectPending(new Error('CDP WebSocket error')));
  }

  rejectPending(error) {
    const reason = error instanceof Error ? error : new Error(String(error || 'CDP connection closed'));
    for (const waiter of this.pending.values()) {
      clearTimeout(waiter.timer);
      waiter.reject(reason);
    }
    this.pending.clear();
  }

  static async connect() {
    await ensureBrowser();
    const response = await fetch(`${CDP_BASE}/json/new?${encodeURIComponent('about:blank')}`, { method: 'PUT' });
    if (!response.ok) throw new Error(`could not create isolated CDP target (${response.status})`);
    const page = await response.json();
    if (!page?.id || !page?.webSocketDebuggerUrl) {
      if (page?.id) await closeCdpTarget(page.id);
      throw new Error('isolated CDP page target unavailable');
    }
    let ws;
    try {
      ws = new WebSocket(page.webSocketDebuggerUrl);
      await new Promise((resolve, reject) => {
        ws.addEventListener('open', resolve, { once: true });
        ws.addEventListener('error', reject, { once: true });
      });
      return new Cdp(ws, page.id);
    } catch (error) {
      try { ws?.close(); } catch {}
      await closeCdpTarget(page.id);
      throw error;
    }
  }

  send(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = ++this.id;
      const timer = setTimeout(() => {
        if (!this.pending.delete(id)) return;
        reject(new Error(`CDP command timed out: ${method}`));
      }, this.commandTimeoutMs);
      this.pending.set(id, { resolve, reject, timer });
      try {
        this.ws.send(JSON.stringify({ id, method, params }));
      } catch (error) {
        clearTimeout(timer);
        this.pending.delete(id);
        reject(error);
      }
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

  async close() {
    try { this.ws.close(); } catch {}
    const targetId = this.targetId;
    this.targetId = null;
    await closeCdpTarget(targetId);
  }
}

function authState(state) {
  const classified = classifyAmazonAuthState(state);
  if (classified === 'AUTHENTICATED') return 'OK';
  if (classified === 'HUMAN_CHALLENGE') return 'HUMAN_CHALLENGE';
  return 'AUTH_REQUIRED';
}

async function authenticatedPage(cdp, targetUrl, waitMs) {
  await cdp.navigate(targetUrl, waitMs);
  let state = await cdp.pageState();
  let auth = authState(state);
  if (auth === 'OK' || auth === 'HUMAN_CHALLENGE') return { state, auth, reason: auth === 'HUMAN_CHALLENGE' ? 'CAPTCHA_PRESENT' : null };
  const recovery = await ensureSellerCentralAuthenticated(cdp);
  if (recovery.status === 'HUMAN_CHALLENGE') return { state: await cdp.pageState(), auth: 'HUMAN_CHALLENGE', reason: recovery.reason };
  if (recovery.status !== 'AUTHENTICATED') return { state: await cdp.pageState(), auth: 'AUTH_REQUIRED', reason: recovery.reason || 'SESSION_NOT_AUTHENTICATED' };
  await cdp.navigate(targetUrl, waitMs);
  state = await cdp.pageState();
  auth = authState(state);
  return { state, auth, reason: auth === 'HUMAN_CHALLENGE' ? 'CAPTCHA_PRESENT' : (auth === 'AUTH_REQUIRED' ? 'SESSION_NOT_AUTHENTICATED' : null) };
}

function result(status, extra = {}) {
  return { status, submitted: false, external_id: null, retry_safe: false, block_reason: null, next_allowed_at: null, reason: null, evidence: {}, read: null, support: null, ...extra };
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

async function safeTDiscovery(job) {
  const orderId = clean(job.case?.order_id);
  if (!/^\d{3}-\d{7}-\d{7}$/.test(orderId)) return result('FAILED', { reason: 'ORDER_ID_REQUIRED_FOR_DISCOVERY' });
  const days = Math.max(1, Math.min(90, Number(job.payload?.lookback_days || 90)));
  const cdp = await Cdp.connect();
  try {
    let page = await authenticatedPage(cdp, `${SAFE_T_BASE}?pageSize=100&dateFilterValue=${days}`, 4500);
    let state = page.state;
    let auth = page.auth;
    if (auth === 'AUTH_REQUIRED') return result('AUTH_REQUIRED', { reason: page.reason || 'SESSION_NOT_AUTHENTICATED', evidence: evidence(state) });
    if (auth === 'HUMAN_CHALLENGE') return result('HUMAN_CHALLENGE', { reason: page.reason || 'CAPTCHA_PRESENT', evidence: evidence(state) });
    const expected = JSON.stringify(orderId);
    const raw = await cdp.evaluate(`JSON.stringify([...document.querySelectorAll('div[id^="claim-content-wrapper-"]')].map(e=>{const safe=(e.id.match(/\\d{5}-\\d{5}-\\d{7}/)||[])[0]||'';const href=e.querySelector('a[href*="/orders-v3/order/"]')?.getAttribute('href')||'';const order=(href.match(/\\d{3}-\\d{7}-\\d{7}/)||[])[0]||'';return {safe,order}}).filter(x=>x.order===${expected}))`);
    const matches = JSON.parse(raw || '[]');
    const ids = [...new Set(matches.map(x => clean(x.safe)).filter(x => /^\d{5}-\d{5}-\d{7}$/.test(x)))];
    if (ids.length === 0) return result('NOT_FOUND', { reason: 'SAFE_T_NOT_FOUND_FOR_ORDER', retry_safe: true, evidence: evidence(state) });
    if (ids.length !== 1) return result('FAILED', { reason: 'MULTIPLE_SAFE_T_CLAIMS_FOR_ORDER', retry_safe: false, evidence: evidence(state) });
    const safeTId = ids[0];
    page = await authenticatedPage(cdp, `${SAFE_T_BASE}/claim/${encodeURIComponent(safeTId)}`, 4500);
    state = page.state;
    auth = page.auth;
    if (auth === 'AUTH_REQUIRED') return result('AUTH_REQUIRED', { reason: page.reason || 'SESSION_NOT_AUTHENTICATED', evidence: evidence(state) });
    if (auth === 'HUMAN_CHALLENGE') return result('HUMAN_CHALLENGE', { reason: page.reason || 'CAPTCHA_PRESENT', evidence: evidence(state) });
    if (!String(state.text || '').includes(orderId)) return result('UI_DRIFT', { reason: 'DISCOVERED_CLAIM_ORDER_MISMATCH', evidence: evidence(state) });
    const read = parseSafeTStatus(state.text || '', { safe_t_id: safeTId, order_id: orderId });
    return result('ACCEPTED', { external_id: safeTId, retry_safe: true, reason: 'SAFE_T_DISCOVERED_BY_ORDER', evidence: evidence(state), read });
  } finally {
    await cdp.close();
  }
}

async function safeTRead(job) {
  const safeTId = clean(job.case?.safe_t_id);
  const orderId = clean(job.case?.order_id);
  if (!/^\d{5}-\d{5}-\d{7}$/.test(safeTId)) return result('FAILED', { reason: 'SAFE_T_ID_REQUIRED' });
  const cdp = await Cdp.connect();
  try {
    const page = await authenticatedPage(cdp, `${SAFE_T_BASE}/claim/${encodeURIComponent(safeTId)}`, 5500);
    const state = page.state;
    const auth = page.auth;
    if (auth === 'AUTH_REQUIRED') return result('AUTH_REQUIRED', { reason: page.reason || 'SESSION_NOT_AUTHENTICATED', evidence: evidence(state) });
    if (auth === 'HUMAN_CHALLENGE') return result('HUMAN_CHALLENGE', { reason: page.reason || 'CAPTCHA_PRESENT', evidence: evidence(state) });
    const read = parseSafeTStatus(state.text || '', { safe_t_id: safeTId, order_id: orderId });
    return result('ACCEPTED', {
      external_id: safeTId,
      retry_safe: true,
      reason: read.claim_status === 'UNKNOWN' ? 'SAFE_T_STATUS_UNKNOWN' : 'SAFE_T_STATUS_READ',
      evidence: evidence(state),
      read,
    });
  } finally {
    await cdp.close();
  }
}

async function supportRead(job) {
  const supportCaseId = clean(job.case?.support_case_id);
  const orderId = clean(job.case?.order_id);
  const safeTId = clean(job.case?.safe_t_id);
  if (!/^\d{8,14}$/.test(supportCaseId)) return result('FAILED', { reason: 'SUPPORT_CASE_ID_REQUIRED' });
  const cdp = await Cdp.connect();
  try {
    const page = await authenticatedPage(cdp, CASE_LOBBY, 4500);
    const state = page.state;
    const auth = page.auth;
    if (auth === 'AUTH_REQUIRED') return result('AUTH_REQUIRED', { reason: page.reason || 'SESSION_NOT_AUTHENTICATED', evidence: evidence(state) });
    if (auth === 'HUMAN_CHALLENGE') return result('HUMAN_CHALLENGE', { reason: page.reason || 'CAPTCHA_PRESENT', evidence: evidence(state) });
    const raw = await cdp.evaluate(`(async()=>{
      const caseId=${JSON.stringify(supportCaseId)};
      const needles=${JSON.stringify([orderId, safeTId].filter(Boolean))};
      const view=async id=>{
        const response=await fetch('/hill/hillservice/mons-api/ViewCase?caseId='+encodeURIComponent(id)+'&timeZone=UTC&pageSize=10',{credentials:'include'});
        if(!response.ok)return null;
        return await response.json();
      };
      let detail=await view(caseId);
      let searchStatus='';
      if(!detail){
        for(let index=0;index<10;index++){
          const response=await fetch('/hill/hillservice/mons-api/SearchForCases',{
            method:'POST',credentials:'include',headers:{'content-type':'application/json'},
            body:JSON.stringify({page:index,searchPageSize:50,sortBy:'CreationDate',sortByOrder:'DESC',getCountOnly:false,caseFilters:{caseOwner:'MerchantCases'}})
          });
          if(!response.ok)break;
          const search=await response.json();
          const rows=Array.isArray(search.caseSearchResultList)?search.caseSearchResultList:[];
          const item=rows.find(row=>String(row?.caseId||'')===caseId);
          if(item){searchStatus=String(item.status||'');detail=await view(caseId);break;}
          if(rows.length<50)break;
        }
      }
      if(!detail)return JSON.stringify({status:'NOT_FOUND'});
      const serialized=JSON.stringify(detail);
      if(needles.length>0 && !needles.some(needle=>serialized.includes(needle)))return JSON.stringify({status:'MISMATCH'});
      const caseStatus=String(detail?.viewCaseMetaData?.caseStatus||searchStatus||'').trim();
      if(!caseStatus)return JSON.stringify({status:'INVALID'});
      const values=[];
      const walk=value=>{
        if(typeof value==='string'){const v=value.replace(/\\s+/g,' ').trim();if(v)values.push(v);return;}
        if(Array.isArray(value)){for(const item of value)walk(item);return;}
        if(value&&typeof value==='object'){for(const item of Object.values(value))walk(item);}
      };
      walk(detail);
      return JSON.stringify({status:'FOUND',case_status:caseStatus,latest_text:[...new Set(values)].join(' ').slice(0,12000)});
    })()`);
    let observed;
    try { observed = JSON.parse(raw || '{}'); } catch { observed = {}; }
    if (observed.status === 'NOT_FOUND') return result('NOT_FOUND', { reason: 'SELLER_SUPPORT_CASE_NOT_FOUND', retry_safe: true, evidence: evidence(state) });
    if (observed.status === 'MISMATCH') return result('NOT_FOUND', {
      external_id: supportCaseId,
      reason: 'SELLER_SUPPORT_CASE_IDENTITY_MISMATCH',
      retry_safe: true,
      evidence: evidence(state),
    });
    if (observed.status !== 'FOUND') return result('UI_DRIFT', { reason: 'SELLER_SUPPORT_CASE_RESPONSE_INVALID', retry_safe: true, evidence: evidence(state) });
    return result('ACCEPTED', {
      external_id: supportCaseId,
      retry_safe: true,
      reason: 'SELLER_SUPPORT_STATUS_READ',
      evidence: evidence(state),
      support: { case_id: supportCaseId, case_status: clean(observed.case_status), latest_text: clean(observed.latest_text).slice(0, 12000) },
    });
  } finally {
    await cdp.close();
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
  const pulled = await bridge('pull', { worker_id: STATUS_WORKER_ID });
  if (pulled.status === 'NO_JOB') return false;
  if (pulled.status !== 'JOB' || !pulled.job) throw new Error(`unexpected pull status ${clean(pulled.status)}`);
  const job = pulled.job;
  log('job_received', job);
  let readResult;
  try {
    if (job.action === 'SAFE_T_READ') readResult = await safeTRead(job);
    else if (job.action === 'SAFE_T_DISCOVERY') readResult = await safeTDiscovery(job);
    else if (job.action === 'SELLER_SUPPORT_READ') readResult = await supportRead(job);
    else readResult = result('FAILED', { reason: 'UNSUPPORTED_READ_ACTION' });
  } catch (error) {
    readResult = result('FAILED', { reason: `UNHANDLED_${error?.name || 'ERROR'}`, retry_safe: false });
  }
  let acknowledged = false;
  let lastResultError = null;
  for (let attempt = 1; attempt <= RESULT_RETRY_ATTEMPTS; attempt++) {
    try {
      await bridge('result', { job_id: job.job_id, idempotency_key: job.idempotency_key, result: readResult });
      acknowledged = true;
      break;
    } catch (error) {
      lastResultError = error;
      log('result_retry', { ...job, status: error?.name || 'Error' });
      if (attempt < RESULT_RETRY_ATTEMPTS) await sleep(RESULT_RETRY_MS * attempt);
    }
  }
  if (!acknowledged) throw lastResultError || new Error('read result acknowledgement failed');
  log('job_result', { ...job, ...readResult });
  return true;
}

async function acquireReadWorkerLease() {
  // Process-owned loopback lease: survives a runner restart, never a node exit.
  // A duplicate must not pull a job or navigate the shared Seller Central tab.
  const port = Number(process.env.SELLER_CENTRAL_STATUS_LOCK_PORT || 19225);
  if (!Number.isInteger(port) || port < 1 || port > 65535) {
    throw new Error('Invalid SELLER_CENTRAL_STATUS_LOCK_PORT');
  }
  const lease = createServer(socket => socket.destroy());
  return new Promise((resolve, reject) => {
    lease.once('error', error => {
      if (error.code === 'EADDRINUSE') resolve(null);
      else reject(error);
    });
    lease.listen({ host: '127.0.0.1', port, exclusive: true }, () => resolve(lease));
  });
}

async function runAuthCheck() {
  const cdp = await Cdp.connect();
  try {
    await cdp.navigate(SAFE_T_BASE, 4500);
    const auth = await ensureSellerCentralAuthenticated(cdp);
    if (auth.status === 'AUTH_REQUIRED' && /^[A-Z0-9_:-]{2,64}$/.test(String(auth.reason || ''))) {
      auth.status = auth.reason;
    }
    const heartbeat = await bridge('heartbeat', { worker_id: STATUS_WORKER_ID, auth_status: auth.status });
    process.stdout.write(`${JSON.stringify({ status: heartbeat.status || 'OK', auth_status: auth.status })}\n`);
    if (auth.status !== 'AUTHENTICATED') throw new Error('SellerCentralAuthCheckFailed');
  } finally {
    await cdp.close();
  }
}
async function main() {
  if (process.argv.includes('--auth-check')) {
    await runAuthCheck();
    return;
  }
  if (process.argv.includes('--heartbeat')) {
    process.stdout.write(`${JSON.stringify(await bridge('heartbeat', { worker_id: STATUS_WORKER_ID }))}\n`);
    return;
  }
  const lease = await acquireReadWorkerLease();
  if (!lease) {
    log('worker_already_running', { status: 'READ_WORKER_LEASE_HELD' });
    return;
  }
  try {
    if (process.argv.includes('--drain')) {
      while (!quiesceRequested() && await runOnce()) {}
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
  } finally {
    lease.close();
  }
}

main().catch(error => {
  log('fatal', { status: error?.name || 'Error' });
  process.exitCode = 1;
});
