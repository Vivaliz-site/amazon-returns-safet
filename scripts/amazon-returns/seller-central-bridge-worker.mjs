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
const SAFE_T_BASE = 'https://sellercentral.amazon.com.br/safet-claims';
const HELP_URL = 'https://sellercentral.amazon.com.br/help/center?redirectSource=Hill';
const CASE_LOBBY = 'https://sellercentral.amazon.com.br/cu/case-lobby';
const supportHistoryRaw = Number(process.env.SELLER_CENTRAL_SUPPORT_CASE_HISTORY_LIMIT || 500);
const SUPPORT_CASE_HISTORY_LIMIT = Number.isFinite(supportHistoryRaw)
  ? Math.max(50, Math.min(500, Math.trunc(supportHistoryRaw)))
  : 500;
const SUPPORT_CASE_TERMINAL_STATUSES = ['RESOLVED','CLOSED','CANCELLED'];

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
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

class Cdp {
  constructor(ws, targetId = null) {
    this.ws = ws;
    this.targetId = targetId;
    this.id = 0;
    this.pending = new Map();
    ws.addEventListener('message', event => {
      const message = JSON.parse(event.data);
      if (!message.id || !this.pending.has(message.id)) return;
      const waiter = this.pending.get(message.id);
      this.pending.delete(message.id);
      message.error ? waiter.reject(new Error(message.error.message || 'CDP error')) : waiter.resolve(message.result);
    });
  }

