#!/usr/bin/env node
import fs from 'node:fs';
import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';

const ENDPOINT = process.env.SELLER_CENTRAL_BRIDGE_ENDPOINT || 'https://shopvivaliz.com.br/api/amazon-returns/bridge.php';
const TOKEN_FILE = process.env.SELLER_CENTRAL_BRIDGE_TOKEN_FILE || 'C:\\ShopVivaliz\\amazon-returns-bridge\\bridge.token';
const CDP_BASE = process.env.SELLER_CENTRAL_CDP_URL || 'http://127.0.0.1:9225';
const PROFILE = process.env.SELLER_CENTRAL_PROFILE || 'C:\\ShopVivaliz\\amazon-returns-bridge\\profile';
const OPERA = process.env.SELLER_CENTRAL_OPERA || 'C:\\Users\\FRED\\AppData\\Local\\Programs\\Opera developer\\opera.exe';
const POLL_MS = Math.max(10000, Number(process.env.SELLER_CENTRAL_BRIDGE_POLL_MS || 30000));
const SAFE_T_BASE = 'https://sellercentral.amazon.com.br/safet-claims';
const HELP_URL = 'https://sellercentral.amazon.com.br/help/center?redirectSource=Hill';
const CASE_LOBBY = 'https://sellercentral.amazon.com.br/cu/case-lobby';

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const text = value => String(value ?? '').replace(/\s+/g, ' ').trim();
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
  if (!fs.existsSync(OPERA) || !fs.existsSync(PROFILE)) throw new Error('headless Opera profile unavailable');
  const child = spawn(OPERA, [
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
  throw new Error('headless Opera did not expose CDP');
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
  }
}

function authenticationState(state) {
  const combined = `${state.href || ''}\n${state.title || ''}\n${state.text || ''}`.toLowerCase();
  if (combined.includes('/signin') || combined.includes('iniciar sessão') || combined.includes('sign in')) return 'AUTH_REQUIRED';
  if (combined.includes('captcha') || combined.includes('digite os caracteres')) return 'HUMAN_CHALLENGE';
  return 'OK';
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

async function evidence(cdp, uiContract) {
  const state = await cdp.pageState(18000);
  const safe = {
    ui_contract: uiContract,
    current_url: state.href || '',
    title: state.title || '',
    body_sha256: sha(state.text || ''),
  };
  return { ...safe, snapshot_sha256: sha(JSON.stringify(safe)) };
}

async function authGate(cdp, uiContract) {
  const state = await cdp.pageState();
  const auth = authenticationState(state);
  if (auth === 'AUTH_REQUIRED') return bridgeResult('AUTH_REQUIRED', { reason: 'SESSION_NOT_AUTHENTICATED', evidence: await evidence(cdp, uiContract) });
  if (auth === 'HUMAN_CHALLENGE') return bridgeResult('HUMAN_CHALLENGE', { reason: 'CAPTCHA_PRESENT', evidence: await evidence(cdp, uiContract) });
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

function narrativeFor(job, max = 1000) {
  const supplied = text(job.payload?.narrative);
  if (supplied) return supplied.slice(0, max);
  const order = text(job.case?.order_id);
  const safeT = text(job.case?.safe_t_id);
  const decision = text(job.payload?.decision?.reason);
  if (job.action === 'SELLER_SUPPORT_OPEN' || job.action === 'SELLER_SUPPORT_UPDATE') {
    return (`SAFE-T ${safeT}, pedido ${order}. A devolução permanece não recebida fisicamente pelo vendedor. `
      + `A nova negativa repetiu a justificativa sem responder aos fatos e às evidências apresentados. `
      + `Solicito revisão manual por equipe especializada. Se a Amazon considera que houve devolução, `
      + `favor informar data, transportadora, rastreio, endereço de entrega e comprovante de entrega. ${decision}`).slice(0, max);
  }
  if (job.action === 'SAFE_T_APPEAL') {
    return (`Pedido ${order}, SAFE-T ${safeT}. O produto não foi recebido fisicamente pelo vendedor. `
      + `Solicito reavaliação da decisão com análise do fluxo de devolução, rastreio e eventual comprovante de entrega. ${decision}`).slice(0, max);
  }
  return (`Pedido ${order}. A Amazon efetuou o reembolso ao comprador e a devolução não foi recebida pelo vendedor. `
    + 'Solicito o ressarcimento correspondente, com validação do fluxo de devolução e do débito ao vendedor.').slice(0, max);
}

async function safeTSubmit(cdp, job) {
  await cdp.navigate(`${SAFE_T_BASE}/create-v2?ref_=ag_sfdcf_cont_safet`, 5000);
  const auth = await authGate(cdp, 'safet-v1');
  if (auth) return auth;
  const orderId = text(job.case?.order_id);
  if (!/^\d{3}-\d{7}-\d{7}$/.test(orderId)) return bridgeResult('FAILED', { reason: 'INVALID_ORDER_ID' });
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
  const evidencePaths = Array.isArray(job.payload?.evidence_paths) ? job.payload.evidence_paths.filter(path => typeof path === 'string' && fs.existsSync(path)) : [];
  if (needsEvidence && evidencePaths.length === 0) {
    return bridgeResult('FAILED', { reason: 'DISCREPANCY_EVIDENCE_REQUIRED', retry_safe: false, evidence: await evidence(cdp, 'safet-v1') });
  }
  if (evidencePaths.length > 0) {
    return bridgeResult('FAILED', { reason: 'FILE_UPLOAD_BRIDGE_NOT_PROVISIONED', retry_safe: false, evidence: await evidence(cdp, 'safet-v1') });
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
    evidence: await evidence(cdp, 'safet-v1'),
  });
}

async function safeTAppeal(cdp, job) {
  const safeTId = text(job.case?.safe_t_id);
  if (!/^\d{5}-\d{5}-\d{7}$/.test(safeTId)) return bridgeResult('FAILED', { reason: 'SAFE_T_ID_REQUIRED' });
  await cdp.navigate(`${SAFE_T_BASE}/claim/${encodeURIComponent(safeTId)}`, 5000);
  const auth = await authGate(cdp, 'safet-v1');
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
    evidence: await evidence(cdp, 'safet-v1'),
  });
}

