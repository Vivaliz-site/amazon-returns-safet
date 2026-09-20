#!/usr/bin/env node
import fs from 'node:fs';
import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { captureTrackingEvidence, attachFiles, withTrackingEvidence } from './TrackingEvidence.mjs';
import { classifyAmazonAuthState, ensureSellerCentralAuthenticated } from './seller-central-auth.mjs';

const ENDPOINT = process.env.SELLER_CENTRAL_BRIDGE_ENDPOINT || 'https://returns.shopvivaliz.com.br/api/amazon-returns/bridge.php';
const TOKEN_FILE = process.env.SELLER_CENTRAL_BRIDGE_TOKEN_FILE || '';
const CDP_BASE = process.env.SELLER_CENTRAL_CDP_URL || 'http://127.0.0.1:9225';
const PROFILE = process.env.SELLER_CENTRAL_PROFILE || '';
const BROWSER = process.env.SELLER_CENTRAL_BROWSER || process.env.SELLER_CENTRAL_OPERA || '';
const WORKER_ID = process.env.SELLER_CENTRAL_WORKER_ID || 'seller-central-browser';
const POLL_MS = Math.max(10000, Number(process.env.SELLER_CENTRAL_BRIDGE_POLL_MS || 30000));
const QUIESCE_MARKER = process.env.AMAZON_RETURNS_QUIESCE_MARKER || '/run/amazon-returns-seller-central.quiesce';
const SAFE_T_BASE = 'https://sellercentral.amazon.com.br/safet-claims';
const HELP_URL = 'https://sellercentral.amazon.com.br/help/center?redirectSource=Hill';
const CASE_LOBBY = 'https://sellercentral.amazon.com.br/cu/case-lobby';
const supportHistoryRaw = Number(process.env.SELLER_CENTRAL_SUPPORT_CASE_HISTORY_LIMIT || 500);
const SUPPORT_CASE_HISTORY_LIMIT = Number.isFinite(supportHistoryRaw)
  ? Math.max(50, Math.min(500, Math.trunc(supportHistoryRaw)))
  : 500;
const SUPPORT_CASE_TERMINAL_STATUSES = ['RESOLVED','CLOSED','CANCELLED'];
const supportLookupTimeoutRaw = Number(process.env.SELLER_CENTRAL_SUPPORT_LOOKUP_COMMAND_TIMEOUT_MS || 120000);
const SUPPORT_CASE_LOOKUP_COMMAND_TIMEOUT_MS = Number.isFinite(supportLookupTimeoutRaw)
  ? Math.max(60000, Math.min(300000, Math.trunc(supportLookupTimeoutRaw)))
  : 120000;
const supportLookupBudgetRaw = Number(process.env.SELLER_CENTRAL_SUPPORT_LOOKUP_SCAN_BUDGET_MS || 90000);
const SUPPORT_CASE_LOOKUP_SCAN_BUDGET_MS = Number.isFinite(supportLookupBudgetRaw)
  ? Math.max(30000, Math.min(SUPPORT_CASE_LOOKUP_COMMAND_TIMEOUT_MS - 10000, Math.trunc(supportLookupBudgetRaw)))
  : 90000;

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

function quiesceRequested() {
  try { return fs.existsSync(QUIESCE_MARKER); } catch { return false; }
}
const text = value => String(value ?? '').replace(/\s+/g, ' ').trim();
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
      'authorization': `Bearer ${token()}`,
      'content-type': 'application/json',
      'accept': 'application/json',
      'user-agent': 'ShopVivaliz-AmazonReturnsBridge/1.0',
    },
    body: JSON.stringify({ operation, ...payload }),
    signal: AbortSignal.timeout(45000),
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(`bridge HTTP ${response.status}: ${text(body.status)}`);
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
  const child = spawn(BROWSER, [
    '--headless=new',
    '--disable-gpu',
    '--remote-debugging-port=9225',
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
    if (result.exceptionDetails) {
      const details = result.exceptionDetails;
      const description = text(details.exception?.description || details.text || 'browser expression failed').replace(/\s+/g, ' ').slice(0, 240);
      const lineNumber = Number.isInteger(details.lineNumber) ? details.lineNumber : -1;
      const columnNumber = Number.isInteger(details.columnNumber) ? details.columnNumber : -1;
      const expressionPrefix = String(expression)
        .replace(/"(?:\\.|[^"\\])*"/g, '"…"')
        .replace(/'(?:\\.|[^'\\])*'/g, "'…'")
        .replace(/\s+/g, ' ')
        .slice(0, 120);
      const stackSummary = text(new Error().stack?.split('\n').slice(2, 5).join(' | ') || '').slice(0, 240);
      throw new Error(`browser expression failed: ${description} @${lineNumber}:${columnNumber} expr=${expressionPrefix} caller=${stackSummary}`);
    }
    return result.result?.value;
  }

  async navigate(url, waitMs = 5000) {
    await this.send('Page.navigate', { url });
    await sleep(waitMs);
  }

  async pageState(limit = 12000) {
    return this.evaluate(`JSON.stringify({href:location.href,title:document.title,text:(document.body?.innerText||'').slice(0,${limit})})`)
      .then(value => JSON.parse(value || '{}'));
  }

  async waitFor(expression, timeoutMs = 20000, intervalMs = 500) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
      if (await this.evaluate(expression)) return true;
      await sleep(intervalMs);
    }
    return false;
  }

  async setKat(selector, value, frame = false) {
    const serialized = JSON.stringify(String(value));
    const expression = `(()=>{const d=${frame ? "[...document.querySelectorAll('iframe')].map(f=>f.contentDocument).find(d=>d?.querySelector(" + JSON.stringify(selector) + "))" : 'document'};`
      + `const h=d?.querySelector(${JSON.stringify(selector)});const i=h?.shadowRoot?.querySelector('input,textarea');if(!h||!i)return false;`
      + `const proto=i.tagName==='TEXTAREA'?HTMLTextAreaElement.prototype:HTMLInputElement.prototype;Object.getOwnPropertyDescriptor(proto,'value').set.call(i,${serialized});`
      + `i.dispatchEvent(new InputEvent('input',{bubbles:true,composed:true,inputType:'insertText',data:${serialized}}));i.dispatchEvent(new Event('change',{bubbles:true,composed:true}));return h.value===${serialized}||i.value===${serialized}})()`;
    return (await this.evaluate(expression)) === true;
  }

  async clickKat(selector, frame = false) {
    const docExpr = frame ? "[...document.querySelectorAll('iframe')].map(f=>f.contentDocument).find(d=>d?.querySelector(" + JSON.stringify(selector) + "))" : 'document';
    return (await this.evaluate(`(()=>{const d=${docExpr};const h=d?.querySelector(${JSON.stringify(selector)});const b=h?.shadowRoot?.querySelector('button,.checkbox,[role=checkbox]');if(!b||b.disabled)return false;b.click();return true})()`)) === true;
  }

  async clickHillButtonTrusted(selector) {
    const point = await this.evaluate(`(()=>{const selector=${JSON.stringify(selector)};for(const f of document.querySelectorAll('iframe')){const outer=f.contentDocument;const hill=outer?.querySelector('spl-hill-form');const innerFrame=hill?.shadowRoot?.querySelector('iframe');const inner=innerFrame?.contentDocument;if(!inner)continue;const host=inner.querySelector(selector);const button=host?.tagName==='KAT-BUTTON'?host.shadowRoot?.querySelector('button'):host;if(!button||button.disabled)continue;f.scrollIntoView({block:'center'});hill.scrollIntoView({block:'center'});button.scrollIntoView({block:'center'});const a=f.getBoundingClientRect(),b=innerFrame.getBoundingClientRect(),c=button.getBoundingClientRect();const x=a.left+b.left+c.left+(c.width/2),y=a.top+b.top+c.top+(c.height/2);if(!Number.isFinite(x)||!Number.isFinite(y)||x<0||y<0||x>innerWidth||y>innerHeight)return null;return {x,y}}return null})()`);
    if (!point || !Number.isFinite(point.x) || !Number.isFinite(point.y)) return false;
    await this.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: point.x, y: point.y, button: 'none' });
    await this.send('Input.dispatchMouseEvent', { type: 'mousePressed', x: point.x, y: point.y, button: 'left', clickCount: 1 });
    await this.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: point.x, y: point.y, button: 'left', clickCount: 1 });
    return true;
  }

  async frameButtonReadyByText(label) {
    return (await this.evaluate(`(()=>{const label=${JSON.stringify(label)};for(const f of document.querySelectorAll('iframe')){const d=f.contentDocument;if(!d)continue;for(const host of d.querySelectorAll('kat-button,button')){const text=(host.getAttribute('label')||host.innerText||'').trim();if(text!==label)continue;const button=host.tagName==='KAT-BUTTON'?host.shadowRoot?.querySelector('button'):host;if(button&&!button.disabled)return true}}return false})()`)) === true;
  }

  async frameOptionSelectedByText(label) {
    return (await this.evaluate(`(()=>{const label=${JSON.stringify(label)};for(const f of document.querySelectorAll('iframe')){const d=f.contentDocument;if(!d)continue;for(const host of d.querySelectorAll('kat-button,button')){const text=(host.getAttribute('label')||host.innerText||'').trim();if(text!==label)continue;const cls=String(host.className||'');if(cls.split(/\\s+/).includes('selected-option'))return true}}return false})()`)) === true;
  }

  async clickFrameButtonTrustedByText(label) {
    const evaluated = await this.send('Runtime.evaluate', {
      expression: `(()=>{const label=${JSON.stringify(label)};for(const f of document.querySelectorAll('iframe')){const d=f.contentDocument;if(!d)continue;for(const host of d.querySelectorAll('kat-button,button')){const text=(host.getAttribute('label')||host.innerText||'').trim();if(text!==label)continue;const button=host.tagName==='KAT-BUTTON'?host.shadowRoot?.querySelector('button'):host;if(!button||button.disabled)continue;return button}}return null})()`,
      returnByValue: false,
      awaitPromise: true,
    });
    const objectId = evaluated?.result?.objectId;
    if (!objectId) return false;
    try {
      await this.send('DOM.scrollIntoViewIfNeeded', { objectId });
      await sleep(120);
      const box = await this.send('DOM.getBoxModel', { objectId });
      const quad = box?.model?.content;
      if (!Array.isArray(quad) || quad.length < 8) return false;
      const x = (Number(quad[0]) + Number(quad[2]) + Number(quad[4]) + Number(quad[6])) / 4;
      const y = (Number(quad[1]) + Number(quad[3]) + Number(quad[5]) + Number(quad[7])) / 4;
      if (!Number.isFinite(x) || !Number.isFinite(y)) return false;
      await this.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y, button: 'none' });
      await this.send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 1 });
      await this.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 1 });
      return true;
    } catch {
      return false;
    } finally {
      await this.send('Runtime.releaseObject', { objectId }).catch(() => {});
    }
  }

  async fillFrameTextareaTrusted(selector, value) {
    const expected = String(value ?? '');
    if (!selector || expected === '') return false;
    const evaluated = await this.send('Runtime.evaluate', {
      expression: `(()=>{const selector=${JSON.stringify(selector)};for(const f of document.querySelectorAll('iframe')){const d=f.contentDocument;if(!d)continue;const host=d.querySelector(selector);const textarea=host?.tagName==='KAT-TEXTAREA'?host.shadowRoot?.querySelector('textarea'):host?.tagName==='TEXTAREA'?host:null;if(!textarea||host?.hasAttribute?.('disabled')||textarea.disabled)continue;return textarea}return null})()`,
      returnByValue: false,
      awaitPromise: true,
    });
    const objectId = evaluated?.result?.objectId;
    if (!objectId) return false;
    try {
      await this.send('DOM.scrollIntoViewIfNeeded', { objectId });
      await sleep(120);
      const box = await this.send('DOM.getBoxModel', { objectId });
      const quad = box?.model?.content;
      if (!Array.isArray(quad) || quad.length < 8) return false;
      const x = (Number(quad[0]) + Number(quad[2]) + Number(quad[4]) + Number(quad[6])) / 4;
      const y = (Number(quad[1]) + Number(quad[3]) + Number(quad[5]) + Number(quad[7])) / 4;
      if (!Number.isFinite(x) || !Number.isFinite(y)) return false;
      await this.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y, button: 'none' });
      await this.send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 1 });
      await this.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 1 });
      await this.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'a', code: 'KeyA', modifiers: 2, windowsVirtualKeyCode: 65, nativeVirtualKeyCode: 65 });
      await this.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'a', code: 'KeyA', modifiers: 2, windowsVirtualKeyCode: 65, nativeVirtualKeyCode: 65 });
      await this.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Backspace', code: 'Backspace', windowsVirtualKeyCode: 8, nativeVirtualKeyCode: 8 });
      await this.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Backspace', code: 'Backspace', windowsVirtualKeyCode: 8, nativeVirtualKeyCode: 8 });
      await this.send('Input.insertText', { text: expected });
      await sleep(300);
      return (await this.send('Runtime.callFunctionOn', {
        objectId,
        functionDeclaration: 'function(expected){return this.value===expected}',
        arguments: [{ value: expected }],
        returnByValue: true,
      }))?.result?.value === true;
    } catch {
      return false;
    } finally {
      await this.send('Runtime.releaseObject', { objectId }).catch(() => {});
    }
  }

  async fillFrameInputTrustedByLabel(labels, value) {
    const wanted = [...new Set((Array.isArray(labels) ? labels : [labels]).map(v => String(v ?? '').trim()).filter(Boolean))];
    const expected = String(value ?? '');
    if (wanted.length === 0 || expected === '') return false;
    const point = await this.evaluate(`(()=>{const wanted=${JSON.stringify(wanted)};for(const f of document.querySelectorAll('iframe')){const d=f.contentDocument;if(!d)continue;for(const row of d.querySelectorAll('.meld-text-input')){const label=(row.querySelector('.meld-label-description')?.innerText||'').trim();if(!wanted.some(x=>label.includes(x)))continue;const host=row.querySelector('kat-input');const input=host?.shadowRoot?.querySelector('input');if(!host||!input||host.hasAttribute('disabled')||input.disabled)continue;f.scrollIntoView({block:'center'});input.scrollIntoView({block:'center'});const outer=f.getBoundingClientRect(),inner=input.getBoundingClientRect();const x=outer.left+inner.left+(inner.width/2),y=outer.top+inner.top+(inner.height/2);if(!Number.isFinite(x)||!Number.isFinite(y)||x<0||y<0||x>innerWidth||y>innerHeight)return null;return {x,y}}}return null})()`);
    if (!point || !Number.isFinite(point.x) || !Number.isFinite(point.y)) return false;
    await this.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: point.x, y: point.y, button: 'none' });
    await this.send('Input.dispatchMouseEvent', { type: 'mousePressed', x: point.x, y: point.y, button: 'left', clickCount: 1 });
    await this.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: point.x, y: point.y, button: 'left', clickCount: 1 });
    await this.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'a', code: 'KeyA', modifiers: 2, windowsVirtualKeyCode: 65, nativeVirtualKeyCode: 65 });
    await this.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'a', code: 'KeyA', modifiers: 2, windowsVirtualKeyCode: 65, nativeVirtualKeyCode: 65 });
    await this.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Backspace', code: 'Backspace', windowsVirtualKeyCode: 8, nativeVirtualKeyCode: 8 });
    await this.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Backspace', code: 'Backspace', windowsVirtualKeyCode: 8, nativeVirtualKeyCode: 8 });
    await this.send('Input.insertText', { text: expected });
    await sleep(300);
    return (await this.evaluate(`(()=>{const wanted=${JSON.stringify(wanted)},expected=${JSON.stringify(expected)};for(const f of document.querySelectorAll('iframe')){const d=f.contentDocument;if(!d)continue;for(const row of d.querySelectorAll('.meld-text-input')){const label=(row.querySelector('.meld-label-description')?.innerText||'').trim();if(!wanted.some(x=>label.includes(x)))continue;const input=row.querySelector('kat-input')?.shadowRoot?.querySelector('input');if(input)return input.value===expected}}return false})()`)) === true;
  }

  async selectKatOption(dropdownSelector, value) {
    return (await this.evaluate(`(()=>{const h=document.querySelector(${JSON.stringify(dropdownSelector)});const o=[...h?.shadowRoot?.querySelectorAll('kat-option')||[]].find(x=>x.getAttribute('value')===${JSON.stringify(value)});if(!o)return false;o.click();return true})()`)) === true;
  }

  async clickFrameText(label) {
    return (await this.evaluate(`(()=>{for(const f of document.querySelectorAll('iframe')){const d=f.contentDocument;if(!d)continue;const nodes=[...d.querySelectorAll('kat-button,button')];const h=nodes.find(e=>(e.getAttribute('label')||e.innerText||'').trim()===${JSON.stringify(label)});if(!h)continue;const b=h.tagName==='KAT-BUTTON'?h.shadowRoot?.querySelector('button'):h;if(!b||b.disabled)return false;b.click();return true}return false})()`)) === true;
  }

  async setFrameKat(selector, value) {
    return this.setKat(selector, value, true);
  }

  async frameText(limit = 16000) {
    const value = await this.evaluate(`JSON.stringify([...document.querySelectorAll('iframe')].map(f=>f.contentDocument?.body?.innerText||'').join('\n').slice(0,${limit}))`);
    return JSON.parse(value || '""');
  }

  async close() {
    try { this.ws.close(); } catch {}
    const targetId = this.targetId;
    this.targetId = null;
    await closeCdpTarget(targetId);
  }
}