  static async connect() {
    await ensureBrowser();
    const response = await fetch(`${CDP_BASE}/json/new?${encodeURIComponent('about:blank')}`, { method: 'PUT' });
    if (!response.ok) throw new Error(`could not create isolated CDP target (${response.status})`);
    const page = await response.json();
    if (!page?.id || !page?.webSocketDebuggerUrl) throw new Error('isolated CDP page target unavailable');
    const ws = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
      ws.addEventListener('open', resolve, { once: true });
      ws.addEventListener('error', reject, { once: true });
    });
    return new Cdp(ws, page.id);
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
    if (result.exceptionDetails) {
      const details = result.exceptionDetails;
      const description = text(details.exception?.description || details.text || 'browser expression failed').replace(/\s+/g, ' ').slice(0, 240);
      const lineNumber = Number.isInteger(details.lineNumber) ? details.lineNumber : -1;
      const columnNumber = Number.isInteger(details.columnNumber) ? details.columnNumber : -1;
      throw new Error(`browser expression failed: ${description} @${lineNumber}:${columnNumber}`);
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

  async clickFrameButtonTrustedByText(label) {
    const point = await this.evaluate(`(()=>{const label=${JSON.stringify(label)};for(const f of document.querySelectorAll('iframe')){const d=f.contentDocument;if(!d)continue;for(const host of d.querySelectorAll('kat-button,button')){const text=(host.getAttribute('label')||host.innerText||'').trim();if(text!==label)continue;const button=host.tagName==='KAT-BUTTON'?host.shadowRoot?.querySelector('button'):host;if(!button||button.disabled)continue;f.scrollIntoView({block:'center'});button.scrollIntoView({block:'center'});const a=f.getBoundingClientRect(),b=button.getBoundingClientRect();const x=a.left+b.left+(b.width/2),y=a.top+b.top+(b.height/2);if(!Number.isFinite(x)||!Number.isFinite(y)||x<0||y<0||x>innerWidth||y>innerHeight)return null;return {x,y}}}return null})()`);
    if (!point || !Number.isFinite(point.x) || !Number.isFinite(point.y)) return false;
    await this.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: point.x, y: point.y, button: 'none' });
    await this.send('Input.dispatchMouseEvent', { type: 'mousePressed', x: point.x, y: point.y, button: 'left', clickCount: 1 });
    await this.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: point.x, y: point.y, button: 'left', clickCount: 1 });
    return true;
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

  close() {
    try { this.ws.close(); } catch {}
    if (this.targetId) fetch(`${CDP_BASE}/json/close/${encodeURIComponent(this.targetId)}`).catch(() => {});
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
  const explicit = text(job.payload?.reason_code).toUpperCase();
  const explicitSub = text(job.payload?.reason_subcategory);
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

async function safeTSubmit(cdp, job) {
  const snapshotFailure = writeSnapshotFailure(job);
  if (snapshotFailure) return snapshotFailure;
  const orderId = text(job.case?.order_id);
  if (!/^\d{3}-\d{7}-\d{7}$/.test(orderId)) return bridgeResult('FAILED', { reason: 'INVALID_ORDER_ID' });
  const trackingEvidence = await captureTrackingEvidence(cdp, orderId);
  await cdp.navigate(`${SAFE_T_BASE}/create-v2?ref_=ag_sfdcf_cont_safet`, 5000);
  const auth = await authGate(cdp, 'safet-v1', `${SAFE_T_BASE}/create-v2?ref_=ag_sfdcf_cont_safet`, 5000);
  if (auth) return auth;
  if (!(await cdp.setKat('kat-input[placeholder="Número do pedido"]', orderId))) {
    return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_ORDER_INPUT_MISSING', evidence: await evidence(cdp, 'safet-v1') });
  }
  if (!(await cdp.clickKat('kat-button[label="Verificar Elegibilidade"]'))) {
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
  const qtySelector = 'kat-input[type="number"]';
  await cdp.setKat(qtySelector, String(quantity));
  if (!(await cdp.clickKat('kat-checkbox.QuantityCheckbox'))) {
    return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_ITEM_CHECKBOX_MISSING', evidence: await evidence(cdp, 'safet-v1') });
  }
  await sleep(500);
  const nextEnabled = await cdp.evaluate(`!document.querySelector('kat-button[label="Próximo"]')?.hasAttribute('disabled')`);
  if (!nextEnabled || !(await cdp.clickKat('kat-button[label="Próximo"]'))) {
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
    if (!(await cdp.selectKatOption('kat-dropdown[placeholder="Selecione a Subcategoria do Motivo"]', reason.sub))) {
      return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_SUBREASON_OPTION_MISSING', evidence: await evidence(cdp, 'safet-v1') });
    }
  }
  await sleep(500);
  if (!(await cdp.clickKat('kat-button[label="Próximo"]'))) {
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
  if (!(await cdp.clickKat('kat-button[label="Próximo"]'))) {
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

async function scanSupportCaseHistory(cdp, job, preferredCaseId = '', { includeTerminal = false } = {}) {
  const orderId = text(job.case?.order_id);
  const safeTId = text(job.case?.safe_t_id);
  const needles = [orderId, safeTId].filter(Boolean);
  if (needles.length === 0) return null;
  const preferred = /^\d{8,14}$/.test(text(preferredCaseId)) ? text(preferredCaseId) : '';
  const raw = await cdp.evaluate(`(async()=>{
    const needles=${JSON.stringify(needles)};
    const preferred=${JSON.stringify(preferred)};
    const includeTerminal=${includeTerminal === true ? 'true' : 'false'};
    const terminal=new Set(${JSON.stringify(SUPPORT_CASE_TERMINAL_STATUSES)});
    const activeSupportStatus=value=>{const status=String(value||'').trim().toUpperCase();return status!==''&&!terminal.has(status)};
    const supportStatusAllowed=value=>includeTerminal ? true : activeSupportStatus(value);
    const limit=${SUPPORT_CASE_HISTORY_LIMIT};
    const pageSize=50;
    const relevant=/reemb|refund|safe[- ]?t|pedido|order|fba|devolu|return|reimbursement|claim|reclama|review|revis/i;
    const viewCase=async caseId=>{
      const response=await fetch('/hill/hillservice/mons-api/ViewCase?caseId='+encodeURIComponent(caseId)+'&timeZone=UTC&pageSize=10',{credentials:'include'});
      if(!response.ok)throw new Error('VIEW_CASE_HTTP_'+response.status);
      return await response.json();
    };
    if(preferred){
      try{
        const detail=await viewCase(preferred);
        const raw=JSON.stringify(detail);
        if(needles.some(n=>raw.includes(n))&&supportStatusAllowed(detail?.viewCaseMetaData?.caseStatus))return JSON.stringify({status:'FOUND',case_id:preferred});
      }catch{return JSON.stringify({status:'UNAVAILABLE',reason:'PREFERRED_CASE_LOOKUP_FAILED'})}
    }
    let total=null;
    let inspected=0;
    let detailFailure=false;
    for(let page=0;inspected<limit;page++){
      const response=await fetch('/hill/hillservice/mons-api/SearchForCases',{
        method:'POST',credentials:'include',headers:{'content-type':'application/json'},
        body:JSON.stringify({page,searchPageSize:50,sortBy:'CreationDate',sortByOrder:'DESC',getCountOnly:false,caseFilters:{caseOwner:'MerchantCases'}})
      });
      if(!response.ok)return JSON.stringify({status:'UNAVAILABLE',reason:'SEARCH_HTTP_'+response.status});
      const search=await response.json();
      if(!Array.isArray(search.caseSearchResultList)||!Number.isFinite(Number(search.totalNumberOfResults))){
        return JSON.stringify({status:'UNAVAILABLE',reason:'SEARCH_RESPONSE_INVALID'});
      }
      const rows=search.caseSearchResultList;
      if(total===null)total=Number(search.totalNumberOfResults);
      for(const item of rows){
        const summary=JSON.stringify(item);
        if(needles.some(n=>summary.includes(n))&&supportStatusAllowed(item.status))return JSON.stringify({status:'FOUND',case_id:String(item.caseId||'')});
      }
      const candidates=rows.filter(item=>supportStatusAllowed(item.status)&&relevant.test(String(item.shortDescription||'')));
      for(const item of candidates){
        try{
          const detail=await viewCase(item.caseId);
          const status=detail?.viewCaseMetaData?.caseStatus||item.status;
          if(supportStatusAllowed(status)&&needles.some(n=>JSON.stringify(detail).includes(n))){
            return JSON.stringify({status:'FOUND',case_id:String(item.caseId||'')});
          }
        }catch{detailFailure=true}
      }
      inspected+=rows.length;
      if(rows.length<pageSize||inspected>=total)break;
    }
    if(detailFailure)return JSON.stringify({status:'UNAVAILABLE',reason:'DETAIL_LOOKUP_FAILED'});
    return JSON.stringify({status:'NOT_FOUND',total:Number(total||0),inspected});
  })()`);
  let parsed;
  try { parsed = JSON.parse(raw || '{}'); } catch { parsed = {}; }
  if (parsed.status === 'FOUND' && /^\d{8,14}$/.test(text(parsed.case_id))) return text(parsed.case_id);
  if (parsed.status === 'NOT_FOUND') return null;
  throw new Error('SUPPORT_CASE_LOOKUP_UNAVAILABLE');
}

async function findSupportCase(cdp, job, options = {}) {
  const known = text(job.case?.support_case_id);
  const lookup = await Cdp.connect();
  try {
    await lookup.navigate(CASE_LOBBY, 4500);
    const auth = await authGate(lookup, 'help-v1', CASE_LOBBY, 4500);
    if (auth) throw new Error('SUPPORT_CASE_LOOKUP_UNAVAILABLE');
    const orderId = text(job.case?.order_id);
    const safeTId = text(job.case?.safe_t_id);
    const quick = text(await lookup.evaluate(`(()=>{const needles=${JSON.stringify([safeTId, orderId].filter(Boolean))};const docs=[document];for(const f of document.querySelectorAll('iframe')){if(f.contentDocument)docs.push(f.contentDocument);const h=f.contentDocument?.querySelector('spl-hill-form');const hd=h?.shadowRoot?.querySelector('iframe')?.contentDocument;if(hd)docs.push(hd)}for(const d of docs){const body=d.body?.innerText||'';if(!needles.some(n=>body.includes(n)))continue;for(const a of d.querySelectorAll('a[href*="view-case"],a[href*="caseID="]')){const row=a.closest('tr,[role=row],div');const t=row?.innerText||'';if(needles.some(n=>t.includes(n))){const m=(a.href||'').match(/[?&]caseID=(\d{8,14})/);if(m)return m[1]}}}return ''})()`));
    return await scanSupportCaseHistory(lookup, job, known || quick, options);
  } catch (error) {
    if (text(error?.message) === 'SUPPORT_CASE_LOOKUP_UNAVAILABLE') throw error;
    throw new Error('SUPPORT_CASE_LOOKUP_UNAVAILABLE', { cause: error });
  } finally {
    lookup.close();
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
  return (await cdp.evaluate(`(()=>{for(const f of document.querySelectorAll('iframe')){const h=f.contentDocument?.querySelector('spl-hill-form');const d=h?.shadowRoot?.querySelector('iframe')?.contentDocument;if(!d)continue;for(const b of d.querySelectorAll('kat-button,button')){const label=(b.getAttribute('label')||b.innerText||'').trim();if(!['Chat now','Conversar agora','Iniciar chat'].includes(label))continue;const button=b.tagName==='KAT-BUTTON'?b.shadowRoot?.querySelector('button'):b;if(button&&!button.disabled){button.click();return label}}}return ''})()`)).toString().trim();
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

async function hillSupportUnavailable(cdp) {
  return (await cdp.evaluate(`(()=>{const phrases=['No support agents are available right now','Support currently unavailable','Nenhum agente de suporte está disponível no momento','Suporte indisponível no momento'];for(const f of document.querySelectorAll('iframe')){const outer=f.contentDocument;const hill=outer?.querySelector('spl-hill-form');const inner=hill?.shadowRoot?.querySelector('iframe')?.contentDocument;const bodies=[outer?.body?.innerText||'',inner?.body?.innerText||''];if(bodies.some(body=>phrases.some(p=>body.includes(p))))return true}return false})()`)) === true;
}

async function currentSupportCaseId(cdp) {
  return text(await cdp.evaluate(`(()=>{const docs=[document];for(const f of document.querySelectorAll('iframe')){if(f.contentDocument)docs.push(f.contentDocument);const h=f.contentDocument?.querySelector('spl-hill-form');const d=h?.shadowRoot?.querySelector('iframe')?.contentDocument;if(d)docs.push(d)}for(const d of docs){for(const a of d.querySelectorAll('a[href*="caseID="]')){const m=(a.href||'').match(/[?&]caseID=(\\d{8,14})/);if(m)return m[1]}const body=d.body?.innerText||'';const m=body.match(/(?:ID do caso|Case ID)[:\\s#-]*(\\d{8,14})/i);if(m)return m[1]}return ''})()`));
}

async function contactSupportAndReadBack(cdp, job) {
  const deadline = Date.now() + 90000;
  while (Date.now() < deadline && !(await hillContactReady(cdp))) await sleep(750);
  if (!(await hillContactReady(cdp))) {
    return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CONTACT_CHANNEL_UNAVAILABLE', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  }
  let channel = await submitHillEmail(cdp, job);
  if (channel !== 'Email') {
    if (await hillChatReady(cdp)) {
      return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CHAT_COMPLETION_NOT_IMPLEMENTED', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
    }
    return bridgeResult('UI_DRIFT', { reason: channel || 'SUPPORT_EMAIL_SEND_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
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
  await sleep(4500);
  let caseId = await currentSupportCaseId(cdp);
  for (let attempt = 0; !caseId && attempt < 5; attempt++) {
    try {
      caseId = text(await findSupportCase(cdp, job, { includeTerminal: true }));
    } catch (error) {
      if (text(error?.message) === 'SUPPORT_CASE_LOOKUP_UNAVAILABLE') {
        return bridgeResult('FAILED', { reason: 'SUPPORT_CASE_LOOKUP_UNAVAILABLE_AFTER_WRITE', submitted: false, retry_safe: false, evidence: { ...(await evidence(cdp, 'help-v1')), support_readback: await supportCaseReadbackSnapshot(cdp) } });
      }
      throw error;
    }
    if (!caseId) await sleep(4000);
  }
  if (!/^\d{8,14}$/.test(caseId)) {
    return bridgeResult('FAILED', { reason: 'SUPPORT_WRITE_WITHOUT_READBACK_ID', submitted: false, retry_safe: false, evidence: { ...(await evidence(cdp, 'help-v1')), support_readback: await supportCaseReadbackSnapshot(cdp) } });
  }
  const reason = channel === 'Email' ? 'SUPPORT_CASE_OPENED_VIA_EMAIL' : `SUPPORT_CASE_OPENED_VIA_${channel.toUpperCase().replace(/\s+/g,'_')}`;
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
  for (let attempt = 0; !caseId && attempt < 6; attempt++) {
    try {
      caseId = text(await findSupportCase(cdp, job, { includeTerminal: true }));
    } catch (error) {
      if (text(error?.message) === 'SUPPORT_CASE_LOOKUP_UNAVAILABLE') {
        return bridgeResult('FAILED', { reason: 'SUPPORT_CASE_LOOKUP_UNAVAILABLE_AFTER_WRITE', submitted: false, retry_safe: false, evidence: { ...(await evidence(cdp, 'help-v1')), support_readback: await supportCaseReadbackSnapshot(cdp) } });
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
    if (await clickFrameTextWhenReady(cdp, 'Contact an associate', 1500)) {
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
async function supportOpen(cdp, job) {
  const snapshotFailure = writeSnapshotFailure(job);
  if (snapshotFailure) return snapshotFailure;
  const supportRoute = supportRouteFor(job);
  if (!supportRoute) return bridgeResult('FAILED', { reason: 'SUPPORT_ROUTE_UNSUPPORTED', retry_safe: false });
  const decisionReason = text(job.payload?.decision?.reason).toUpperCase();
  const physicalStatus = text(job.case?.physical_status).toUpperCase();
  if (decisionReason === 'CLASSIC_FBA_UNPAID_AFTER_FINANCE_RECONCILIATION' && physicalStatus === 'RECEIVED_OK') {
    return bridgeResult('SUPERSEDED', { reason: 'PHYSICAL_RETURN_RECEIVED_BEFORE_SUPPORT_OPEN', retry_safe: false });
  }
  let existing;
  try {
    existing = await findSupportCase(cdp, job);
  } catch (error) {
    if (text(error?.message) === 'SUPPORT_CASE_LOOKUP_UNAVAILABLE') {
      return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CASE_LOOKUP_UNAVAILABLE', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
    }
    throw error;
  }
  if (existing) return bridgeResult('ALREADY_EXISTS', { external_id: existing, retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
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

  const fbaEnglish = await waitFrameHas(cdp, 'FBA Returns Reimbursement', 60000);
  const fbaPortuguese = fbaEnglish ? false : await waitFrameHas(cdp, 'Reembolso de devoluções com FBA - Logística da Amazon', 10000);
  const fbaRouteOpened = fbaEnglish
    ? await clickFrameIncludes(cdp, 'FBA Returns Reimbursement')
    : (fbaPortuguese ? await clickFrameIncludes(cdp, 'Reembolso de devoluções com FBA - Logística da Amazon') : false);
  if (!fbaRouteOpened) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_FBA_CARD_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  const orderInputReady = await cdp.waitFor(`(()=>{for(const f of document.querySelectorAll('iframe')){const h=f.contentDocument?.querySelector('kat-input[placeholder*="112-"]');if(h&&!h.hasAttribute('disabled'))return true}return false})()`, 30000);
  if (!orderInputReady || !(await cdp.setFrameKat('kat-input[placeholder*="112-"]', orderId))) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_ORDER_INPUT_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  if (!(await clickFrameTextWhenReady(cdp, 'Continue', 20000)) && !(await clickFrameTextWhenReady(cdp, 'Continuar', 10000))) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_ORDER_CONTINUE_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  const flowDeadline = Date.now() + 120000;
  let fbaAsinFilled = false;
  let narrativeFilled = false;
  while (Date.now() < flowDeadline) {
    if (await hillContactReady(cdp)) return await contactSupportAndReadBack(cdp, job);
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
    if (await frameHas(cdp, 'Contact an associate')) {
      await clickFrameTextWhenReady(cdp, 'Contact an associate', 5000);
      await sleep(750);
      continue;
    }
    if (await frameHas(cdp, 'Entre em contato com um associado')) {
      await clickFrameTextWhenReady(cdp, 'Entre em contato com um associado', 5000);
      await sleep(750);
      continue;
    }
    const asinRequired = await cdp.evaluate(`(()=>{for(const f of document.querySelectorAll("iframe")){const h=f.contentDocument?.querySelector("kat-input[placeholder=\"Inserir ASIN\"]");if(h&&!h.hasAttribute("disabled"))return true}return false})()`);
    if (asinRequired && !fbaAsinFilled) {
      if (!resolvedAsin || !(await cdp.setFrameKat('kat-input[placeholder="Inserir ASIN"]', resolvedAsin))) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_ASIN_INPUT_MISSING', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
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
  return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CHAT_CHANNEL_UNAVAILABLE', retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
}
async function supportUpdate(cdp, job) {
  const snapshotFailure = writeSnapshotFailure(job);
  if (snapshotFailure) return snapshotFailure;
  const caseId = text(job.case?.support_case_id);
  if (!/^\d{8,14}$/.test(caseId)) return supportOpen(cdp, job);
  await cdp.navigate(`https://sellercentral.amazon.com.br/cu/case-dashboard/view-case?caseID=${encodeURIComponent(caseId)}`, 5000);
  const auth = await authGate(cdp, 'help-v1', `https://sellercentral.amazon.com.br/cu/case-dashboard/view-case?caseID=${encodeURIComponent(caseId)}`, 5000);
  if (auth) return auth;
  const narrative = narrativeFor(job, 9000);
  const already = await cdp.evaluate(`(document.body?.innerText||'').includes(${JSON.stringify(narrative.slice(0, 240))})`);
  if (already) return bridgeResult('ALREADY_EXISTS', { external_id: caseId, retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  const selector = await cdp.evaluate(`(()=>{for(const s of ['kat-textarea','textarea']){const h=document.querySelector(s);if(h&&!h.hasAttribute('disabled'))return s}return ''})()`);
  if (!text(selector)) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_REPLY_FIELD_MISSING', evidence: await evidence(cdp, 'help-v1') });
  if (selector === 'kat-textarea') {
    if (!(await cdp.setKat('kat-textarea', narrative))) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_REPLY_FIELD_NOT_WRITABLE', evidence: await evidence(cdp, 'help-v1') });
  } else {
    const ok = await cdp.evaluate(`(()=>{const i=document.querySelector('textarea');if(!i)return false;const setter=Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype,'value').set;setter.call(i,${JSON.stringify(narrative)});i.dispatchEvent(new InputEvent('input',{bubbles:true,inputType:'insertText',data:${JSON.stringify(narrative)}}));i.dispatchEvent(new Event('change',{bubbles:true}));return i.value===${JSON.stringify(narrative)}})()`);
    if (!ok) return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_NATIVE_REPLY_NOT_WRITABLE', evidence: await evidence(cdp, 'help-v1') });
  }
  const sent = await cdp.evaluate(`(()=>{const labels=['Enviar','Enviar mensagem','Responder'];for(const h of document.querySelectorAll('kat-button,button')){const label=(h.getAttribute('label')||h.innerText||'').trim();if(!labels.includes(label))continue;const b=h.tagName==='KAT-BUTTON'?h.shadowRoot?.querySelector('button'):h;if(b&&!b.disabled){b.click();return label}}return ''})()`);
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
    cdp.close();
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
    while (true) {
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
