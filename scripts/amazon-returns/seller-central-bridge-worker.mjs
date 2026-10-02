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
const SAFE_T_APPEAL_FIELD_WAIT_MS = 15000;
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

  async fillDeepSupportTextarea(value) {
    const expected=String(value??'');
    if(expected==='')return false;
    const evaluated=await this.send('Runtime.evaluate',{
      expression:`(()=>{const roots=[];const scan=root=>{if(!root||roots.includes(root))return;roots.push(root);for(const frame of root.querySelectorAll?.('iframe')||[]){try{scan(frame.contentDocument)}catch{}}for(const e of root.querySelectorAll?.('*')||[]){if(e.shadowRoot)scan(e.shadowRoot)}};scan(document);const usable=h=>{if(!h||h.disabled===true||h.hasAttribute?.('disabled')||h.getAttribute?.('aria-disabled')==='true')return false;const placeholder=(h.getAttribute?.('placeholder')||h.getAttribute?.('aria-label')||'').toLowerCase();return !placeholder.includes('feedback')};for(const root of roots){for(const host of root.querySelectorAll?.('kat-textarea')||[]){if(!usable(host))continue;const textarea=host.shadowRoot?.querySelector('textarea');if(usable(textarea))return textarea}for(const textarea of root.querySelectorAll?.('textarea')||[]){if(usable(textarea))return textarea}}return null})()`,
      returnByValue:false,
      awaitPromise:true,
    });
    const objectId=evaluated?.result?.objectId;
    if(!objectId)return false;
    try{
      const result=await this.send('Runtime.callFunctionOn',{
        objectId,
        functionDeclaration:`function(expected){if(!this||this.tagName!=='TEXTAREA'||this.disabled)return false;const setter=Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype,'value')?.set;if(typeof setter!=='function')return false;setter.call(this,expected);this.dispatchEvent(new InputEvent('input',{bubbles:true,composed:true,inputType:'insertText',data:expected}));this.dispatchEvent(new Event('change',{bubbles:true,composed:true}));return this.value===expected}`,
        arguments:[{value:expected}],
        returnByValue:true,
        awaitPromise:true,
      });
      return result?.result?.value===true;
    }catch{return false}finally{await this.send('Runtime.releaseObject',{objectId}).catch(()=>{});}
  }

  async clickButtonTrustedByText(labels) {
    const wanted=[...new Set((Array.isArray(labels)?labels:[labels]).map(v=>String(v??'').trim()).filter(Boolean))];
    if(wanted.length===0)return '';
    const normalizedWanted=wanted.map(value=>value.toLowerCase());
    const frameTree=await this.send('Page.getFrameTree').catch(()=>null);
    const frameIds=[];
    const visitFrame=node=>{
      const frameId=node?.frame?.id;
      if(frameId)frameIds.push(frameId);
      for(const child of node?.childFrames||[])visitFrame(child);
    };
    visitFrame(frameTree?.frameTree);
    const visible=[];
    for(const frameId of frameIds){
      const tree=await this.send('Accessibility.getFullAXTree',{frameId}).catch(()=>null);
      for(const node of tree?.nodes||[]){
        if(node?.ignored===true || !node?.backendDOMNodeId)continue;
        const role=String(node?.role?.value||'').trim().toLowerCase();
        if(!['button','link'].includes(role))continue;
        const name=String(node?.name?.value||'').trim();
        if(!normalizedWanted.includes(name.toLowerCase()))continue;
        const disabled=(node?.properties||[]).some(property=>property?.name==='disabled' && property?.value?.value===true);
        if(disabled)continue;
        const resolved=await this.send('DOM.resolveNode',{backendNodeId:node.backendDOMNodeId}).catch(()=>null);
        const objectId=resolved?.object?.objectId;
        if(!objectId)continue;
        const usable=await this.send('Runtime.callFunctionOn',{
          objectId,
          functionDeclaration:"function(){const style=getComputedStyle(this),rect=this.getBoundingClientRect();return !this.disabled&&this.getAttribute?.('aria-disabled')!=='true'&&style.display!=='none'&&style.visibility!=='hidden'&&Number(style.opacity||1)>0&&rect.width>0&&rect.height>0&&this.getClientRects().length>0}",
          returnByValue:true,
          awaitPromise:true,
        }).catch(()=>null);
        if(usable?.result?.value===true)visible.push({objectId,name});
        else await this.send('Runtime.releaseObject',{objectId}).catch(()=>{});
      }
    }
    if(visible.length===1){
      const {objectId}=visible[0];
      try{
        await this.send('DOM.scrollIntoViewIfNeeded',{objectId});await sleep(120);
        const box=await this.send('DOM.getBoxModel',{objectId});const q=box?.model?.content;
        if(!Array.isArray(q)||q.length<8)return '';
        const x=(Number(q[0])+Number(q[2])+Number(q[4])+Number(q[6]))/4;
        const y=(Number(q[1])+Number(q[3])+Number(q[5])+Number(q[7]))/4;
        if(!Number.isFinite(x)||!Number.isFinite(y))return '';
        await this.send('Input.dispatchMouseEvent',{type:'mouseMoved',x,y,button:'none'});
        await this.send('Input.dispatchMouseEvent',{type:'mousePressed',x,y,button:'left',clickCount:1});
        await this.send('Input.dispatchMouseEvent',{type:'mouseReleased',x,y,button:'left',clickCount:1});
        return 'CLICKED';
      }catch{return ''}finally{
        for(const item of visible)await this.send('Runtime.releaseObject',{objectId:item.objectId}).catch(()=>{});
      }
    }
    for(const item of visible)await this.send('Runtime.releaseObject',{objectId:item.objectId}).catch(()=>{});
    return '';
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
  return await cdp.evaluate(`(()=>{const out={buttons:[],inputs:[]};const roots=[];const scan=root=>{if(!root||roots.includes(root))return;roots.push(root);for(const frame of root.querySelectorAll?.('iframe')||[]){try{scan(frame.contentDocument)}catch{}}for(const e of root.querySelectorAll?.('*')||[]){if(e.shadowRoot)scan(e.shadowRoot)}};scan(document);const buttonSelector='kat-button,button,kat-link,[role="button"],input[type="submit"],input[type="button"]';for(const root of roots){for(const h of root.querySelectorAll?.(buttonSelector)||[]){const label=(h.getAttribute?.('label')||h.getAttribute?.('aria-label')||h.getAttribute?.('title')||h.getAttribute?.('value')||h.innerText||'').trim();if(label&&out.buttons.length<32&&!out.buttons.includes(label.slice(0,80)))out.buttons.push(label.slice(0,80))}for(const h of root.querySelectorAll?.('kat-input,input,kat-textarea,textarea')||[]){const placeholder=(h.getAttribute?.('placeholder')||h.getAttribute?.('aria-label')||'').trim();if(placeholder&&out.inputs.length<20&&!out.inputs.includes(placeholder.slice(0,80)))out.inputs.push(placeholder.slice(0,80))}}return out})()`);
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

async function selectSafeTSubreason(cdp, value) {
  const hints=['subcategoria','subcategory','sub category','subreason'];
  const expected=String(value||'').trim();
  if (!expected) return false;
  for (let attempt=0; attempt<12; attempt++) {
    const directExact=(await cdp.evaluate(`(()=>{const hints=${JSON.stringify(hints)};const expected=${JSON.stringify(expected)};const normalize=v=>String(v||'').normalize('NFD').replace(/[\\u0300-\\u036f]/g,'').trim().toLowerCase();const isInteractive=element=>{try{if(!element||element.hidden||element.getAttribute('aria-hidden')==='true'||element.hasAttribute('disabled'))return false;const style=getComputedStyle(element);if(style.display==='none'||style.visibility==='hidden'||Number(style.opacity||1)<=0)return false;const rect=element.getBoundingClientRect();return rect.width>0&&rect.height>0&&element.getClientRects().length>0}catch{return false}};const docs=[];const visit=d=>{if(!d||docs.includes(d))return;docs.push(d);for(const frame of d.querySelectorAll('iframe')){try{visit(frame.contentDocument)}catch{}}};visit(document);const roots=[];const addRoot=r=>{if(!r||roots.includes(r))return;roots.push(r);for(const el of r.querySelectorAll?.('*')||[]){if(el.shadowRoot)addRoot(el.shadowRoot)}};for(const d of docs)addRoot(d);const semantic=[];for(const root of roots){for(const host of root.querySelectorAll?.('kat-dropdown')||[]){const meta=normalize([host.getAttribute('placeholder'),host.getAttribute('label'),host.getAttribute('aria-label'),host.getAttribute('name'),host.id,host.className].filter(Boolean).join(' '));if(hints.some(h=>meta.includes(h)))semantic.push(host)}}const interactiveDropdowns=semantic.filter(isInteractive);const options=[];const seen=new Set();for(const root of roots){for(const option of root.querySelectorAll?.('kat-option,option,[role="option"]')||[]){if(seen.has(option))continue;seen.add(option);options.push(option)}}const valueOf=option=>String(option.getAttribute?.('value')??option.value??option.dataset?.value??'').trim();const exact=options.filter(option=>valueOf(option)===expected);if(exact.length>1)return -1;const interactiveExact=exact.filter(isInteractive);if(interactiveDropdowns.length!==1||interactiveExact.length!==1)return 0;interactiveExact[0].click();return 1})()`));
    if (directExact===1) return true;
    if (directExact===-1) return false;
    const trustedOwnerPoint=await cdp.evaluate(`(()=>{const expected=${JSON.stringify(expected)};const roots=[];const add=r=>{if(!r||roots.includes(r))return;roots.push(r);for(const e of r.querySelectorAll?.('*')||[])if(e.shadowRoot)add(e.shadowRoot)};add(document);const options=roots.flatMap(r=>[...(r.querySelectorAll?.('kat-option,option,[role="option"]')||[])]);const exact=options.filter(o=>String(o.getAttribute?.('value')??o.value??o.dataset?.value??'').trim()===expected);if(exact.length!==1)return null;let owner=exact[0].closest?.('kat-dropdown');if(!owner){let n=exact[0];for(let i=0;i<8;i++){const h=n?.getRootNode?.()?.host;if(!h)break;if(h.tagName==='KAT-DROPDOWN'){owner=h;break}n=h}}if(!owner||owner.hidden||owner.hasAttribute('disabled'))return null;owner.scrollIntoView({block:'center'});const r=owner.getBoundingClientRect();if(r.width<=0||r.height<=0)return null;return {x:r.left+r.width/2,y:r.top+r.height/2}})()`);
    if(trustedOwnerPoint&&Number.isFinite(trustedOwnerPoint.x)&&Number.isFinite(trustedOwnerPoint.y)){
      await cdp.send('Input.dispatchMouseEvent',{type:'mouseMoved',x:trustedOwnerPoint.x,y:trustedOwnerPoint.y,button:'none'});
      await cdp.send('Input.dispatchMouseEvent',{type:'mousePressed',x:trustedOwnerPoint.x,y:trustedOwnerPoint.y,button:'left',clickCount:1});
      await cdp.send('Input.dispatchMouseEvent',{type:'mouseReleased',x:trustedOwnerPoint.x,y:trustedOwnerPoint.y,button:'left',clickCount:1});
      await sleep(300);
      const trustedOptionPoint=await cdp.evaluate(`(()=>{const expected=${JSON.stringify(expected)};const roots=[];const add=r=>{if(!r||roots.includes(r))return;roots.push(r);for(const e of r.querySelectorAll?.('*')||[])if(e.shadowRoot)add(e.shadowRoot)};add(document);const options=roots.flatMap(r=>[...(r.querySelectorAll?.('kat-option,option,[role="option"]')||[])]);const exact=options.filter(o=>String(o.getAttribute?.('value')??o.value??o.dataset?.value??'').trim()===expected);if(exact.length!==1)return null;const o=exact[0];const s=getComputedStyle(o),r=o.getBoundingClientRect();if(o.hidden||o.getAttribute('aria-hidden')==='true'||s.display==='none'||s.visibility==='hidden'||r.width<=0||r.height<=0)return null;return {x:r.left+r.width/2,y:r.top+r.height/2}})()`);
      if(trustedOptionPoint&&Number.isFinite(trustedOptionPoint.x)&&Number.isFinite(trustedOptionPoint.y)){
        await cdp.send('Input.dispatchMouseEvent',{type:'mouseMoved',x:trustedOptionPoint.x,y:trustedOptionPoint.y,button:'none'});
        await cdp.send('Input.dispatchMouseEvent',{type:'mousePressed',x:trustedOptionPoint.x,y:trustedOptionPoint.y,button:'left',clickCount:1});
        await cdp.send('Input.dispatchMouseEvent',{type:'mouseReleased',x:trustedOptionPoint.x,y:trustedOptionPoint.y,button:'left',clickCount:1});
        await sleep(200);
        const trustedSelected=(await cdp.evaluate(`(()=>{const expected=${JSON.stringify(expected)};const roots=[];const add=r=>{if(!r||roots.includes(r))return;roots.push(r);for(const e of r.querySelectorAll?.('*')||[])if(e.shadowRoot)add(e.shadowRoot)};add(document);const options=roots.flatMap(r=>[...(r.querySelectorAll?.('kat-option,option,[role="option"]')||[])]);const exact=options.filter(o=>String(o.getAttribute?.('value')??o.value??o.dataset?.value??'').trim()===expected);if(exact.length!==1)return false;let owner=exact[0].closest?.('kat-dropdown');if(!owner){let n=exact[0];for(let i=0;i<8;i++){const h=n?.getRootNode?.()?.host;if(!h)break;if(h.tagName==='KAT-DROPDOWN'){owner=h;break}n=h}}const v=String(owner?.value??owner?.getAttribute?.('value')??'').trim();return v===expected||exact[0].selected===true||exact[0].hasAttribute?.('selected')===true})()`))===true;
        if(trustedSelected)return true;
      }
    }
    const ownerOpened=await cdp.evaluate(`(()=>{const expected=${JSON.stringify(expected)};const isInteractive=element=>{try{if(!element||element.hidden||element.getAttribute('aria-hidden')==='true'||element.hasAttribute('disabled'))return false;const style=getComputedStyle(element);if(style.display==='none'||style.visibility==='hidden'||Number(style.opacity||1)<=0)return false;const rect=element.getBoundingClientRect();return rect.width>0&&rect.height>0&&element.getClientRects().length>0}catch{return false}};const docs=[];const visit=d=>{if(!d||docs.includes(d))return;docs.push(d);for(const frame of d.querySelectorAll('iframe')){try{visit(frame.contentDocument)}catch{}}};visit(document);const roots=[];const addRoot=r=>{if(!r||roots.includes(r))return;roots.push(r);for(const el of r.querySelectorAll?.('*')||[]){if(el.shadowRoot)addRoot(el.shadowRoot)}};for(const d of docs)addRoot(d);const options=[];const seen=new Set();for(const root of roots){for(const option of root.querySelectorAll?.('kat-option,option,[role="option"]')||[]){if(seen.has(option))continue;seen.add(option);options.push(option)}}const valueOf=option=>String(option.getAttribute?.('value')??option.value??option.dataset?.value??'').trim();const exact=options.filter(option=>valueOf(option)===expected);if(exact.length!==1)return 0;const ownerDropdown=option=>{const lightOwner=option.closest?.('kat-dropdown');if(lightOwner)return lightOwner;let node=option;for(let depth=0;depth<8;depth++){const root=node?.getRootNode?.();const host=root?.host;if(!host)return null;if(host.tagName==='KAT-DROPDOWN')return host;node=host}return null};const owner=ownerDropdown(exact[0]);if(!owner||!isInteractive(owner))return 0;const ownerTrigger=owner.shadowRoot?.querySelector('button,[role=button],input')||owner;if(!isInteractive(ownerTrigger))return 0;ownerTrigger.click();return 1})()`);
    if(ownerOpened===1){
      await sleep(300);
      const ownerSelected=(await cdp.evaluate(`(()=>{const expected=${JSON.stringify(expected)};const isInteractive=element=>{try{if(!element||element.hidden||element.getAttribute('aria-hidden')==='true'||element.hasAttribute('disabled'))return false;const style=getComputedStyle(element);if(style.display==='none'||style.visibility==='hidden'||Number(style.opacity||1)<=0)return false;const rect=element.getBoundingClientRect();return rect.width>0&&rect.height>0&&element.getClientRects().length>0}catch{return false}};const docs=[];const visit=d=>{if(!d||docs.includes(d))return;docs.push(d);for(const frame of d.querySelectorAll('iframe')){try{visit(frame.contentDocument)}catch{}}};visit(document);const roots=[];const addRoot=r=>{if(!r||roots.includes(r))return;roots.push(r);for(const el of r.querySelectorAll?.('*')||[]){if(el.shadowRoot)addRoot(el.shadowRoot)}};for(const d of docs)addRoot(d);const options=[];const seen=new Set();for(const root of roots){for(const option of root.querySelectorAll?.('kat-option,option,[role="option"]')||[]){if(seen.has(option))continue;seen.add(option);options.push(option)}}const valueOf=option=>String(option.getAttribute?.('value')??option.value??option.dataset?.value??'').trim();const exact=options.filter(option=>valueOf(option)===expected);if(exact.length!==1)return false;const ownerDropdown=option=>{const lightOwner=option.closest?.('kat-dropdown');if(lightOwner)return lightOwner;let node=option;for(let depth=0;depth<8;depth++){const root=node?.getRootNode?.();const host=root?.host;if(!host)return null;if(host.tagName==='KAT-DROPDOWN')return host;node=host}return null};const owner=ownerDropdown(exact[0]);if(!owner||!isInteractive(owner)||!isInteractive(exact[0]))return false;exact[0].click();return true})()`))===true;
      if(ownerSelected)return true;
    }
    const opened=(await cdp.evaluate(`(()=>{const hints=${JSON.stringify(hints)};const normalize=v=>String(v||'').normalize('NFD').replace(/[\\u0300-\\u036f]/g,'').trim().toLowerCase();const isInteractive=element=>{try{if(!element||element.hidden||element.getAttribute('aria-hidden')==='true'||element.hasAttribute('disabled'))return false;const style=getComputedStyle(element);if(style.display==='none'||style.visibility==='hidden'||Number(style.opacity||1)<=0)return false;const rect=element.getBoundingClientRect();return rect.width>0&&rect.height>0&&element.getClientRects().length>0}catch{return false}};const docs=[];const visit=d=>{if(!d||docs.includes(d))return;docs.push(d);for(const frame of d.querySelectorAll('iframe')){try{visit(frame.contentDocument)}catch{}}};visit(document);const roots=[];const addRoot=r=>{if(!r||roots.includes(r))return;roots.push(r);for(const el of r.querySelectorAll?.('*')||[]){if(el.shadowRoot)addRoot(el.shadowRoot)}};for(const d of docs)addRoot(d);const matches=[];for(const root of roots){for(const host of root.querySelectorAll?.('kat-dropdown')||[]){const meta=normalize([host.getAttribute('placeholder'),host.getAttribute('label'),host.getAttribute('aria-label'),host.getAttribute('name'),host.id,host.className].filter(Boolean).join(' '));if(!hints.some(h=>meta.includes(h))||!isInteractive(host))continue;matches.push(host)}}if(matches.length!==1)return false;const host=matches[0];const trigger=host.shadowRoot?.querySelector('button,[role=button],input')||host;if(!isInteractive(trigger))return false;trigger.click();return true})()`))===true;
    if (!opened) {
      if (attempt+1<12) await sleep(500);
      continue;
    }
    await sleep(300);
    const selected=(await cdp.evaluate(`(()=>{const hints=${JSON.stringify(hints)};const expected=${JSON.stringify(expected)};const normalize=v=>String(v||'').normalize('NFD').replace(/[\\u0300-\\u036f]/g,'').trim().toLowerCase();const isInteractive=element=>{try{if(!element||element.hidden||element.getAttribute('aria-hidden')==='true'||element.hasAttribute('disabled'))return false;const style=getComputedStyle(element);if(style.display==='none'||style.visibility==='hidden'||Number(style.opacity||1)<=0)return false;const rect=element.getBoundingClientRect();return rect.width>0&&rect.height>0&&element.getClientRects().length>0}catch{return false}};const docs=[];const visit=d=>{if(!d||docs.includes(d))return;docs.push(d);for(const frame of d.querySelectorAll('iframe')){try{visit(frame.contentDocument)}catch{}}};visit(document);const roots=[];const addRoot=r=>{if(!r||roots.includes(r))return;roots.push(r);for(const el of r.querySelectorAll?.('*')||[]){if(el.shadowRoot)addRoot(el.shadowRoot)}};for(const d of docs)addRoot(d);const dropdowns=[];for(const root of roots){for(const host of root.querySelectorAll?.('kat-dropdown')||[]){const meta=normalize([host.getAttribute('placeholder'),host.getAttribute('label'),host.getAttribute('aria-label'),host.getAttribute('name'),host.id,host.className].filter(Boolean).join(' '));if(hints.some(h=>meta.includes(h))&&isInteractive(host))dropdowns.push(host)}}if(dropdowns.length!==1)return false;const options=[];const seen=new Set();for(const root of roots){for(const option of root.querySelectorAll?.('kat-option,option,[role="option"]')||[]){if(seen.has(option))continue;seen.add(option);options.push(option)}}const optionValue=option=>String(option.getAttribute?.('value')??option.value??option.dataset?.value??'').trim();const exact=options.filter(option=>optionValue(option)===expected);if(exact.length!==1)return false;if(!isInteractive(exact[0]))return false;exact[0].click();return true})()`))===true;
    if (selected) return true;
    if (attempt+1<12) await sleep(500);
  }
  return false;
}

async function safeTSubreasonDiagnostics(cdp, value) {
  const hints=['subcategoria','subcategory','sub category','subreason'];
  const expected=String(value||'').trim();
  try {
    return String(await cdp.evaluate(`(()=>{const hints=${JSON.stringify(hints)};const expected=${JSON.stringify(expected)};const normalize=v=>String(v||'').normalize('NFD').replace(/[\\u0300-\\u036f]/g,'').trim().toLowerCase();const isInteractive=element=>{try{if(!element||element.hidden||element.getAttribute('aria-hidden')==='true'||element.hasAttribute('disabled'))return false;const style=getComputedStyle(element);if(style.display==='none'||style.visibility==='hidden'||Number(style.opacity||1)<=0)return false;const rect=element.getBoundingClientRect();return rect.width>0&&rect.height>0&&element.getClientRects().length>0}catch{return false}};const docs=[];const visit=d=>{if(!d||docs.includes(d))return;docs.push(d);for(const frame of d.querySelectorAll('iframe')){try{visit(frame.contentDocument)}catch{}}};visit(document);const roots=[];const addRoot=r=>{if(!r||roots.includes(r))return;roots.push(r);for(const el of r.querySelectorAll?.('*')||[]){if(el.shadowRoot)addRoot(el.shadowRoot)}};for(const d of docs)addRoot(d);const dropdowns=roots.flatMap(root=>[...(root.querySelectorAll?.('kat-dropdown')||[])]);const semantic=dropdowns.filter(host=>{const meta=normalize([host.getAttribute('placeholder'),host.getAttribute('label'),host.getAttribute('aria-label'),host.getAttribute('name'),host.id,host.className].filter(Boolean).join(' '));return hints.some(h=>meta.includes(h))});const interactiveSemantic=semantic.filter(isInteractive);const options=[];const seen=new Set();for(const root of roots){for(const option of root.querySelectorAll?.('kat-option,option,[role="option"]')||[]){if(seen.has(option))continue;seen.add(option);options.push(option)}}const valueOf=option=>String(option.getAttribute?.('value')??option.value??option.dataset?.value??'').trim();const exact=options.filter(option=>valueOf(option)===expected);const visibleExact=exact.filter(isInteractive).length;return ['d='+dropdowns.length,'s='+semantic.length,'i='+interactiveSemantic.length,'r='+roots.length,'o='+options.length,'e='+exact.length,'ve='+visibleExact].join(';')})()`)).slice(0,220);
  } catch {
    return 'SUBREASON_DIAG_UNAVAILABLE';
  }
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
      return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_SUBREASON_OPTION_MISSING', lookup_reason: await safeTSubreasonDiagnostics(cdp, reason.sub), evidence: await evidence(cdp, 'safet-v1') });
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
  const readBack = await cdp.evaluate(`(()=>{const href=location.href;const body=document.body?.innerText||'';const claimMarker='/claim/';const claimPos=href.indexOf(claimMarker);const fromUrl=claimPos>=0?href.slice(claimPos+claimMarker.length).split(/[/?#]/,1)[0]:'';const fromBody=(body.match(new RegExp('(?:ID da reivindicação SAFE-T[:\\s]*|SAFE-T[:\\s]+)([0-9]{5}-[0-9]{5}-[0-9]{7})','i'))||[])[1]||'';const valid=v=>/^[0-9]{5}-[0-9]{5}-[0-9]{7}$/.test(String(v||''));return valid(fromUrl)?fromUrl:(valid(fromBody)?fromBody:'')})()`);
  if (!text(readBack)) {
    await cdp.navigate(`${SAFE_T_BASE}?pageSize=100&dateFilterValue=90`, 5500);
    const discoveredRaw = await cdp.evaluate(`JSON.stringify([...document.querySelectorAll('div[id^="claim-content-wrapper-"]')].map(e=>{const safe=(e.id.match(/\\d{5}-\\d{5}-\\d{7}/)||[])[0]||'';const href=e.querySelector('a[href*="/orders-v3/order/"]')?.getAttribute('href')||'';const order=(href.match(/\\d{3}-\\d{7}-\\d{7}/)||[])[0]||'';return {safe,order}}).filter(x=>x.order===${JSON.stringify(orderId)}))`);
    let discoveredMatches=[];
    try { discoveredMatches=JSON.parse(text(discoveredRaw)||'[]'); } catch {}
    const discoveredIds=[...new Set(discoveredMatches.map(row=>text(row?.safe)).filter(value=>/^\\d{5}-\\d{5}-\\d{7}$/.test(value)))];
    if (discoveredIds.length===1) {
      return bridgeResult('ACCEPTED', {
        submitted: true,
        external_id: discoveredIds[0],
        retry_safe: true,
        reason: 'SAFE_T_SUBMITTED_AND_DISCOVERED_BY_ORDER',
        evidence: withTrackingEvidence(await evidence(cdp, 'safet-v1'), trackingEvidence, uploadResult),
      });
    }
    if (discoveredIds.length>1) {
      return bridgeResult('FAILED', { reason: 'MULTIPLE_SAFE_T_CLAIMS_AFTER_SUBMIT', submitted: false, retry_safe: false, evidence: await evidence(cdp, 'safet-v1') });
    }
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

async function waitForSafeTAppealField(cdp) {
  const deadline = Date.now() + SAFE_T_APPEAL_FIELD_WAIT_MS;
  do {
    const present = await cdp.evaluate(`Boolean(document.querySelector('kat-textarea.description-textbox'))`);
    if (present) return true;
    if (Date.now() >= deadline) return false;
    await sleep(500);
  } while (true);
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
  const hasField = await waitForSafeTAppealField(cdp);
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
  const legacySend = await cdp.clickKat(sendSelector);
  const semanticSend = legacySend ? '' : text(await cdp.clickButtonTrustedByText(['Send','Enviar']));
  if (!legacySend && !semanticSend) {
    return bridgeResult('UI_DRIFT', { reason: 'SAFE_T_APPEAL_SEND_MISSING', evidence: await evidence(cdp, 'safet-v1') });
  }
  await sleep(5000);
  const confirmed = await cdp.evaluate(`(document.body?.innerText||'').includes(${JSON.stringify(narrative