function authenticationState(state) {
  const classified = classifyAmazonAuthState(state);
  if (classified === 'AUTHENTICATED') return 'OK';
  if (classified === 'HUMAN_CHALLENGE') return 'HUMAN_CHALLENGE';
  return 'AUTH_REQUIRED';
}

function bridgeResult(status, extra = {}) {
  return {
    status,
    submitted: false,
    external_id: null,
    retry_safe: false,
    block_reason: null,
    next_allowed_at: null,
    reason: null,
    evidence: {},
    ...extra,
  };
}

async function supportFrameSnapshot(cdp) {
  return await cdp.evaluate(`(()=>{const out={buttons:[],inputs:[]};for(const f of document.querySelectorAll('iframe')){const d=f.contentDocument;if(!d)continue;for(const h of d.querySelectorAll('kat-button,button')){const label=(h.getAttribute('label')||h.innerText||'').trim();if(label&&out.buttons.length<24)out.buttons.push(label.slice(0,80))}for(const h of d.querySelectorAll('kat-input,input')){const placeholder=(h.getAttribute('placeholder')||'').trim();if(placeholder&&out.inputs.length<16)out.inputs.push(placeholder.slice(0,80))}}return out})()`);
}

async function evidence(cdp, uiContract) {
  const state = await cdp.pageState(18000);
  const supportUi = uiContract === 'help-v1' ? await supportFrameSnapshot(cdp) : null;
  const safe = {
    ui_contract: uiContract,
    current_url: state.href || '',
    title: state.title || '',
    ...(supportUi ? { support_ui: supportUi } : {}),
    body_sha256: sha(state.text || ''),
  };
  return { ...safe, snapshot_sha256: sha(JSON.stringify(safe)) };
}

async function authGate(cdp, uiContract, targetUrl = null, waitMs = 5000) {
  let state = await cdp.pageState();
  let auth = authenticationState(state);
  if (auth === 'OK') return null;
  if (auth === 'HUMAN_CHALLENGE') return bridgeResult('HUMAN_CHALLENGE', { reason: 'CAPTCHA_PRESENT', evidence: await evidence(cdp, uiContract) });
  const recovery = await ensureSellerCentralAuthenticated(cdp);
  if (recovery.status === 'HUMAN_CHALLENGE') return bridgeResult('HUMAN_CHALLENGE', { reason: recovery.reason, evidence: await evidence(cdp, uiContract) });
  if (recovery.status !== 'AUTHENTICATED') return bridgeResult('AUTH_REQUIRED', { reason: recovery.reason || 'SESSION_NOT_AUTHENTICATED', evidence: await evidence(cdp, uiContract) });
  if (targetUrl) await cdp.navigate(targetUrl, waitMs);
  state = await cdp.pageState();
  auth = authenticationState(state);
  if (auth === 'HUMAN_CHALLENGE') return bridgeResult('HUMAN_CHALLENGE', { reason: 'CAPTCHA_PRESENT', evidence: await evidence(cdp, uiContract) });
  if (auth !== 'OK') return bridgeResult('AUTH_REQUIRED', { reason: 'SESSION_NOT_AUTHENTICATED', evidence: await evidence(cdp, uiContract) });
  return null;
}

function reasonFor(job) {
  const explicit = text(job.payload?.reason_code || job.payload?.decision?.reason_code).toUpperCase();
  const explicitSub = text(job.payload?.reason_subcategory || job.payload?.decision?.reason_subcategory);
  if (['ADMGD','NOCOT','MSNG','RNOTR','LBLOT'].includes(explicit)) {
    return { reason: explicit, sub: explicitSub || (explicit === 'RNOTR' ? 'RNOTR-a' : '') };
  }
  if (job.case?.physical_status === 'NOT_RECEIVED') return { reason: 'RNOTR', sub: 'RNOTR-a' };
  const condition = text(job.payload?.physical_condition).toUpperCase();
  if (['DAMAGED','USED'].includes(condition)) return { reason: 'ADMGD', sub: '' };
  if (['WRONG_ITEM','EMPTY_PACKAGE'].includes(condition)) return { reason: 'NOCOT', sub: '' };
  if (condition === 'INCOMPLETE') return { reason: 'MSNG', sub: '' };
  return null;
}

function snapshotNarrative(job, max) {
  const snapshot = job.payload?.write_snapshot;
  if (snapshot?.format_version !== 2) return null;
  const narrative = text(snapshot.narrative);
  return narrative ? narrative.slice(0, max) : '';
}

function writeSnapshotFailure(job) {
  const snapshot = job.payload?.write_snapshot;
  if (snapshot?.format_version === 2 && !text(snapshot.narrative)) {
    return bridgeResult('FAILED', { reason: 'WRITE_SNAPSHOT_MISSING', retry_safe: false });
  }
  return null;
}

function narrativeFor(job, max = 1000) {
  const persisted = snapshotNarrative(job, max);
  if (persisted !== null) return persisted;
  const supplied = text(job.payload?.narrative);
  if (supplied) return supplied.slice(0, max);
  const order = text(job.case?.order_id);
  const safeT = text(job.case?.safe_t_id);
  const decision = text(job.payload?.decision?.reason);
  if (job.action === 'SELLER_SUPPORT_OPEN' && safeT) {
    return (`SAFE-T ${safeT}, pedido ${order}. A devolução permanece não recebida fisicamente pelo vendedor. `
      + `A nova negativa repetiu a justificativa sem responder aos fatos e às evidências apresentados. `
      + `Solicito revisão manual por equipe especializada. Se a Amazon considera que houve devolução, `
      + `favor informar data, transportadora, rastreio, endereço de entrega e comprovante de entrega. ${decision}`).slice(0, max);
  }
  if (job.action === 'SELLER_SUPPORT_UPDATE' && safeT) {
    return (`SAFE-T ${safeT}, pedido ${order}. A devolução permanece não recebida fisicamente pelo vendedor. `
      + `Solicito revisão manual por equipe especializada. ${decision}`).slice(0, max);
  }
  if (job.action === 'SELLER_SUPPORT_OPEN' || job.action === 'SELLER_SUPPORT_UPDATE') {
    return (`Pedido ${order}. A Amazon efetuou o reembolso ao comprador e ainda existe saldo pendente ao vendedor. `
      + `Solicito análise manual e ressarcimento do valor devido, considerando o fluxo de devolução e as evidências do pedido. ${decision}`).slice(0, max);
  }
  if (job.action === 'SAFE_T_APPEAL') {
    return (`Pedido ${order}, SAFE-T ${safeT}. O produto não foi recebido fisicamente pelo vendedor. `
      + `Solicito reavaliação da decisão com análise do fluxo de devolução, rastreio e eventual comprovante de entrega. ${decision}`).slice(0, max);
  }
  return (`Pedido ${order}. A Amazon efetuou o reembolso ao comprador e a devolução não foi recebida pelo vendedor. `
    + 'Solicito o ressarcimento correspondente, com validação do fluxo de devolução e do débito ao vendedor.').slice(0, max);
}


const SAFE_T_ORDER_INPUT_RETRY_ATTEMPTS = 12;

async function setSafeTOrderInput(cdp, orderId) {
  const selectors = [
    'kat-input[placeholder="Número do pedido"]',
    'kat-input[placeholder="Número do pedido da Amazon"]',
    'kat-input[placeholder="Order ID"]',
    'kat-input[placeholder="Amazon order ID"]',
  ];
  const orderHints=['pedido','order'];
  const serializedOrderId = JSON.stringify(String(orderId));

  for (let attempt = 0; attempt < SAFE_T_ORDER_INPUT_RETRY_ATTEMPTS; attempt++) {
    for (const selector of selectors) {
      if (await cdp.setKat(selector, orderId)) return true;
      if (await cdp.setFrameKat(selector, orderId)) return true;
    }

    const semanticReady = (await cdp.evaluate(`(()=>{const hints=${JSON.stringify(orderHints)};const value=${serializedOrderId};const docs=[];const visitDocument=d=>{if(!d||docs.includes(d))return;docs.push(d);for(const frame of d.querySelectorAll('iframe')){try{visitDocument(frame.contentDocument)}catch{}}};visitDocument(document);const matches=[];const seen=new Set();for(const d of docs){for(const host of d.querySelectorAll('kat-input,input')){const input=host.tagName==='KAT-INPUT'?host.shadowRoot?.querySelector('input,textarea'):host;if(!input||input.disabled||input.readOnly||seen.has(input))continue;const raw=[host.getAttribute('placeholder'),host.getAttribute('label'),host.getAttribute('aria-label'),host.getAttribute('name'),host.id,input.getAttribute('placeholder'),input.getAttribute('label'),input.getAttribute('aria-label'),input.getAttribute('name'),input.id].filter(Boolean).join(' ').normalize('NFD').replace(/[\\u0300-\\u036f]/g,'').toLowerCase();if(!hints.some(h=>raw.includes(h)))continue;seen.add(input);matches.push({host,input})}}if(matches.length!==1)return false;const {host,input}=matches[0];const proto=input.tagName==='TEXTAREA'?HTMLTextAreaElement.prototype:HTMLInputElement.prototype;const setter=Object.getOwnPropertyDescriptor(proto,'value')?.set;if(typeof setter!=='function')return false;setter.call(input,value);input.dispatchEvent(new InputEvent('input',{bubbles:true,composed:true,inputType:'insertText',data:value}));input.dispatchEvent(new Event('change',{bubbles:true,composed:true}));return host.value===value||input.value===value})()`)) === true;
    if (semanticReady) return true;
    if (attempt + 1 < SAFE_T_ORDER_INPUT_RETRY_ATTEMPTS) await sleep(500);
  }
  return false;
}

const SAFE_T_ELIGIBILITY_RETRY_ATTEMPTS = 12;

