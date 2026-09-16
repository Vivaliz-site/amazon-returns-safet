#!/usr/bin/env node
import { classifyAmazonAuthState, ensureSellerCentralAuthenticated } from './seller-central-auth.mjs';

const CDP_BASE = process.env.SELLER_CENTRAL_CDP_URL || 'http://127.0.0.1:9225';
const CASE_LOBBY = 'https://sellercentral.amazon.com.br/cu/case-lobby';
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const clean = value => String(value ?? '').replace(/\s+/g, ' ').trim().slice(0, 160);

async function closeTarget(targetId) {
  const id = String(targetId ?? '').trim();
  if (!id) return;
  await fetch(`${CDP_BASE}/json/close/${encodeURIComponent(id)}`, { signal: AbortSignal.timeout(2500) }).catch(() => null);
}

class ProbeCdp {
  constructor(ws, targetId) {
    this.ws = ws;
    this.targetId = targetId;
    this.id = 0;
    this.pending = new Map();
    ws.addEventListener('message', event => {
      const message = JSON.parse(event.data);
      if (!message.id || !this.pending.has(message.id)) return;
      const waiter = this.pending.get(message.id);
      this.pending.delete(message.id);
      clearTimeout(waiter.timer);
      message.error ? waiter.reject(new Error('CDP_ERROR')) : waiter.resolve(message.result);
    });
  }

  static async connect() {
    const response = await fetch(`${CDP_BASE}/json/new?${encodeURIComponent('about:blank')}`, { method: 'PUT', signal: AbortSignal.timeout(5000) });
    if (!response.ok) throw new Error('CDP_TARGET_CREATE_FAILED');
    const target = await response.json();
    if (!target?.id || !target?.webSocketDebuggerUrl) throw new Error('CDP_TARGET_UNAVAILABLE');
    const ws = new WebSocket(target.webSocketDebuggerUrl);
    try {
      await new Promise((resolve, reject) => {
        ws.addEventListener('open', resolve, { once: true });
        ws.addEventListener('error', reject, { once: true });
      });
      return new ProbeCdp(ws, target.id);
    } catch (error) {
      await closeTarget(target.id);
      throw error;
    }
  }

  send(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = ++this.id;
      const timer = setTimeout(() => {
        if (!this.pending.delete(id)) return;
        reject(new Error('CDP_TIMEOUT'));
      }, 15000);
      this.pending.set(id, { resolve, reject, timer });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }

  async evaluate(expression) {
    const result = await this.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error('BROWSER_EVALUATION_FAILED');
    return result.result?.value;
  }

  async navigate(url, waitMs = 4500) {
    await this.send('Page.navigate', { url });
    await sleep(waitMs);
  }

  async pageState(limit = 6000) {
    const value = await this.evaluate(`JSON.stringify({href:location.href,title:document.title,text:(document.body?.innerText||'').slice(0,${limit})})`);
    try { return JSON.parse(value || '{}'); } catch { return {}; }
  }

  async close() {
    try { this.ws.close(); } catch {}
    await closeTarget(this.targetId);
  }
}

function safeResult(data = {}) {
  return {
    at: new Date().toISOString(),
    event: 'support_lookup_probe',
    status: clean(data.status || 'UNAVAILABLE'),
    reason: clean(data.reason || 'UNKNOWN'),
    auth_state: clean(data.auth_state || 'UNKNOWN'),
    http_status: Number.isInteger(data.http_status) ? data.http_status : null,
    content_type: clean(data.content_type || ''),
    response_keys: Array.isArray(data.response_keys) ? data.response_keys.map(clean).filter(Boolean).slice(0, 20) : [],
    list_is_array: data.list_is_array === true,
    total_is_numeric: data.total_is_numeric === true,
  };
}

async function probeSupportCaseLookup() {
  const cdp = await ProbeCdp.connect();
  try {
    await cdp.navigate(CASE_LOBBY, 4500);
    let state = await cdp.pageState();
    let authState = classifyAmazonAuthState(state);
    const auth = await ensureSellerCentralAuthenticated(cdp);
    if (auth?.status !== 'AUTHENTICATED') {
      return safeResult({ status: auth?.status || 'AUTH_REQUIRED', reason: auth?.reason || 'AUTH_NOT_READY', auth_state: authState });
    }

    await cdp.navigate(CASE_LOBBY, 4500);
    state = await cdp.pageState();
    authState = classifyAmazonAuthState(state);
    if (authState !== 'AUTHENTICATED') {
      return safeResult({ status: 'AUTH_REQUIRED', reason: 'CASE_LOBBY_NOT_AUTHENTICATED', auth_state: authState });
    }

    const raw = await cdp.evaluate(`(async()=>{
      try{
        const response=await fetch('/hill/hillservice/mons-api/SearchForCases',{
          method:'POST',credentials:'include',headers:{'content-type':'application/json'},
          body:JSON.stringify({page:0,searchPageSize:50,sortBy:'CreationDate',sortByOrder:'DESC',getCountOnly:false,caseFilters:{caseOwner:'MerchantCases'}})
        });
        let parsed=null;
        try{parsed=await response.json()}catch{}
        const keys=parsed&&typeof parsed==='object'&&!Array.isArray(parsed)?Object.keys(parsed).slice(0,20):[];
        const listOk=Array.isArray(parsed?.caseSearchResultList);
        const totalOk=Number.isFinite(Number(parsed?.totalNumberOfResults));
        return JSON.stringify({
          status:response.ok&&listOk&&totalOk?'OK':'UNAVAILABLE',
          reason:!response.ok?'SEARCH_HTTP_'+response.status:(!listOk||!totalOk?'SEARCH_RESPONSE_INVALID':'SEARCH_CONTRACT_OK'),
          http_status:response.status,
          content_type:String(response.headers.get('content-type')||'').slice(0,160),
          response_keys:keys,
          list_is_array:listOk,
          total_is_numeric:totalOk
        });
      }catch{return JSON.stringify({status:'UNAVAILABLE',reason:'SEARCH_REQUEST_FAILED',http_status:null,content_type:'',response_keys:[],list_is_array:false,total_is_numeric:false})}
    })()`);
    let parsed = {};
    try { parsed = JSON.parse(raw || '{}'); } catch {}
    return safeResult({ ...parsed, auth_state: authState });
  } finally {
    await cdp.close();
  }
}

try {
  const result = await probeSupportCaseLookup();
  process.stdout.write(`${JSON.stringify(result)}\n`);
} catch (error) {
  process.stdout.write(`${JSON.stringify(safeResult({ status: 'FAILED', reason: `PROBE_${clean(error?.name || 'ERROR')}` }))}\n`);
  process.exitCode = 1;
}