async function findSupportCase(cdp, job) {
  const known = text(job.case?.support_case_id);
  if (/^\d{8,14}$/.test(known)) return known;
  await cdp.navigate(CASE_LOBBY, 4500);
  const auth = await authGate(cdp, 'help-v1');
  if (auth) return null;
  const orderId = text(job.case?.order_id);
  const safeTId = text(job.case?.safe_t_id);
  const result = await cdp.evaluate(`(()=>{const body=document.body?.innerText||'';const needles=${JSON.stringify([safeTId, orderId].filter(Boolean))};if(!needles.some(n=>body.includes(n)))return '';const links=[...document.querySelectorAll('a[href*="view-case"]')];for(const a of links){const row=a.closest('tr,[role=row],div');const t=row?.innerText||'';if(needles.some(n=>t.includes(n))){const m=(a.href||'').match(/[?&]caseID=(\d{8,14})/);if(m)return m[1]}}return ''})()`);
  return text(result) || null;
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

async function supportOpen(cdp, job) {
  const existing = await findSupportCase(cdp, job);
  if (existing) return bridgeResult('ALREADY_EXISTS', { external_id: existing, retry_safe: true, evidence: await evidence(cdp, 'help-v1') });
  const orderId = text(job.case?.order_id);
  if (!/^\d{3}-\d{7}-\d{7}$/.test(orderId)) return bridgeResult('FAILED', { reason: 'SUPPORT_ORDER_ID_REQUIRED' });
  await cdp.navigate(HELP_URL, 6000);
  const auth = await authGate(cdp, 'help-v1');
  if (auth) return auth;
  if (!(await clickFrameIncludes(cdp, 'Reembolso de devoluções com FBA - Logística da Amazon'))) {
    return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_FBA_CARD_MISSING', evidence: await evidence(cdp, 'help-v1') });
  }
  await sleep(3500);
  if (!(await cdp.setFrameKat('kat-input[placeholder*="112-"]', orderId))) {
    return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_ORDER_INPUT_MISSING', evidence: await evidence(cdp, 'help-v1') });
  }
  if (!(await cdp.clickFrameText('Continuar'))) {
    return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_ORDER_CONTINUE_MISSING', evidence: await evidence(cdp, 'help-v1') });
  }
  await sleep(6500);
  if (await frameHas(cdp, 'Solicitar reembolso para um pedido')) {
    await cdp.clickFrameText('Solicitar reembolso para um pedido');
    await sleep(6500);
  }
  if (await frameHas(cdp, 'Entre em contato com um associado')) {
    await cdp.clickFrameText('Entre em contato com um associado');
    await sleep(4500);
  }
  const narrative = narrativeFor(job, 9000);
  const textareaReady = await cdp.waitFor(`(()=>{for(const f of document.querySelectorAll('iframe')){const h=f.contentDocument?.querySelector('kat-textarea.meld-text-area');if(h&&!h.hasAttribute('disabled'))return true}return false})()`, 20000);
  if (!textareaReady) {
    return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CONTACT_TEXTAREA_UNAVAILABLE', evidence: await evidence(cdp, 'help-v1') });
  }
  if (!(await cdp.setFrameKat('kat-textarea.meld-text-area', narrative))) {
    return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CONTACT_TEXTAREA_NOT_WRITABLE', evidence: await evidence(cdp, 'help-v1') });
  }
  if (!(await cdp.clickFrameText('Continuar'))) {
    return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_CONTACT_CONTINUE_MISSING', evidence: await evidence(cdp, 'help-v1') });
  }
  await sleep(6500);
  if (await frameHas(cdp, 'E-mail')) {
    await clickFrameIncludes(cdp, 'E-mail');
    await sleep(2000);
  }
  const finalAction = await cdp.evaluate(`(()=>{const labels=['Enviar','Enviar mensagem','Enviar caso','Criar caso','Enviar solicitação','Abrir caso'];for(const f of document.querySelectorAll('iframe')){const d=f.contentDocument;if(!d)continue;for(const h of d.querySelectorAll('kat-button,button')){const label=(h.getAttribute('label')||h.innerText||'').trim();if(!labels.includes(label))continue;const b=h.tagName==='KAT-BUTTON'?h.shadowRoot?.querySelector('button'):h;if(b&&!b.disabled){b.click();return label}}}return ''})()`);
  if (!text(finalAction)) {
    return bridgeResult('UI_DRIFT', { reason: 'SUPPORT_FINAL_SUBMIT_MISSING', retry_safe: false, evidence: await evidence(cdp, 'help-v1') });
  }
  await sleep(8000);
  const caseId = await cdp.evaluate(`(()=>{const docs=[document,...[...document.querySelectorAll('iframe')].map(f=>f.contentDocument).filter(Boolean)];for(const d of docs){for(const a of d.querySelectorAll('a[href*="caseID="]')){const m=(a.href||'').match(/[?&]caseID=(\d{8,14})/);if(m)return m[1]}const body=d.body?.innerText||'';const m=body.match(/(?:ID do caso|Case ID)[:\s#-]*(\d{8,14})/i);if(m)return m[1]}return ''})()`);
  if (!text(caseId)) {
    return bridgeResult('FAILED', { reason: 'SUPPORT_WRITE_WITHOUT_READBACK_ID', submitted: false, retry_safe: false, evidence: await evidence(cdp, 'help-v1') });
  }
  return bridgeResult('ACCEPTED', {
    submitted: true,
    external_id: text(caseId),
    retry_safe: true,
    reason: `SUPPORT_CASE_SUBMITTED_VIA_${text(finalAction)}`,
    evidence: await evidence(cdp, 'help-v1'),
  });
}

async function supportUpdate(cdp, job) {
  const caseId = text(job.case?.support_case_id);
  if (!/^\d{8,14}$/.test(caseId)) return supportOpen(cdp, job);
  await cdp.navigate(`https://sellercentral.amazon.com.br/cu/case-dashboard/view-case?caseID=${encodeURIComponent(caseId)}`, 5000);
  const auth = await authGate(cdp, 'help-v1');
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
  };
  process.stdout.write(`${JSON.stringify(safe)}\n`);
}

async function runOnce() {
  const pulled = await bridge('pull', { worker_id: 'fred-win-seller-central' });
  if (pulled.status === 'NO_JOB') return false;
  if (pulled.status !== 'JOB' || !pulled.job) throw new Error(`unexpected pull status ${text(pulled.status)}`);
  const job = pulled.job;
  log('job_received', job);
  let result;
  try {
    result = await executeJob(job);
  } catch (error) {
    result = bridgeResult('FAILED', { reason: `UNHANDLED_${error?.name || 'ERROR'}`, retry_safe: false });
  }
  await bridge('result', { job_id: job.job_id, idempotency_key: job.idempotency_key, result });
  log('job_result', { ...job, ...result });
  return true;
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
    const heartbeat = await bridge('heartbeat', { worker_id: 'fred-win-seller-central' });
    process.stdout.write(`${JSON.stringify(heartbeat)}\n`);
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