async function clickSafeTEligibilityButton(cdp) {
  const eligibilityLabels=['Verificar Elegibilidade','Verificar elegibilidade','Check Eligibility','Check eligibility'];
  for (let attempt = 0; attempt < SAFE_T_ELIGIBILITY_RETRY_ATTEMPTS; attempt++) {
    const clicked = (await cdp.evaluate(`(()=>{const labels=${JSON.stringify(eligibilityLabels)}.map(v=>v.normalize('NFD').replace(/[\\u0300-\\u036f]/g,'').trim().toLowerCase());const docs=[];const visitDocument=d=>{if(!d||docs.includes(d))return;docs.push(d);for(const frame of d.querySelectorAll('iframe')){try{visitDocument(frame.contentDocument)}catch{}}};visitDocument(document);const matches=[];const seen=new Set();for(const d of docs){for(const host of d.querySelectorAll('kat-button,button')){const button=host.tagName==='KAT-BUTTON'?host.shadowRoot?.querySelector('button'):host;if(!button||button.disabled||seen.has(button))continue;const hostLabel=host.getAttribute('label')||host.getAttribute('aria-label')||host.innerText||'';const buttonLabel=button.getAttribute('label')||button.getAttribute('aria-label')||button.innerText||'';const rawValues=[hostLabel,buttonLabel].map(v=>v.normalize('NFD').replace(/[\\u0300-\\u036f]/g,'').trim().toLowerCase()).filter(Boolean);if(!rawValues.some(raw=>labels.includes(raw)))continue;seen.add(button);matches.push(button)}}if(matches.length!==1)return false;matches[0].click();return true})()`)) === true;
    if (clicked) return true;
    if (attempt + 1 < SAFE_T_ELIGIBILITY_RETRY_ATTEMPTS) await sleep(500);
  }
  return false;
}

async function setSafeTQuantity(cdp, quantity) {
  const value = String(quantity);
  const selectors = ['kat-input[type="number"]','kat-input.QuantityInput','kat-input[placeholder*="Quantidade"]','kat-input[placeholder*="Quantity"]'];
  for (const selector of selectors) {
    if (await cdp.setKat(selector, value)) return true;
    if (await cdp.setFrameKat(selector, value)) return true;
  }
  return false;
}

async function clickSafeTNextButton(cdp) {
  const nextLabels=['Próximo','Proximo','Next','Continuar','Continue'];
  for (let attempt = 0; attempt < 4; attempt++) {
    const clicked = (await cdp.evaluate(`(()=>{const labels=${JSON.stringify(nextLabels)}.map(v=>v.trim().toLowerCase());const docs=[];const visitDocument=d=>{if(!d||docs.includes(d))return;docs.push(d);for(const frame of d.querySelectorAll('iframe')){try{visitDocument(frame.contentDocument)}catch{}}};visitDocument(document);const matches=[];const seen=new Set();for(const d of docs){for(const host of d.querySelectorAll('kat-button,button')){const button=host.tagName==='KAT-BUTTON'?host.shadowRoot?.querySelector('button'):host;if(!button||button.disabled||host.hasAttribute('disabled')||seen.has(button))continue;const hostLabel=host.getAttribute('label')||host.getAttribute('aria-label')||host.innerText||'';const buttonLabel=button.getAttribute('label')||button.getAttribute('aria-label')||button.innerText||'';const rawValues=[hostLabel,buttonLabel].map(v=>v.trim().toLowerCase()).filter(Boolean);if(!rawValues.some(raw=>labels.includes(raw)))continue;const rect=button.getBoundingClientRect();if(rect.width<=0||rect.height<=0)continue;seen.add(button);matches.push(button)}}if(matches.length!==1)return false;matches[0].click();return true})()`)) === true;
    if (clicked) return true;
    if (attempt + 1 < 4) await sleep(350);
  }
  return false;
}

function normalizeSafeTSubreasonShape(raw) {
  if (!raw || typeof raw !== 'object') return null;
  const keys=[
    'documents_total','dropdowns_total','hinted_dropdowns','shadow_dropdowns','trigger_candidates',
    'expanded_dropdowns','direct_option_nodes','deep_option_nodes','exact_expected_direct_matches',
    'exact_expected_deep_matches','role_option_nodes',
  ];
  const safe={};
  for (const key of keys) {
    const value=Number(raw[key]);
    safe[key]=Number.isFinite(value)&&value>=0?Math.trunc(value):0;
  }
  return safe;
}

async function safeTSubreasonShape(cdp, expected) {
  const expectedValue=String(expected||'').trim();
  return await cdp.evaluate(`(()=>{const expected=${JSON.stringify(expectedValue)};const hints=['subcategoria','subcategory','sub category','subreason'];const normalize=v=>String(v||'').normalize('NFD').replace(/[\\u0300-\\u036f]/g,'').trim().toLowerCase();const docs=[];const visit=d=>{if(!d||docs.includes(d))return;docs.push(d);for(const frame of d.querySelectorAll('iframe')){try{visit(frame.contentDocument)}catch{}}};visit(document);const dropdowns=[];for(const d of docs){for(const host of d.querySelectorAll('kat-dropdown'))dropdowns.push({d,host})}const direct=new Set();let hinted=0,shadow=0,triggers=0,expanded=0;for(const {d,host} of dropdowns){const meta=normalize([host.getAttribute('placeholder'),host.getAttribute('label'),host.getAttribute('aria-label'),host.getAttribute('name'),host.id,host.className].filter(Boolean).join(' '));if(hints.some(h=>meta.includes(h)))hinted++;if(host.shadowRoot)shadow++;const trigger=host.shadowRoot?.querySelector('button,[role=button],input')||null;if(trigger)triggers++;if(host.getAttribute('aria-expanded')==='true'||trigger?.getAttribute?.('aria-expanded')==='true')expanded++;for(const root of [host.shadowRoot,host,d].filter(Boolean)){for(const option of root.querySelectorAll('kat-option'))direct.add(option)}}const deep=new Set();const roleOptions=new Set();const seenRoots=new Set();const walk=root=>{if(!root||seenRoots.has(root))return;seenRoots.add(root);for(const option of root.querySelectorAll('kat-option'))deep.add(option);for(const option of root.querySelectorAll('[role=option]'))roleOptions.add(option);for(const el of root.querySelectorAll('*')){if(el.shadowRoot)walk(el.shadowRoot)}};for(const d of docs)walk(d);const exactDirect=[...direct].filter(option=>String(option.getAttribute('value')||'').trim()===expected).length;const exactDeep=[...deep].filter(option=>String(option.getAttribute('value')||'').trim()===expected).length;return {documents_total:docs.length,dropdowns_total:dropdowns.length,hinted_dropdowns:hinted,shadow_dropdowns:shadow,trigger_candidates:triggers,expanded_dropdowns:expanded,direct_option_nodes:direct.size,deep_option_nodes:deep.size,exact_expected_direct_matches:exactDirect,exact_expected_deep_matches:exactDeep,role_option_nodes:roleOptions.size}})()`);
}

async function selectSafeTSubreason(cdp, value) {
  const hints=['subcategoria','subcategory','sub category','subreason'];
  const expected=String(value||'').trim();
  if (!expected) return false;
  for (let attempt=0; attempt<12; attempt++) {
    const opened=(await cdp.evaluate(`(()=>{const hints=${JSON.stringify(hints)};const normalize=v=>String(v||'').normalize('NFD').replace(/[\\u0300-\\u036f]/g,'').trim().toLowerCase();const docs=[];const visit=d=>{if(!d||docs.includes(d))return;docs.push(d);for(const frame of d.querySelectorAll('iframe')){try{visit(frame.contentDocument)}catch{}}};visit(document);const matches=[];for(const d of docs){for(const host of d.querySelectorAll('kat-dropdown')){const meta=normalize([host.getAttribute('placeholder'),host.getAttribute('label'),host.getAttribute('aria-label'),host.getAttribute('name'),host.id,host.className].filter(Boolean).join(' '));if(!hints.some(h=>meta.includes(h)))continue;matches.push(host)}}if(matches.length!==1)return false;const host=matches[0];const trigger=host.shadowRoot?.querySelector('button,[role=button],input')||host;if(trigger.disabled||host.hasAttribute('disabled'))return false;trigger.click();return true})()`))===true;
    if (!opened) {
      if (attempt+1<12) await sleep(500);
      continue;
    }
    await sleep(250);
    const selected=(await cdp.evaluate(`(()=>{const hints=${JSON.stringify(hints)};const expected=${JSON.stringify(expected)};const normalize=v=>String(v||'').normalize('NFD').replace(/[\\u0300-\\u036f]/g,'').trim().toLowerCase();const docs=[];const visit=d=>{if(!d||docs.includes(d))return;docs.push(d);for(const frame of d.querySelectorAll('iframe')){try{visit(frame.contentDocument)}catch{}}};visit(document);const dropdowns=[];for(const d of docs){for(const host of d.querySelectorAll('kat-dropdown')){const meta=normalize([host.getAttribute('placeholder'),host.getAttribute('label'),host.getAttribute('aria-label'),host.getAttribute('name'),host.id,host.className].filter(Boolean).join(' '));if(hints.some(h=>meta.includes(h)))dropdowns.push({d,host})}}if(dropdowns.length!==1)return false;const {d,host}=dropdowns[0];const roots=[host.shadowRoot,host,d].filter(Boolean);const options=[];const seen=new Set();for(const root of roots){for(const option of root.querySelectorAll('kat-option')){if(seen.has(option))continue;seen.add(option);options.push(option)}}const exact=options.filter(option=>String(option.getAttribute('value')||'').trim()===expected);if(exact.length!==1)return false;exact[0].click();return true})()`))===true;
    if (selected) return true;
    if (attempt+1<12) await sleep(500);
  }
  return false;
}

async function safeTSubmit(cdp, job) {
  const snapshotFailure = writeSnapshotFailure(job);
  if (snapshotFailure) return snapshotFailure;
  const orderId = text(job.case?.order_id);
  if (!/^\d{3}-\d{7}-\d{7}$/.test(orderId)) return bridgeResult('FAILED', { reason: 'INVALID_ORDER_ID' });
  const trackingEvidence = await captureTrackingEvidence(cdp, orderId);
  await cdp.navigate(`${SAFE_T_BASE}/create-v2?ref_=ag_sfdcf_cont_safet`, 5000);
  const auth = await authGate(cdp, 'safet-v1', `${SAFE_T_BASE}/create-v2?ref_=ag_sfdcf_cont_safet`, 5000);
  if (auth) return auth;
  const orderInputReady = await setSafeTOrderInput(cdp, orderId);
  if (!orderInputReady) {
    return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_ORDER_INPUT_MISSING', evidence: await evidence(cdp, 'safet-v1') });
  }
  const eligibilityClicked = await clickSafeTEligibilityButton(cdp);
  if (!eligibilityClicked) {
    return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_ELIGIBILITY_BUTTON_MISSING', evidence: await evidence(cdp, 'safet-v1') });
  }
  await sleep(6000);
  const existing = await cdp.evaluate(`document.querySelector('kat-link.ClaimAlreadyExists')?.getAttribute('label')||''`);
  if (text(existing)) {
    return bridgeResult('ALREADY_EXISTS', { external_id: text(existing), retry_safe: true, evidence: await evidence(cdp, 'safet-v1') });
  }
  const eligibilityState = await cdp.pageState();
  const eligibilityText = text(eligibilityState.text).toLowerCase();
  if (eligibilityText.includes('excedeu 75 dias') || eligibilityText.includes('exceeded 75 days')) {
    return bridgeResult('SUPERSEDED', { reason: 'SAFE_T_WINDOW_EXPIRED', retry_safe: false, evidence: await evidence(cdp, 'safet-v1') });
  }
  const hasItem = await cdp.evaluate(`Boolean(document.querySelector('kat-checkbox.QuantityCheckbox'))`);
  if (!hasItem) {
    const state = await cdp.pageState();
    return bridgeResult('BLOCKED_UNTIL', { reason: 'SELLER_CENTRAL_NOT_ELIGIBLE', block_reason: text(state.text).slice(0, 800), retry_safe: true, evidence: await evidence(cdp, 'safet-v1') });
  }

  const quantity = Math.max(1, Number(job.case?.quantity_refunded || 1) - Number(job.case?.quantity_received || 0));
  if (!(await setSafeTQuantity(cdp, quantity))) {
    return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_QUANTITY_INPUT_MISSING', evidence: await evidence(cdp, 'safet-v1') });
  }
  if (!(await cdp.clickKat('kat-checkbox.QuantityCheckbox'))) {
    return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_ITEM_CHECKBOX_MISSING', evidence: await evidence(cdp, 'safet-v1') });
  }
  await sleep(700);
  if (!(await clickSafeTNextButton(cdp))) {
    return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_ITEM_SELECTION_NOT_ACCEPTED', evidence: await evidence(cdp, 'safet-v1') });
  }
  await sleep(2500);
  const reason = reasonFor(job);
  if (!reason) return bridgeResult('FAILED', { reason: 'SAFE_T_REASON_REQUIRES_REVIEW', retry_safe: false, evidence: await evidence(cdp, 'safet-v1') });
  if (!(await cdp.selectKatOption('kat-dropdown.reasonDropdown', reason.reason))) {
    return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_REASON_OPTION_MISSING', evidence: await evidence(cdp, 'safet-v1') });
  }
  await sleep(500);
  if (reason.sub) {
    if (!(await selectSafeTSubreason(cdp, reason.sub))) {
      const safeTSubreasonShapeResult = await safeTSubreasonShape(cdp, reason.sub);
      return bridgeResult('UI_DRIFT', {
        reason: 'SAFE_T_SUBREASON_OPTION_MISSING',
        evidence: await evidence(cdp, 'safet-v1'),
        safe_t_subreason_shape: normalizeSafeTSubreasonShape(safeTSubreasonShapeResult),
      });
    }
  }
  await sleep(500);
  if (!(await clickSafeTNextButton(cdp))) {
    return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_REASON_NEXT_DISABLED', evidence: await evidence(cdp, 'safet-v1') });
  }
  await sleep(2200);
  const needsEvidence = reason.reason !== 'RNOTR' && reason.reason !== 'LBLOT';
  const suppliedEvidence = Array.isArray(job.payload?.evidence_paths) ? job.payload.evidence_paths : [];
  const evidencePaths = [...new Set([...suppliedEvidence, ...(trackingEvidence.path ? [trackingEvidence.path] : [])]
    .filter(file => typeof file === 'string' && fs.existsSync(file)))];
  if (needsEvidence && evidencePaths.length === 0) {
    return bridgeResult('FAILED', { reason: 'DISCREPANCY_EVIDENCE_REQUIRED', retry_safe: false, evidence: await evidence(cdp, 'safet-v1') });
  }
  let uploadResult = { attached: false, files: [] };
  if (evidencePaths.length > 0) {
    uploadResult = await attachFiles(cdp, evidencePaths);
    if (!uploadResult.attached) {
      return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_EVIDENCE_UPLOAD_FAILED', retry_safe: true, evidence: withTrackingEvidence(await evidence(cdp, 'safet-v1'), trackingEvidence, uploadResult) });
    }
  }
  if (!(await clickSafeTNextButton(cdp))) {
    return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_EVIDENCE_NEXT_MISSING', evidence: await evidence(cdp, 'safet-v1') });
  }
  await sleep(2200);
  const narrative = narrativeFor(job, 1000);
  if (!(await cdp.setKat('kat-textarea.KatTextarea', narrative))) {
    return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_NARRATIVE_FIELD_MISSING', evidence: await evidence(cdp, 'safet-v1') });
  }
  if (!(await cdp.clickKat('kat-checkbox.KatCheckbox'))) {
    return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_CONFIRMATION_CHECKBOX_MISSING', evidence: await evidence(cdp, 'safet-v1') });
  }
  await sleep(500);
  const canSubmit = await cdp.evaluate(`!document.querySelector('kat-button.SubmitButton')?.hasAttribute('disabled')`);
  if (!canSubmit) return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_SUBMIT_STILL_DISABLED', evidence: await evidence(cdp, 'safet-v1') });
  if (!(await cdp.clickKat('kat-button.SubmitButton'))) {
    return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_SUBMIT_BUTTON_MISSING', evidence: await evidence(cdp, 'safet-v1') });
  }
  await sleep(7000);
  const readBack = await cdp.evaluate(`(()=>{const href=location.href;const body=document.body?.innerText||'';const fromUrl=href.match(/\/claim\/(\d{5}-\d{5}-\d{7})/);const fromBody=body.match(/(?:ID da reivindicação SAFE-T[:\s]*|SAFE-T[:\s]+)(\d{5}-\d{5}-\d{7})/i);return fromUrl?.[1]||fromBody?.[1]||''})()`);
  if (!text(readBack)) {
    return bridgeResult('FAILED', { reason: 'SAFE_T_WRITE_WITHOUT_READBACK_ID', submitted: false, retry_safe: false, evidence: await evidence(cdp, 'safet-v1') });
  }
  return bridgeResult('ACCEPTED', {
    submitted: true,
    external_id: text(readBack),
    retry_safe: true,
    reason: 'SAFE_T_SUBMITTED_AND_READ_BACK',
    evidence: withTrackingEvidence(await evidence(cdp, 'safet-v1'), trackingEvidence, uploadResult),
  });
}

async function safeTAppeal(cdp, job) {
  const snapshotFailure = writeSnapshotFailure(job);
  if (snapshotFailure) return snapshotFailure;
  const safeTId = text(job.case?.safe_t_id);
  if (!/^\d{5}-\d{5}-\d{7}$/.test(safeTId)) return bridgeResult('FAILED', { reason: 'SAFE_T_ID_REQUIRED' });
  const orderId = text(job.case?.order_id);
  const trackingEvidence = await captureTrackingEvidence(cdp, orderId);
  await cdp.navigate(`${SAFE_T_BASE}/claim/${encodeURIComponent(safeTId)}`, 5000);
  const auth = await authGate(cdp, 'safet-v1', `${SAFE_T_BASE}/claim/${encodeURIComponent(safeTId)}`, 5000);
  if (auth) return auth;
  const narrative = narrativeFor(job, 1500);
  const already = await cdp.evaluate(`(document.body?.innerText||'').includes(${JSON.stringify(narrative)})`);
  if (already) return bridgeResult('ALREADY_EXISTS', { external_id: safeTId, retry_safe: true, evidence: await evidence(cdp, 'safet-v1') });
  const hasField = await cdp.evaluate(`Boolean(document.querySelector('kat-textarea.description-textbox'))`);
  if (!hasField) {
    const state = await cdp.pageState();
    return bridgeResult('BLOCKED_UNTIL', { reason: 'SAFE_T_APPEAL_FIELD_UNAVAILABLE', block_reason: text(state.text).slice(-1200), retry_safe: true, evidence: await evidence(cdp, 'safet-v1') });
  }
  if (!(await cdp.setKat('kat-textarea.description-textbox', narrative))) {
    return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_APPEAL_FIELD_NOT_WRITABLE', evidence: await evidence(cdp, 'safet-v1') });
  }
  let uploadResult = { attached: false, files: [] };
  if (trackingEvidence.path) {
    uploadResult = await attachFiles(cdp, [trackingEvidence.path]);
    if (!uploadResult.attached) {
      return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_APPEAL_TRACKING_UPLOAD_FAILED', retry_safe: true, evidence: withTrackingEvidence(await evidence(cdp, 'safet-v1'), trackingEvidence, uploadResult) });
    }
  }
  const sendSelector = 'kat-button.right-floated[label="Enviar"]';
  if (!(await cdp.clickKat(sendSelector))) {
    return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_APPEAL_SEND_MISSING', evidence: await evidence(cdp, 'safet-v1') });
  }
  await sleep(5000);
  const confirmed = await cdp.evaluate(`(document.body?.innerText||'').includes(${JSON.stringify(narrative.slice(0, 240))})`);
  if (!confirmed) {
    return bridgeResult('FAILED', { reason: 'SAFE_T_APPEAL_WRITE_NOT_CONFIRMED', retry_safe: false, evidence: await evidence(cdp, 'safet-v1') });
  }
  return bridgeResult('ACCEPTED', {
    submitted: true,
    external_id: safeTId,
    retry_safe: true,
    reason: 'SAFE_T_APPEAL_SUBMITTED_AND_READ_BACK',
    evidence: withTrackingEvidence(await evidence(cdp, 'safet-v1'), trackingEvidence, uploadResult),
  });
}

async function scanSupportCaseHistory(cdp, job, preferredCaseId = '', { includeTerminal = false, cutoffEpochSeconds = null } = {}) {
  const orderId = text(job.case?.order_id);
  const safeTId = text(job.case?.safe_t_id);
  const needles = [orderId, safeTId].filter(Boolean);
  if (needles.length === 0) return null;
  const preferred = /^\d{8,14}$/.test(text(preferredCaseId)) ? text(preferredCaseId) : '';
  const cutoff = Number(cutoffEpochSeconds);
  const raw = await cdp.evaluate(`(async()=>{
    const needles=${JSON.stringify(needles)};
    const preferred=${JSON.stringify(preferred)};
    const includeTerminal=${includeTerminal === true ? 'true' : 'false'};
    const cutoffSeconds=${Number.isFinite(cutoff) && cutoff > 0 ? cutoff : 'null'};
    const scanBudgetMs=${SUPPORT_CASE_LOOKUP_SCAN_BUDGET_MS};
    const scanStartedAt=Date.now();
    const budgetExceeded=()=>Date.now()-scanStartedAt>=scanBudgetMs;
    const budgetResult=()=>JSON.stringify({status:'UNAVAILABLE',reason:'LOOKUP_SCAN_BUDGET_EXHAUSTED'});
    const terminal=new Set(${JSON.stringify(SUPPORT_CASE_TERMINAL_STATUSES)});
    const activeSupportStatus=value=>{const status=String(value||'').trim().toUpperCase();return status!==''&&!terminal.has(status)};
    const supportStatusAllowed=value=>includeTerminal ? true : activeSupportStatus(value);
    const validCaseId=value=>{const candidate=String(value||'');return candidate.length>=8&&candidate.length<=14&&[...candidate].every(ch=>ch>='0'&&ch<='9')};
    const limit=${SUPPORT_CASE_HISTORY_LIMIT};
    const pageSize=50;
    const relevant=/reemb|refund|safe[- ]?t|pedido|order|fba|devolu|return|reimbursement|claim|reclama|review|revis/i;
    const searchCases=async (searchText,page=0)=>{
      const filters={caseOwner:'MerchantCases'};
      if(searchText)filters.searchText=searchText;
      const response=await fetch('/hill/hillservice/mons-api/SearchForCases',{
        method:'POST',credentials:'include',headers:{'content-type':'application/json'},
        body:JSON.stringify({page,searchPageSize:50,sortBy:'CreationDate',sortByOrder:'DESC',getCountOnly:false,caseFilters:filters})
      });
      if(!response.ok)return {error:'SEARCH_HTTP_'+response.status};
      const search=await response.json();
      if(!Array.isArray(search.caseSearchResultList)||!Number.isFinite(Number(search.totalNumberOfResults))){
        return {error:'SEARCH_RESPONSE_INVALID'};
      }
      return {rows:search.caseSearchResultList,total:Number(search.totalNumberOfResults)};
    };
    const viewCase=async caseId=>{
      const maxAttempts=4;
      for(let attempt=0;attempt<maxAttempts;attempt++){
        const paceMs=1000*(2**attempt);
        await new Promise(resolve=>setTimeout(resolve,paceMs));
        const response=await fetch('/hill/hillservice/mons-api/ViewCase?caseId='+encodeURIComponent(caseId)+'&timeZone=UTC&pageSize=10',{credentials:'include'});
        if(response.status===429&&attempt+1<maxAttempts)continue;
        if(!response.ok)throw new Error('VIEW_CASE_HTTP_'+response.status);
        return await response.json();
      }
      throw new Error('VIEW_CASE_HTTP_429');
    };
    const deterministicTerms=[preferred,...needles].filter((value,index,all)=>value&&all.indexOf(value)===index);
    for(const term of deterministicTerms){
      if(budgetExceeded())return budgetResult();
      const search=await searchCases(term);
      if(search.error)return JSON.stringify({status:'UNAVAILABLE',reason:search.error});
      for(const item of search.rows){
        const caseId=String(item.caseId||'');
        if(!validCaseId(caseId)||!supportStatusAllowed(item.status))continue;
        const preferredCandidate=preferred&&caseId===preferred;
        const relevantCandidate=relevant.test(String(item.shortDescription||''));
        if(!preferredCandidate&&!relevantCandidate)continue;
        try{
          const detail=await viewCase(caseId);
          const status=detail?.viewCaseMetaData?.caseStatus||item.status;
          if(supportStatusAllowed(status)&&needles.some(n=>JSON.stringify(detail).includes(n))){
            return JSON.stringify({status:'FOUND',case_id:caseId});
          }
        }catch{return JSON.stringify({status:'UNAVAILABLE',reason:'DETAIL_LOOKUP_FAILED'})}
      }
    }
    if(!Number.isFinite(cutoffSeconds)||cutoffSeconds<=0){
      return JSON.stringify({status:'UNAVAILABLE',reason:'LOOKUP_CUTOFF_UNAVAILABLE'});
    }
    let total=null;
    let inspected=0;
    let detailFailure=false;
    for(let page=0;inspected<limit;page++){
      if(budgetExceeded())return budgetResult();
      const search=await searchCases('',page);
      if(search.error)return JSON.stringify({status:'UNAVAILABLE',reason:search.error});
      const rows=search.rows;
      if(total===null)total=search.total;
      const dated=[];
      for(const item of rows){
        const created=Number(item.creationDate);
        if(!Number.isFinite(created))return JSON.stringify({status:'UNAVAILABLE',reason:'SEARCH_CREATION_DATE_INVALID'});
        dated.push({item,created});
      }
      const recent=dated.filter(entry=>entry.created>=cutoffSeconds).map(entry=>entry.item);
      for(const item of recent){
        const summary=JSON.stringify(item);
        if(needles.some(n=>summary.includes(n))&&supportStatusAllowed(item.status))return JSON.stringify({status:'FOUND',case_id:String(item.caseId||'')});
      }
      const candidates=recent.filter(item=>supportStatusAllowed(item.status)&&relevant.test(String(item.shortDescription||'')));
      for(const item of candidates){
        if(budgetExceeded())return budgetResult();
        try{
          const detail=await viewCase(item.caseId);
          if(budgetExceeded())return budgetResult();
          const status=detail?.viewCaseMetaData?.caseStatus||item.status;
          if(supportStatusAllowed(status)&&needles.some(n=>JSON.stringify(detail).includes(n))){
            return JSON.stringify({status:'FOUND',case_id:String(item.caseId||'')});
          }
        }catch{detailFailure=true}
      }
      inspected+=rows.length;
      const crossedCutoff=dated.some(entry=>entry.created<cutoffSeconds);
      if(rows.length<pageSize||inspected>=total||crossedCutoff)break;
    }
    if(detailFailure)return JSON.stringify({status:'UNAVAILABLE',reason:'DETAIL_LOOKUP_FAILED'});
    return JSON.stringify({status:'NOT_FOUND',total:Number(total||0),inspected});
  })()`);
  let parsed;
  try { parsed = JSON.parse(raw || '{}'); } catch { parsed = {}; }
  if (parsed.status === 'FOUND' && /^\d{8,14}$/.test(text(parsed.case_id))) return text(parsed.case_id);
  if (parsed.status === 'NOT_FOUND') return null;
  const error = new Error('SUPPORT_CASE_LOOKUP_UNAVAILABLE');
  error.lookupReason = text(parsed.reason || 'UNKNOWN');
  throw error;
}
async function findSupportCase(cdp, job, options = {}) {
  const known = text(job.case?.support_case_id);
  const lookup = await Cdp.connect();
  lookup.commandTimeoutMs = SUPPORT_CASE_LOOKUP_COMMAND_TIMEOUT_MS;
  try {
    await lookup.navigate(CASE_LOBBY, 4500);
    const auth = await authGate(lookup, 'help-v1', CASE_LOBBY, 4500);
    if (auth) { const error = new Error('SUPPORT_CASE_LOOKUP_UNAVAILABLE'); error.lookupReason = `AUTH_GATE_${text(auth.status || 'AUTH_REQUIRED')}`; throw error; }
    const orderId = text(job.case?.order_id);
    const safeTId = text(job.case?.safe_t_id);
    const quick = text(await lookup.evaluate(`(()=>{const needles=${JSON.stringify([safeTId, orderId].filter(Boolean))};const docs=[document];for(const f of document.querySelectorAll('iframe')){if(f.contentDocument)docs.push(f.contentDocument);const h=f.contentDocument?.querySelector('spl-hill-form');const hd=h?.shadowRoot?.querySelector('iframe')?.contentDocument;if(hd)docs.push(hd)}for(const d of docs){const body=d.body?.innerText||'';if(!needles.some(n=>body.includes(n)))continue;for(const a of d.querySelectorAll('a[href*="view-case"],a[href*="caseID="]')){const row=a.closest('tr,[role=row],div');const t=row?.innerText||'';if(needles.some(n=>t.includes(n))){const m=(a.href||'').match(/[?&]caseID=(\d{8,14})/);if(m)return m[1]}}}return ''})()`));
    let cutoffEpochSeconds = Number(options.cutoffEpochSeconds);
    if (!Number.isFinite(cutoffEpochSeconds) || cutoffEpochSeconds <= 0) {
      const cutoffValue = text(options.includeTerminal === true ? job.created_at : job.case?.refund_at);
      const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(cutoffValue)
        ? `${cutoffValue.replace(' ', 'T')}Z`
        : cutoffValue;
      const cutoffMs = Date.parse(normalized);
      if (!Number.isFinite(cutoffMs)) {
        const error = new Error('SUPPORT_CASE_LOOKUP_UNAVAILABLE');
        error.lookupReason = 'LOOKUP_CUTOFF_UNAVAILABLE';
        throw error;
      }
      cutoffEpochSeconds = Math.floor(cutoffMs / 1000) - 86400;
    }
    return await scanSupportCaseHistory(lookup, job, known || quick, { ...options, cutoffEpochSeconds });
  } catch (error) {
    if (text(error?.message) === 'SUPPORT_CASE_LOOKUP_UNAVAILABLE') throw error;
    throw new Error('SUPPORT_CASE_LOOKUP_UNAVAILABLE', { cause: error });
  } finally {
    await lookup.close();
  }
}

async function clickFrameIncludes(cdp, phrase) {
  return (await cdp.evaluate(`(()=>{for(const f of document.querySelectorAll('iframe')){const d=f.contentDocument;if(!d)continue;const nodes=[...d.querySelectorAll('kat-button,button')];const h=nodes.find(e=>(e.getAttribute('label')||e.innerText||'').includes(${JSON.stringify(phrase)}));if(!h)continue;const b=h.tagName==='KAT-BUTTON'?h.shadowRoot?.querySelector('button'):h;if(!b||b.disabled)return false;b.click();return true}return false})()`)) === true;
}

async function frameHas(cdp, phrase) {
  return (await cdp.evaluate(`[...document.querySelectorAll('iframe')].some(f=>(f.contentDocument?.body?.innerText||'').includes(${JSON.stringify(phrase)}))`)) === true;
}

async function waitFrameHas(cdp, phrase, timeoutMs = 20000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (await frameHas(cdp, phrase)) return true;
    await sleep(500);
  }
  return false;
}

async function resolveOrderAsin(cdp, orderId) {
  if (!/^\d{3}-\d{7}-\d{7}$/.test(orderId)) return '';
  const url = `https://sellercentral.amazon.com.br/orders-v3/order/${encodeURIComponent(orderId)}`;
  await cdp.navigate(url, 5000);
  const auth = await authGate(cdp, 'help-v1', url, 5000);
  if (auth) return '';
  const state = await cdp.pageState(20000);
  const match = text(state.text).match(/ASIN:\s*([A-Z0-9]{10})/);
  return match?.[1] || '';
}

function supportRouteFor(job) {
  const explicit = text(job.payload?.decision?.support_route).toUpperCase();
  const program = text(job.case?.program).toUpperCase();
  const reason = text(job.payload?.decision?.reason).toUpperCase();
  if (explicit === 'FBA_RETURNS_REIMBURSEMENT') {
    return program === 'FBA' ? explicit : '';
  }
  if (explicit === 'GENERAL_ORDER_SUPPORT') return explicit;
  if (reason === 'CLASSIC_FBA_UNPAID_AFTER_FINANCE_RECONCILIATION' && program === 'FBA') {
    return 'FBA_RETURNS_REIMBURSEMENT';
  }
  if ([
    'SAFE_T_WINDOW_EXPIRED_RESIDUAL_UNPAID',
    'APPROVED_PARTIAL_REIMBURSEMENT_SUPPORT_RECOVERY',
    'OFFICIAL_APPEAL_WINDOW_EXPIRED_RECOVERY_CONTINUES',
    'EMAIL_REVIEW_DENIED_REQUIRES_SUPPORT',
    'EMAIL_REVIEW_ANALYZER_SELECTED_SUPPORT',
    'LEARNED_RULE_APPROVED',
  ].includes(reason)) return 'GENERAL_ORDER_SUPPORT';
  return '';
}
async function clickFrameTextWhenReady(cdp, label, timeoutMs = 30000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const clicked = await cdp.evaluate(`(()=>{for(const f of document.querySelectorAll('iframe')){const d=f.contentDocument;if(!d)continue;for(const h of d.querySelectorAll('kat-button,button')){const t=(h.getAttribute('label')||h.innerText||'').trim();if(t!==${JSON.stringify(label)})continue;const b=h.tagName==='KAT-BUTTON'?h.shadowRoot?.querySelector('button'):h;if(!b||b.disabled)continue;b.click();return true}}return false})()`);
    if (clicked === true) return true;
    await sleep(500);
  }
  return false;
}

async function clickFirstFrameTextWhenReady(cdp, labels, timeoutMs = 30000) {
  const wanted = [...new Set(labels.map(text).filter(Boolean))];
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const clicked = text(await cdp.evaluate(`(()=>{const labels=${JSON.stringify(wanted)};for(const wanted of labels){for(const f of document.querySelectorAll('iframe')){const d=f.contentDocument;if(!d)continue;for(const h of d.querySelectorAll('kat-button,button')){const label=(h.getAttribute('label')||h.innerText||'').trim();if(label!==wanted)continue;const b=h.tagName==='KAT-BUTTON'?h.shadowRoot?.querySelector('button'):h;if(!b||b.disabled)continue;b.click();return label}}}return ''})()`));
    if (clicked) return clicked;
    await sleep(500);
  }
  return '';
}

async function supportOrderInputReady(cdp) {
  return (await cdp.evaluate(`(()=>{for(const f of document.querySelectorAll('iframe')){const d=f.contentDocument;if(!d)continue;const h=d.querySelector('kat-input[placeholder*="112-"]');if(h&&!h.hasAttribute('disabled'))return true}return false})()`)) === true;
}

async function hillChatReady(cdp) {
  return (await cdp.evaluate(`(()=>{for(const f of document.querySelectorAll('iframe')){const h=f.contentDocument?.querySelector('spl-hill-form');const d=h?.shadowRoot?.querySelector('iframe')?.contentDocument;if(!d)continue;for(const b of d.querySelectorAll('kat-button,button')){const label=(b.getAttribute('label')||b.innerText||'').trim();if(!['Chat now','Conversar agora','Iniciar chat'].includes(label))continue;const button=b.tagName==='KAT-BUTTON'?b.shadowRoot?.querySelector('button'):b;if(button&&!button.disabled)return true}}return false})()`)) === true;
}

async function clickHillChat(cdp) {
  const point = await cdp.evaluate(`(()=>{for(const f of document.querySelectorAll('iframe')){const outer=f.contentDocument;const hill=outer?.querySelector('spl-hill-form');const innerFrame=hill?.shadowRoot?.querySelector('iframe');const d=innerFrame?.contentDocument;if(!d)continue;for(const h of d.querySelectorAll('kat-button,button')){const label=(h.getAttribute('label')||h.innerText||'').trim();if(!['Chat now','Conversar agora','Iniciar chat'].includes(label))continue;const button=h.tagName==='KAT-BUTTON'?h.shadowRoot?.querySelector('button'):h;if(!button||button.disabled)continue;f.scrollIntoView({block:'center'});hill.scrollIntoView({block:'center'});button.scrollIntoView({block:'center'});const a=f.getBoundingClientRect(),b=innerFrame.getBoundingClientRect(),c=button.getBoundingClientRect();const x=a.left+b.left+c.left+(c.width/2),y=a.top+b.top+c.top+(c.height/2);if(!Number.isFinite(x)||!Number.isFinite(y)||x<0||y<0||x>innerWidth||y>innerHeight)return null;return {x,y,label}}}return null})()`);
  if (!point || !Number.isFinite(point.x) || !Number.isFinite(point.y)) return '';
  await cdp.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: point.x, y: point.y, button: 'none' });
  await cdp.send('Input.dispatchMouseEvent', { type: 'mousePressed', x: point.x, y: point.y, button: 'left', clickCount: 1 });
  await cdp.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: point.x, y: point.y, button: 'left', clickCount: 1 });
  return text(point.label);
}

async function hillContactReady(cdp) {
  if (await hillChatReady(cdp)) return true;
  return (await cdp.evaluate(`(()=>{for(const f of document.querySelectorAll('iframe')){const h=f.contentDocument?.querySelector('spl-hill-form');const d=h?.shadowRoot?.querySelector('iframe')?.contentDocument;if(d?.querySelector('kat-tab[tab-id="Email"]'))return true}return false})()`)) === true;
}

async function submitHillEmail(cdp, job) {
  const orderId = text(job.case?.order_id);
  const safeTId = text(job.case?.safe_t_id);
  const subject = (`Revisão de reembolso - pedido ${orderId}${safeTId ? ` - SAFE-T ${safeTId}` : ''}`).slice(0, 180);
  const selected = (await cdp.evaluate(`(()=>{for(const f of document.querySelectorAll('iframe')){const h=f.contentDocument?.querySelector('spl-hill-form');const d=h?.shadowRoot?.querySelector('iframe')?.contentDocument;if(!d)continue;const tabs=d.querySelector('kat-tabs');const email=tabs?.querySelector('kat-tab[tab-id="Email"]');if(!tabs||!email)continue;tabs.selected='Email';tabs.setAttribute('selected','Email');tabs.dispatchEvent(new Event('change',{bubbles:true,composed:true}));return true}return false})()`)) === true;
  if (!selected) return 'SUPPORT_EMAIL_FORM_MISSING';
  await sleep(750);
  const prepared = text(await cdp.evaluate(`(()=>{const subject=${JSON.stringify(subject)};for(const f of document.querySelectorAll('iframe')){const h=f.contentDocument?.querySelector('spl-hill-form');const d=h?.shadowRoot?.querySelector('iframe')?.contentDocument;if(!d)continue;const email=d.querySelector('kat-tab[tab-id="Email"]');if(!email)continue;const required=email.querySelector('kat-input[required="true"]')?.shadowRoot?.querySelector('input');if(!required||!required.value.trim())return 'SUPPORT_EMAIL_ADDRESS_MISSING';const label=[...d.querySelectorAll('kat-label')].find(x=>/^(Subject|Assunto)/i.test((x.innerText||'').trim()));const id=label?.getAttribute('for')||'';const host=[...d.querySelectorAll('kat-input')].find(x=>x.getAttribute('unique-id')===id);const input=host?.shadowRoot?.querySelector('input');if(!input)return 'SUPPORT_EMAIL_SUBJECT_MISSING';Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(input,subject);input.dispatchEvent(new InputEvent('input',{bubbles:true,composed:true,inputType:'insertText',data:subject}));input.dispatchEvent(new Event('change',{bubbles:true,composed:true}));return input.value===subject?'READY':'SUPPORT_EMAIL_SUBJECT_NOT_WRITABLE'}return 'SUPPORT_EMAIL_FORM_MISSING'})()`));
  if (prepared !== 'READY') return prepared || 'SUPPORT_EMAIL_SUBJECT_NOT_WRITABLE';
  await sleep(500);
  const sendReady = text(await cdp.evaluate(`(()=>{for(const f of document.querySelectorAll('iframe')){const h=f.contentDocument?.querySelector('spl-hill-form');const d=h?.shadowRoot?.querySelector('iframe')?.contentDocument;if(!d)continue;const email=d.querySelector('kat-tab[tab-id="Email"]');const send=email?.querySelector('kat-button[label="Send"],kat-button[label="Enviar"]');const button=send?.shadowRoot?.querySelector('button');if(!button||button.disabled)return 'SUPPORT_EMAIL_SEND_MISSING';const label=(send.getAttribute('label')||send.innerText||'').trim();return label==='Send'||label==='Enviar'?'READY':'SUPPORT_EMAIL_SEND_MISSING'}return 'SUPPORT_EMAIL_FORM_MISSING'})()`));
  if (sendReady !== 'READY') return sendReady || 'SUPPORT_EMAIL_SEND_MISSING';
  if (!(await cdp.clickHillButtonTrusted('#send-email-button'))) return 'SUPPORT_EMAIL_SEND_MISSING';
  return 'Email';
}
async function supportCaseReadbackSnapshot(cdp) {
  return await cdp.evaluate(`(()=>{const out=[];const docs=[document];for(const f of document.querySelectorAll('iframe')){if(f.contentDocument)docs.push(f.contentDocument);const h=f.contentDocument?.querySelector('spl-hill-form');const d=h?.shadowRoot?.querySelector('iframe')?.contentDocument;if(d)docs.push(d)}for(const d of docs){out.push({url:d.location?.href||'',links:[...d.querySelectorAll('a[href]')].map(a=>({href:a.href||'',text:(a.innerText||'').trim().slice(0,120)})).filter(x=>/case|support/i.test(x.href+x.text)).slice(0,30),text:(d.body?.innerText||'').slice(0,5000)})}return out})()`);
}

function sellerSupportUnavailableResult() {
  return bridgeResult('BLOCKED_UNTIL', {
    block_reason: 'SELLER_SUPPORT_CURRENTLY_UNAVAILABLE',
    reason: 'SELLER_SUPPORT_CURRENTLY_UNAVAILABLE',
    retry_safe: true,
    next_allowed_at: new Date(Date.now() + 60 * 60 * 1000).toISOString(),
  });
}

function supportCaseIdFromHillTargetUrl(value) {
  try {
    const parsed = new URL(String(value ?? ''));
    if (!parsed.pathname.includes('/hill/website/chat')) return '';
    const caseId = String(parsed.searchParams.get('caseID') ?? '').trim();
    return /^\d{8,14}$/.test(caseId) ? caseId : '';
  } catch {
    return '';
  }
}

async function hillPopupSupportCaseIds() {
  let targets;
  try {
    const response = await fetch(`${CDP_BASE}/json/list`, { signal: AbortSignal.timeout(2500) });
    if (!response.ok) return [];
    targets = await response.json();
  } catch {
    return [];
  }
  if (!Array.isArray(targets)) return [];
  const ids = [];
  for (const row of targets) {
    if (row?.type !== 'page') continue;
    const caseId = supportCaseIdFromHillTargetUrl(row?.url);
    if (caseId && !ids.includes(caseId)) ids.push(caseId);
  }
  return ids;
}

async function hillPopupSupportCaseId(excludedCaseIds = []) {
  const excluded = new Set((Array.isArray(excludedCaseIds) ? excludedCaseIds : []).map(text).filter(Boolean));
  const ids = await hillPopupSupportCaseIds();
  for (const caseId of [...ids].reverse()) {
    if (!excluded.has(caseId)) return caseId;
  }
  return '';
}

async function hillPopupSupportState() {
  let targets;
  try {
    const response = await fetch(`${CDP_BASE}/json/list`, { signal: AbortSignal.timeout(2500) });
    if (!response.ok) return 'UNKNOWN';
    targets = await response.json();
  } catch {
    return 'UNKNOWN';
  }
  if (!Array.isArray(targets)) return 'UNKNOWN';
  const popup = [...targets].reverse().find(row =>
    row?.type === 'page'
    && text(row?.url).includes('/hill/website/chat')
    && text(row?.webSocketDebuggerUrl)
  );
  if (!popup) return 'NONE';
  let ws;
  let cdp;
  try {
    ws = new WebSocket(popup.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
      ws.addEventListener('open', resolve, { once: true });
      ws.addEventListener('error', reject, { once: true });
    });
    cdp = new Cdp(ws, null, 5000);
    const state = await cdp.pageState(5000);
    const body = text(state?.text);
    const phrases = [
      'No support agents are available right now',
      'Support currently unavailable',
      'Nenhum agente de suporte está disponível no momento',
      'Suporte indisponível no momento',
    ];
    return phrases.some(phrase => body.includes(phrase)) ? 'UNAVAILABLE' : 'AVAILABLE';
  } catch {
    return 'UNKNOWN';
  } finally {
    if (cdp) await cdp.close();
    else try { ws?.close(); } catch {}
  }
}

async function hillSupportUnavailable(cdp) {
  const embedded = (await cdp.evaluate(`(()=>{const phrases=['No support agents are available right now','Support currently unavailable','Nenhum agente de suporte está disponível no momento','Suporte indisponível no momento'];for(const f of document.querySelectorAll('iframe')){const outer=f.contentDocument;const hill=outer?.querySelector('spl-hill-form');const inner=hill?.shadowRoot?.querySelector('iframe')?.contentDocument;const bodies=[outer?.body?.innerText||'',inner?.body?.innerText||''];if(bodies.some(body=>phrases.some(p=>body.includes(p))))return true}return false})()`)) === true;
  if (embedded) return true;
  return await hillPopupSupportState() === 'UNAVAILABLE';
}

async function currentSupportCaseId(cdp) {
  return text(await cdp.evaluate(`(()=>{const docs=[document];for(const f of document.querySelectorAll('iframe')){if(f.contentDocument)docs.push(f.contentDocument);const h=f.contentDocument?.querySelector('spl-hill-form');const d=h?.shadowRoot?.querySelector('iframe')?.contentDocument;if(d)docs.push(d)}for(const d of docs){for(const a of d.querySelectorAll('a[href*="caseID="]')){const m=(a.href||'').match(/[?&]caseID=(\\d{8,14})/);if(m)return m[1]}const body=d.body?.innerText||'';const m=body.match(/(?:ID do caso|Case ID)[:\\s#-]*(\\d{8,14})/i);if(m)return m[1]}return ''})()`));
}

async function supportCaseMatchesJob(cdp, job, caseId) {
  const candidate = text(caseId);
  const needles = [text(job.case?.order_id), text(job.case?.safe_t_id)].filter(Boolean);
  if (!/^\d{8,14}$/.test(candidate) || needles.length === 0) return false;
  return (await cdp.evaluate(`(async()=>{try{const response=await fetch('/hill/hillservice/mons-api/ViewCase?caseId='+encodeURIComponent(${JSON.stringify(candidate)})+'&timeZone=UTC&pageSize=50',{credentials:'include'});if(!response.ok)return false;const detail=await response.json();const body=JSON.stringify(detail||{});const needles=${JSON.stringify(needles)};return needles.some(needle=>body.includes(needle))}catch{return false}})()`)) === true;
}

async function contactSupportAndReadBack(cdp, job) {
  const popupCaseIdsBeforeWrite = await hillPopupSupportCaseIds();
  const deadline = Date.now() + 90000;
  while (Date.now() < deadline && !(await hillContactReady(cdp))) await sleep(750);
  if (!(await hillContactReady(cdp))) {
    if (await hillPopupSupportState() === 'UNAVAILABLE') return sellerSupportUnavailableResult();
    return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CONTACT_CHANNEL_UNAVAILABLE', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  }
  let channel = await submitHillEmail(cdp, job);
  if (channel !== 'Email') {
    if (!(await hillChatReady(cdp))) {
      return bridgeResult('UI_DRIFT', { reason: channel || 'SUPPORT_CONTACT_CHANNEL_UNAVAILABLE', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
    }
    channel = await clickHillChat(cdp);
    if (!channel) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CHAT_START_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  }
  await sleep(1500);
  if (await hillSupportUnavailable(cdp)) {
    return bridgeResult('BLOCKED_UNTIL', {
      block_reason: 'SELLER_SUPPORT_CURRENTLY_UNAVAILABLE',
      reason: 'SELLER_SUPPORT_CURRENTLY_UNAVAILABLE',
      retry_safe: true,
      next_allowed_at: new Date(Date.now() + 60 * 60 * 1000).toISOString(),
    });
  }
  await sleep(1500);
  let caseId = await currentSupportCaseId(cdp);
  if (caseId && !(await supportCaseMatchesJob(cdp, job, caseId))) caseId = '';
  const excludedPopupCaseIds = [...popupCaseIdsBeforeWrite];
  for (let attempt = 0; !caseId && attempt < 8; attempt++) {
    const popupCaseId = await hillPopupSupportCaseId(excludedPopupCaseIds);
    if (popupCaseId) {
      if (await supportCaseMatchesJob(cdp, job, popupCaseId)) {
        caseId = popupCaseId;
        break;
      }
      if (!excludedPopupCaseIds.includes(popupCaseId)) excludedPopupCaseIds.push(popupCaseId);
    }
    if (!caseId) await sleep(750);
  }
  for (let attempt = 0; !caseId && attempt < 5; attempt++) {
    try {
      caseId = text(await findSupportCase(cdp, job, { includeTerminal: true }));
    } catch (error) {
      if (text(error?.message) === 'SUPPORT_CASE_LOOKUP_UNAVAILABLE') {
        return bridgeResult('FAILED', { reason: 'SUPPORT_CASE_LOOKUP_UNAVAILABLE_AFTER_WRITE', lookup_reason: text(error?.lookupReason || 'UNKNOWN'), submitted: false, retry_safe: false, evidence: { ...(await evidence(cdp, 'help-v1')), support_readback: await supportCaseReadbackSnapshot(cdp) } });
      }
      throw error;
    }
    if (!caseId) await sleep(4000);
  }
  if (!/^\d{8,14}$/.test(caseId)) {
    return bridgeResult('FAILED', { reason: 'SUPPORT_WRITE_WITHOUT_READBACK_ID', submitted: false, retry_safe: false, evidence: { ...(await evidence(cdp, 'help-v1')), support_readback: await supportCaseReadbackSnapshot(cdp) } });
  }
  const reason = channel === 'Email' ? 'SUPPORT_CASE_OPENED_VIA_EMAIL' : 'SUPPORT_CASE_OPENED_VIA_CHAT';
  return bridgeResult('ACCEPTED', { submitted: true, external_id: caseId, retry_safe: true, reason, evidence: await evidence(cdp, 'help-v1') });
}
async function fillGeneralSupportIssue(cdp, job, narrative) {
  const orderId = text(job.case?.order_id);
  const safeTId = text(job.case?.safe_t_id);
  const steps = `Reviewed Seller Central order and financial reconciliation. ${orderId ? `Order ${orderId}.` : ''} ${safeTId ? `SAFE-T ${safeTId}.` : ''}`.trim();
  const reference = [orderId && `Order ${orderId}`, safeTId && `SAFE-T ${safeTId}`].filter(Boolean).join('; ');
  const values = [narrative, steps, reference];
  return (await cdp.evaluate(`(()=>{const values=${JSON.stringify(values)};for(const f of document.querySelectorAll('iframe')){const d=f.contentDocument;if(!d)continue;const host=d.querySelector('kat-textarea.meld-text-area');const textarea=host?.shadowRoot?.querySelector('textarea');const inputs=[...d.querySelectorAll('kat-input')].filter(h=>!h.hasAttribute('disabled')).map(h=>h.shadowRoot?.querySelector('input')).filter(Boolean);if(!textarea||inputs.length<2)continue;const set=(el,v)=>{const proto=el.tagName==='TEXTAREA'?HTMLTextAreaElement.prototype:HTMLInputElement.prototype;Object.getOwnPropertyDescriptor(proto,'value').set.call(el,v);el.dispatchEvent(new InputEvent('input',{bubbles:true,composed:true,inputType:'insertText',data:v}));el.dispatchEvent(new Event('change',{bubbles:true,composed:true}))};set(textarea,values[0]);set(inputs[0],values[1]);set(inputs[1],values[2]);return textarea.value===values[0]&&inputs[0].value===values[1]&&inputs[1].value===values[2]}return false})()`)) === true;
}

async function fillDirectSupportCaseDetails(cdp, narrative) {
  const value = text(narrative);
  if (!value) return false;
  return await cdp.fillFrameInputTrustedByLabel([
    'Provide details about the order reimbursement error',
    'Forneça detalhes sobre o erro de reembolso do pedido',
  ], value);
}

async function waitForDirectSupportCaseDetails(cdp, narrative, timeoutMs = 15000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (await fillDirectSupportCaseDetails(cdp, narrative)) return true;
    await sleep(500);
  }
  return false;
}

async function submitDirectSupportCaseAndReadBack(cdp, job, narrative) {
  if (!(await waitForDirectSupportCaseDetails(cdp, narrative, 15000))) {
    return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_DIRECT_CASE_DETAILS_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  }
  if (!(await cdp.clickFrameButtonTrustedByText('Create a case'))) {
    return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_DIRECT_CREATE_BUTTON_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  }
  await sleep(2500);
  let caseId = await currentSupportCaseId(cdp);
  if (caseId && !(await supportCaseMatchesJob(cdp, job, caseId))) caseId = '';
  for (let attempt = 0; !caseId && attempt < 6; attempt++) {
    try {
      caseId = text(await findSupportCase(cdp, job, { includeTerminal: true }));
    } catch (error) {
      if (text(error?.message) === 'SUPPORT_CASE_LOOKUP_UNAVAILABLE') {
        return bridgeResult('FAILED', { reason: 'SUPPORT_CASE_LOOKUP_UNAVAILABLE_AFTER_WRITE', lookup_reason: text(error?.lookupReason || 'UNKNOWN'), submitted: false, retry_safe: false, evidence: { ...(await evidence(cdp, 'help-v1')), support_readback: await supportCaseReadbackSnapshot(cdp) } });
      }
      throw error;
    }
    if (!caseId) await sleep(3000);
  }
  if (!/^\d{8,14}$/.test(caseId)) {
    return bridgeResult('FAILED', { reason: 'SUPPORT_WRITE_WITHOUT_READBACK_ID', submitted: false, retry_safe: false, evidence: { ...(await evidence(cdp, 'help-v1')), support_readback: await supportCaseReadbackSnapshot(cdp) } });
  }
  return bridgeResult('ACCEPTED', { submitted: true, external_id: caseId, retry_safe: true, reason: 'SUPPORT_CASE_OPENED_DIRECTLY', evidence: await evidence(cdp, 'help-v1') });
}

async function openGeneralSupportRoute(cdp, job, narrative, asin, sku) {
  if (!(await waitFrameHas(cdp, 'My issue is not listed', 60000)) || !(await clickFrameTextWhenReady(cdp, 'My issue is not listed', 10000))) {
    return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_GENERAL_ROUTE_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  }
  const formReady = await cdp.waitFor(`(()=>{for(const f of document.querySelectorAll('iframe')){const d=f.contentDocument;if(!d)continue;if(d.querySelector('kat-textarea.meld-text-area')&&d.querySelectorAll('kat-input').length>=2)return true}return false})()`, 30000);
  if (!formReady || !(await fillGeneralSupportIssue(cdp, job, narrative))) {
    return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_GENERAL_DESCRIPTION_FIELDS_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  }
  if (!(await clickFrameTextWhenReady(cdp, 'Continue', 20000))) {
    return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_GENERAL_CONTINUE_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  }
  if (await waitFrameHas(cdp, 'Use original text', 30000)) {
    await clickFrameTextWhenReady(cdp, 'Use original text', 10000);
    if (!(await clickFrameTextWhenReady(cdp, 'Continue', 20000))) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_GENERAL_SUGGESTION_CONTINUE_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  }

  const orderId = text(job.case?.order_id);
  const troubleshooterDeadline = Date.now() + 120000;
  let contacted = false;
  let suggestedOrderEntered = false;
  while (Date.now() < troubleshooterDeadline) {
    if (await frameHas(cdp, 'Create a case')) {
      return await submitDirectSupportCaseAndReadBack(cdp, job, narrative);
    }
    if (await frameHas(cdp, 'Contact an associate')) {
      const contactClicked = (await cdp.clickFrameButtonTrustedByText('Contact an associate'))
        || Boolean(await clickFrameTextWhenReady(cdp, 'Contact an associate', 1500));
      if (!contactClicked) {
        return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CONTACT_ASSOCIATE_CLICK_FAILED', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
      }
      contacted = true;
      break;
    }
    const suggestedOrderReady = (await cdp.evaluate(`(()=>{for(const f of document.querySelectorAll('iframe')){const h=f.contentDocument?.querySelector('kat-input[placeholder*="112-"]');if(h&&!h.hasAttribute('disabled'))return true}return false})()`)) === true;
    if (suggestedOrderReady && !suggestedOrderEntered) {
      if (!(await cdp.setFrameKat('kat-input[placeholder*="112-"]', orderId))) {
        return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_GENERAL_SUGGESTED_ORDER_INPUT_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
      }
      suggestedOrderEntered = true;
      if (!(await clickFrameTextWhenReady(cdp, 'Continue', 10000))) {
        return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_GENERAL_SUGGESTED_ORDER_CONTINUE_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
      }
      await sleep(750);
      continue;
    }
    const troubleshootingAction = await clickFirstFrameTextWhenReady(cdp, [
      'Request Reimbursement for an Order',
      'Solicitar reembolso para um pedido',
      'Get help',
      'Obter ajuda',
      'Having issues with your order?',
      'Está com problemas com seu pedido?',
    ], 1500);
    if (troubleshootingAction) {
      await sleep(750);
      continue;
    }
    await sleep(750);
  }
  if (!contacted) {
    return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_GENERAL_TROUBLESHOOTER_EXHAUSTED', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  }

  const associateCategories = text(job.case?.program).toUpperCase() === 'FBA'
    ? ['FBA related','A-to-z Claims']
    : ['A-to-z Claims'];
  const category = await clickFirstFrameTextWhenReady(cdp, associateCategories, 45000);
  if (!category) {
    return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_GENERAL_CATEGORY_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  }

  if (await supportOrderInputReady(cdp)) {
    if (!orderId || !(await cdp.setFrameKat('kat-input[placeholder*="112-"]', orderId))) {
      return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_GENERAL_CATEGORY_ORDER_INPUT_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
    }
    if (!(await clickFrameTextWhenReady(cdp, 'Continue', 20000))) {
      return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_GENERAL_CATEGORY_ORDER_CONTINUE_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
    }
    const postOrderDeadline = Date.now() + 30000;
    while (Date.now() < postOrderDeadline) {
      if (await hillContactReady(cdp)) return null;
      if (!(await supportOrderInputReady(cdp))) break;
      await sleep(500);
    }
  }

  const identityDeadline = Date.now() + 30000;
  let idsReady = false;
  while (Date.now() < identityDeadline) {
    if (await hillContactReady(cdp)) return null;
    idsReady = (await cdp.evaluate(`(()=>{for(const f of document.querySelectorAll('iframe')){const d=f.contentDocument;if(!d)continue;if(d.querySelector('kat-input[placeholder*="ASIN"]')&&d.querySelector('kat-input[placeholder*="SKU"]'))return true}return false})()`)) === true;
    if (idsReady) break;
    await sleep(500);
  }
  if (!idsReady) {
    return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_GENERAL_PRODUCT_FIELDS_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  }
  if (!asin || !sku) return bridgeResult('FAILED', { reason: 'SUPPORT_GENERAL_PRODUCT_IDENTITY_REQUIRED', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  if (!(await cdp.setFrameKat('kat-input[placeholder*="ASIN"],kat-input[placeholder="Enter ASIN"]', asin)) || !(await cdp.setFrameKat('kat-input[placeholder*="SKU"],kat-input[placeholder="Enter SKU"]', sku))) {
    return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_GENERAL_PRODUCT_FIELDS_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  }
  if (!(await clickFrameTextWhenReady(cdp, 'Continue', 20000))) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_GENERAL_PRODUCT_CONTINUE_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  return null;
}
async function openFbaSupportCardWithRetry(cdp) {
  for (let attempt = 0; attempt < 2; attempt += 1) {
    const englishWait = attempt === 0 ? 60000 : 45000;
    const fbaEnglish = await waitFrameHas(cdp, 'FBA Returns Reimbursement', englishWait);
    const fbaPortuguese = fbaEnglish ? false : await waitFrameHas(cdp, 'Reembolso de devoluções com FBA - Logística da Amazon', 10000);
    const opened = fbaEnglish
      ? await clickFrameIncludes(cdp, 'FBA Returns Reimbursement')
      : (fbaPortuguese ? await clickFrameIncludes(cdp, 'Reembolso de devoluções com FBA - Logística da Amazon') : false);
    if (opened) return { opened: true, auth: null };
    if (attempt === 0) {
      await cdp.navigate(HELP_URL, 6000);
      const auth = await authGate(cdp, 'help-v1', HELP_URL, 6000);
      if (auth) return { opened: false, auth };
    }
  }
  return { opened: false, auth: null };
}
async function supportOpen(cdp, job, options = {}) {
  const snapshotFailure = writeSnapshotFailure(job);
  if (snapshotFailure) return snapshotFailure;
  const supportRoute = supportRouteFor(job);
  if (!supportRoute) return bridgeResult('FAILED', { reason: 'SUPPORT_ROUTE_UNSUPPORTED', retry_safe: false });
  const decisionReason = text(job.payload?.decision?.reason).toUpperCase();
  const physicalStatus = text(job.case?.physical_status).toUpperCase();
  if (decisionReason === 'CLASSIC_FBA_UNPAID_AFTER_FINANCE_RECONCILIATION' && physicalStatus === 'RECEIVED_OK') {
    return bridgeResult('SUPERSEDED', { reason: 'PHYSICAL_RETURN_RECEIVED_BEFORE_SUPPORT_OPEN', retry_safe: false });
  }
  const retryReconciliation = Number(job.attempt_count || 0) > 1 && options.forceFreshCase !== true;
  let existing;
  try {
    existing = await findSupportCase(cdp, job, retryReconciliation ? { includeTerminal: true } : {});
  } catch (error) {
    if (text(error?.message) === 'SUPPORT_CASE_LOOKUP_UNAVAILABLE') {
      return bridgeResult('UI_DRIFT', {
        reason: 'SUPPORT_CASE_LOOKUP_UNAVAILABLE',
        lookup_phase: retryReconciliation ? 'RETRY_RECONCILIATION' : 'PRE_WRITE',
        lookup_reason: text(error?.lookupReason || 'UNKNOWN'), retry_safe: true, evidence: await evidence(cdp, 'help-v1')
      });
    }
    throw error;
  }
  if (existing) return bridgeResult('ALREADY_EXISTS', {
    external_id: existing, retry_safe: true,
    ...(retryReconciliation ? { reason: 'SUPPORT_RETRY_RECONCILED_TERMINAL_CASE' } : {}),
    evidence: await evidence(cdp, 'help-v1')
  });
  const orderId = text(job.case?.order_id);
  if (!/^\d{3}-\d{7}-\d{7}$/.test(orderId)) return bridgeResult('FAILED', { reason: 'SUPPORT_ORDER_ID_REQUIRED' });
  const resolvedAsin = text(job.case?.asin || await resolveOrderAsin(cdp, orderId));
  const sku = text(job.case?.sku);
  const narrative = narrativeFor(job, 9000);
  await cdp.navigate(HELP_URL, 6000);
  const auth = await authGate(cdp, 'help-v1', HELP_URL, 6000);
  if (auth) return auth;
  if (supportRoute === 'GENERAL_ORDER_SUPPORT') {
    const routeFailure = await openGeneralSupportRoute(cdp, job, narrative, resolvedAsin, sku);
    if (routeFailure) return routeFailure;
    return await contactSupportAndReadBack(cdp, job);
  }

  const fbaRoute = await openFbaSupportCardWithRetry(cdp);
  if (fbaRoute.auth) return fbaRoute.auth;
  if (!fbaRoute.opened) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_FBA_CARD_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  const orderInputReady = await cdp.waitFor(`(()=>{for(const f of document.querySelectorAll('iframe')){const h=f.contentDocument?.querySelector('kat-input[placeholder*="112-"]');if(h&&!h.hasAttribute('disabled'))return true}return false})()`, 30000);
  if (!orderInputReady || !(await cdp.setFrameKat('kat-input[placeholder*="112-"]', orderId))) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_ORDER_INPUT_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  if (!(await clickFrameTextWhenReady(cdp, 'Continue', 20000)) && !(await clickFrameTextWhenReady(cdp, 'Continuar', 10000))) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_ORDER_CONTINUE_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  const flowDeadline = Date.now() + 180000;
  let fbaAsinFilled = false;
  let narrativeFilled = false;
  while (Date.now() < flowDeadline) {
    if (await hillContactReady(cdp)) return await contactSupportAndReadBack(cdp, job);
    const contactSelected = (await cdp.frameOptionSelectedByText('Contact an associate'))
      || (await cdp.frameOptionSelectedByText('Entre em contato com um associado'));
    if (contactSelected) {
      const additionalInfoReady = (await cdp.evaluate(`(()=>{for(const f of document.querySelectorAll('iframe')){const h=f.contentDocument?.querySelector('kat-textarea.meld-text-area');if(h&&!h.hasAttribute('disabled'))return true}return false})()`)) === true;
      if (!additionalInfoReady) {
        await sleep(500);
        continue;
      }
      if (!narrativeFilled) {
        if (!(await cdp.fillFrameTextareaTrusted('kat-textarea.meld-text-area', narrative))) {
          return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CONTACT_ADDITIONAL_INFO_NOT_WRITABLE', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
        }
        narrativeFilled = true;
        await sleep(500);
      }
      const advanced = (await cdp.clickFrameButtonTrustedByText('Continue'))
        || (await cdp.clickFrameButtonTrustedByText('Continuar'))
        || Boolean(await clickFirstFrameTextWhenReady(cdp, ['Continue','Continuar'], 10000));
      if (!advanced) {
        return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CONTACT_SELECTED_CONTINUE_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
      }
      await sleep(750);
      continue;
    }
    if (await frameHas(cdp, 'Create a case')) {
      return await submitDirectSupportCaseAndReadBack(cdp, job, narrative);
    }
    if (await cdp.frameButtonReadyByText('Contact an associate')) {
      const contactClicked = (await cdp.clickFrameButtonTrustedByText('Contact an associate'))
        || Boolean(await clickFrameTextWhenReady(cdp, 'Contact an associate', 5000));
      if (!contactClicked) {
        return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CONTACT_ASSOCIATE_CLICK_FAILED', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
      }
      await sleep(750);
      if (await hillContactReady(cdp)) continue;
      const postContactContinue = (await cdp.clickFrameButtonTrustedByText('Continue'))
        || (await cdp.clickFrameButtonTrustedByText('Continuar'))
        || Boolean(await clickFirstFrameTextWhenReady(cdp, ['Continue','Continuar'], 5000));
      if (postContactContinue) { await sleep(750); continue; }
      const routed = await clickFirstFrameTextWhenReady(cdp, ['FBA related','A-to-z Claims'], 15000);
      if (routed) { await sleep(750); continue; }
      continue;
    }
    if (await cdp.frameButtonReadyByText('Entre em contato com um associado')) {
      const contactClicked = (await cdp.clickFrameButtonTrustedByText('Entre em contato com um associado'))
        || Boolean(await clickFrameTextWhenReady(cdp, 'Entre em contato com um associado', 5000));
      if (!contactClicked) {
        return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CONTACT_ASSOCIATE_CLICK_FAILED', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
      }
      await sleep(750);
      if (await hillContactReady(cdp)) continue;
      const postContactContinue = (await cdp.clickFrameButtonTrustedByText('Continue'))
        || (await cdp.clickFrameButtonTrustedByText('Continuar'))
        || Boolean(await clickFirstFrameTextWhenReady(cdp, ['Continue','Continuar'], 5000));
      if (postContactContinue) { await sleep(750); continue; }
      const routed = await clickFirstFrameTextWhenReady(cdp, ['FBA related','A-to-z Claims'], 15000);
      if (routed) { await sleep(750); continue; }
      continue;
    }
    if (await frameHas(cdp, 'Having issues with your order?')) {
      await clickFrameTextWhenReady(cdp, 'Having issues with your order?', 5000);
      await sleep(750);
      continue;
    }
    if (await frameHas(cdp, 'Está com problemas com seu pedido?')) {
      await clickFrameTextWhenReady(cdp, 'Está com problemas com seu pedido?', 5000);
      await sleep(750);
      continue;
    }
    if (await frameHas(cdp, 'Request Reimbursement for an Order')) {
      await clickFrameTextWhenReady(cdp, 'Request Reimbursement for an Order', 5000);
      await sleep(750);
      continue;
    }
    if (await frameHas(cdp, 'Solicitar reembolso para um pedido')) {
      await clickFrameTextWhenReady(cdp, 'Solicitar reembolso para um pedido', 5000);
      await sleep(750);
      continue;
    }
    const asinSelector = 'kat-input[placeholder="Inserir ASIN"],kat-input[placeholder="Enter ASIN"]';
    const asinRequired = await cdp.evaluate(`(()=>{const selector=${JSON.stringify(asinSelector)};for(const f of document.querySelectorAll('iframe')){const h=f.contentDocument?.querySelector(selector);if(h&&!h.hasAttribute('disabled'))return true}return false})()`);
    if (asinRequired && !fbaAsinFilled) {
      if (!resolvedAsin || !(await cdp.setFrameKat(asinSelector, resolvedAsin))) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_ASIN_INPUT_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
      fbaAsinFilled = true;
      await sleep(500);
      continue;
    }
    const textareaReady = await cdp.evaluate(`(()=>{for(const f of document.querySelectorAll('iframe')){const h=f.contentDocument?.querySelector('kat-textarea.meld-text-area');if(h&&!h.hasAttribute('disabled'))return true}return false})()`);
    if (textareaReady && !narrativeFilled) {
      if (!(await cdp.setFrameKat('kat-textarea.meld-text-area', narrative))) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CONTACT_TEXTAREA_NOT_WRITABLE', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
      narrativeFilled = true;
      await sleep(500);
      if (!(await clickFrameTextWhenReady(cdp, 'Continue', 10000)) && !(await clickFrameTextWhenReady(cdp, 'Continuar', 5000))) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CONTACT_CONTINUE_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
      await sleep(750);
      continue;
    }
    await sleep(750);
  }
  // Seller Central may surface the final contact control exactly as the normal
  // transition deadline expires. Process one bounded actionable grace phase
  // before classifying the UI as drift.
  for (const contactLabel of ['Contact an associate','Entre em contato com um associado']) {
    if (!(await frameHas(cdp, contactLabel))) continue;
    const clicked = (await cdp.clickFrameButtonTrustedByText(contactLabel))
      || Boolean(await clickFrameTextWhenReady(cdp, contactLabel, 5000));
    if (!clicked) {
      return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CONTACT_ASSOCIATE_CLICK_FAILED', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
    }
    const graceDeadline = Date.now() + 45000;
    while (Date.now() < graceDeadline) {
      if (await hillContactReady(cdp)) return await contactSupportAndReadBack(cdp, job);
      if (await frameHas(cdp, 'Create a case')) return await submitDirectSupportCaseAndReadBack(cdp, job, narrative);
      const postContactContinue = (await cdp.clickFrameButtonTrustedByText('Continue'))
        || (await cdp.clickFrameButtonTrustedByText('Continuar'))
        || Boolean(await clickFirstFrameTextWhenReady(cdp, ['Continue','Continuar'], 1500));
      if (postContactContinue) { await sleep(750); continue; }
      const routed = await clickFirstFrameTextWhenReady(cdp, ['FBA related','A-to-z Claims'], 1500);
      if (routed) { await sleep(750); continue; }
      await sleep(750);
    }
    break;
  }
  if (await hillPopupSupportState() === 'UNAVAILABLE') return sellerSupportUnavailableResult();
  return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CHAT_CHANNEL_UNAVAILABLE', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
}
async function ensureSupportReplyComposer(cdp) {
  const triggerLabels=['Reply','Responder'];
  const deadline = Date.now() + 15000;
  let triggered = false;
  while (Date.now() < deadline) {
    const selector = text(await cdp.evaluate(`(()=>{const usable=h=>{if(!h||h.disabled===true||h.hasAttribute('disabled'))return false;const placeholder=(h.getAttribute('placeholder')||'').toLowerCase();return !placeholder.includes('feedback')};const kat=[...document.querySelectorAll('kat-textarea')].find(usable);if(kat)return 'kat-textarea';const native=[...document.querySelectorAll('textarea')].find(usable);return native?'textarea':''})()`));
    if (selector) return selector;
    if (!triggered) {
      const opened = text(await cdp.evaluate(`(()=>{const labels=${JSON.stringify(triggerLabels)};for(const h of document.querySelectorAll('kat-button,button')){const label=(h.getAttribute('label')||h.getAttribute('aria-label')||h.innerText||'').trim();if(!labels.includes(label))continue;const b=h.tagName==='KAT-BUTTON'?(h.shadowRoot?.querySelector('button')||h):h;if(b&&!b.disabled){b.click();return label}}return ''})()`));
      if (opened) triggered = true;
    }
    await sleep(500);
  }
  return '';
}
async function supportUpdate(cdp, job) {
  const snapshotFailure = writeSnapshotFailure(job);
  if (snapshotFailure) return snapshotFailure;
  const caseId = text(job.case?.support_case_id);
  if (!/^\d{8,14}$/.test(caseId)) return supportOpen(cdp, job);
  await cdp.navigate(`https://sellercentral.amazon.com.br/cu/case-dashboard/view-case?caseID=${encodeURIComponent(caseId)}`, 5000);
  const auth = await authGate(cdp, 'help-v1', `https://sellercentral.amazon.com.br/cu/case-dashboard/view-case?caseID=${encodeURIComponent(caseId)}`, 5000);
  if (auth) return auth;
  const supportPage = await cdp.pageState(18000);
  const supportBody = text(supportPage?.text).toLowerCase();
  if (supportBody.includes('answered cases cannot be reopened after 5 days with no activity')
      || supportBody.includes('casos respondidos não podem ser reabertos após 5 dias sem atividade')
      || supportBody.includes('casos respondidos nao podem ser reabertos apos 5 dias sem atividade')) {
    if (supportRouteFor(job)) {
      return await supportOpen(cdp, job, { forceFreshCase: true });
    }
    return bridgeResult('SUPERSEDED', { reason: 'SUPPORT_CASE_NOT_REOPENABLE', retry_safe: false, evidence: await evidence(cdp, 'help-v1') });
  }
  const narrative = narrativeFor(job, 9000);
  const already = await cdp.evaluate(`(document.body?.innerText||'').includes(${JSON.stringify(narrative.slice(0, 240))})`);
  if (already) return bridgeResult('ALREADY_EXISTS', { external_id: caseId, retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  const selector = await ensureSupportReplyComposer(cdp);
  if (!text(selector)) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_REPLY_FIELD_MISSING', evidence: await evidence(cdp, 'help-v1') });
  if (selector === 'kat-textarea') {
    if (!(await cdp.setKat('kat-textarea', narrative))) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_REPLY_FIELD_NOT_WRITABLE', evidence: await evidence(cdp, 'help-v1') });
  } else {
    const ok = await cdp.evaluate(`(()=>{const i=[...document.querySelectorAll('textarea')].find(h=>{if(h.disabled===true||h.hasAttribute('disabled'))return false;const placeholder=(h.getAttribute('placeholder')||'').toLowerCase();return !placeholder.includes('feedback')});if(!i)return false;const setter=Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype,'value').set;setter.call(i,${JSON.stringify(narrative)});i.dispatchEvent(new InputEvent('input',{bubbles:true,inputType:'insertText',data:${JSON.stringify(narrative)}}));i.dispatchEvent(new Event('change',{bubbles:true}));return i.value===${JSON.stringify(narrative)}})()`);
    if (!ok) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_NATIVE_REPLY_NOT_WRITABLE', evidence: await evidence(cdp, 'help-v1') });
  }
  const sent = await cdp.evaluate(`(()=>{const labels=['Send','Send message','Reply','Enviar','Enviar mensagem','Responder'];for(const h of document.querySelectorAll('kat-button,button')){const label=(h.getAttribute('label')||h.innerText||'').trim();if(!labels.includes(label))continue;const b=h.tagName==='KAT-BUTTON'?h.shadowRoot?.querySelector('button'):h;if(b&&!b.disabled){b.click();return label}}return ''})()`);
  if (!text(sent)) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_REPLY_SEND_MISSING', evidence: await evidence(cdp, 'help-v1') });
  await sleep(5000);
  const confirmed = await cdp.evaluate(`(document.body?.innerText||'').includes(${JSON.stringify(narrative.slice(0, 240))})`);
  if (!confirmed) return bridgeResult('FAILED', { reason: 'SUPPORT_REPLY_NOT_CONFIRMED', retry_safe: false, evidence: await evidence(cdp, 'help-v1') });
  return bridgeResult('ACCEPTED', {
    submitted: true,
    external_id: caseId,
    retry_safe: true,
    reason: 'SUPPORT_CASE_UPDATED_AND_READ_BACK',
    evidence: await evidence(cdp, 'help-v1'),
  });
}

async function executeJob(job) {
  const cdp = await Cdp.connect();
  try {
    if (job.write_enabled !== true) return bridgeResult('FAILED', { reason: 'SERVER_WRITE_FLAG_OFF' });
    if (job.action === 'SAFE_T_SUBMIT') return await safeTSubmit(cdp, job);
    if (job.action === 'SAFE_T_APPEAL') return await safeTAppeal(cdp, job);
    if (job.action === 'SELLER_SUPPORT_OPEN') return await supportOpen(cdp, job);
    if (job.action === 'SELLER_SUPPORT_UPDATE') return await supportUpdate(cdp, job);
    return bridgeResult('FAILED', { reason: 'UNSUPPORTED_JOB_ACTION' });
  } finally {
    await cdp.close();
  }
}

function log(event, data = {}) {
  const safe = {
    at: new Date().toISOString(),
    event,
    job_id: data.job_id ?? null,
    action: data.action ?? null,
    status: data.status ?? null,
    external_id: data.external_id ?? null,
    reason: data.reason ?? null,
    lookup_reason: data.lookup_reason ?? null,
    safe_t_subreason_shape: normalizeSafeTSubreasonShape(data.safe_t_subreason_shape),
  };
  process.stdout.write(`${JSON.stringify(safe)}\n`);
}

async function runOnce() {
  const pulled = await bridge('pull', { worker_id: WORKER_ID });
  if (pulled.status === 'NO_JOB') return { processed: false, drainBlocked: false };
  if (pulled.status !== 'JOB' || !pulled.job) throw new Error(`unexpected pull status ${text(pulled.status)}`);
  const job = pulled.job;
  log('job_received', job);
  let result;
  try {
    result = await executeJob(job);
  } catch (error) {
    log('job_exception', { ...job, status: 'FAILED', reason: `UNHANDLED_${error?.name || 'ERROR'}:${text(error?.message).slice(0, 240)}` });
    result = bridgeResult('FAILED', { reason: `UNHANDLED_${error?.name || 'ERROR'}`, retry_safe: false });
  }
  await bridge('result', { job_id: job.job_id, idempotency_key: job.idempotency_key, result });
  log('job_result', { ...job, ...result });
  const drainBlocked = ['AUTH_REQUIRED','HUMAN_CHALLENGE'].includes(result.status);
  return { processed: true, drainBlocked };
}

async function main() {
  const jobFileIndex = process.argv.indexOf('--job-file');
  if (jobFileIndex >= 0) {
    const path = process.argv[jobFileIndex + 1];
    if (!path) throw new Error('--job-file requires a path');
    const job = JSON.parse(fs.readFileSync(path, 'utf8'));
    const result = await executeJob(job);
    process.stdout.write(`${JSON.stringify(result)}\n`);
    return;
  }
  if (process.argv.includes('--heartbeat')) {
    const heartbeat = await bridge('heartbeat', { worker_id: WORKER_ID });
    process.stdout.write(`${JSON.stringify(heartbeat)}\n`);
    return;
  }
  if (process.argv.includes('--drain')) {
    while (!quiesceRequested()) {
      const outcome = await runOnce();
      if (!outcome.processed || outcome.drainBlocked) break;
    }
    return;
  }
  if (process.argv.includes('--once')) {
    await runOnce();
    return;
  }

  log('worker_started');
  while (true) {
    try {
      const outcome = await runOnce();
      if (!outcome.processed || outcome.drainBlocked) await sleep(POLL_MS);
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
