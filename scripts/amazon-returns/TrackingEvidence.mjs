import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';

const EVIDENCE_DIR = process.env.SELLER_CENTRAL_EVIDENCE_DIR
  || 'C:\\ShopVivaliz\\amazon-returns-bridge\\evidence';
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const text = value => String(value ?? '').replace(/\s+/g, ' ').trim();
const sha256 = value => createHash('sha256').update(value).digest('hex');

function authState(state) {
  const combined = `${state?.href || ''}\n${state?.title || ''}\n${state?.text || ''}`.toLowerCase();
  if (combined.includes('/signin') || combined.includes('iniciar sessão') || combined.includes('sign in')) return 'AUTH_REQUIRED';
  if (combined.includes('captcha') || combined.includes('digite os caracteres')) return 'HUMAN_CHALLENGE';
  return 'OK';
}

export async function captureTrackingEvidence(cdp, orderId) {
  if (!/^\d{3}-\d{7}-\d{7}$/.test(orderId)) {
    return { status: 'NOT_AVAILABLE', path: null, tracking_id: null };
  }
  await cdp.navigate(`https://sellercentral.amazon.com.br/orders-v3/order/${encodeURIComponent(orderId)}`, 5000);
  const state = await cdp.pageState(8000);
  const auth = authState(state);
  if (auth !== 'OK') return { status: auth, path: null, tracking_id: null };
  const opened = await cdp.evaluate(`(()=>{const b=document.querySelector('button.order-details-tracking-link-button');if(!b)return false;b.scrollIntoView({block:'center',inline:'center'});b.click();return true})()`);
  if (!opened) return { status: 'NOT_AVAILABLE', path: null, tracking_id: null };
  await sleep(1800);
  const trackingId = text(await cdp.evaluate(`document.querySelector('button.order-details-tracking-link-button')?.innerText||''`));
  const shot = await cdp.send('Page.captureScreenshot', {
    format: 'png', fromSurface: true, captureBeyondViewport: false,
  });
  const bytes = Buffer.from(String(shot?.data || ''), 'base64');
  if (bytes.length < 500) {
    return { status: 'FAILED', path: null, tracking_id: trackingId, reason: 'TRACKING_SCREENSHOT_EMPTY' };
  }
  fs.mkdirSync(EVIDENCE_DIR, { recursive: true });
  const file = path.join(EVIDENCE_DIR, `${orderId}-tracking.png`);
  fs.writeFileSync(file, bytes);
  return { status: 'OK', path: file, tracking_id: trackingId, sha256: sha256(bytes) };
}

async function fileInputObject(cdp) {
  const result = await cdp.send('Runtime.evaluate', {
    expression: `(()=>{const q=[document];while(q.length){const root=q.shift();const input=root?.querySelector?.('input[type="file"]');if(input)return input;for(const e of root?.querySelectorAll?.('*')||[]){if(e.shadowRoot)q.push(e.shadowRoot)}}return null})()`,
    returnByValue: false,
  });
  return result.result?.objectId || null;
}

export async function attachFiles(cdp, files) {
  const usable = [...new Set(files.filter(file => typeof file === 'string' && fs.existsSync(file)))];
  if (usable.length === 0) return { attached: false, files: [] };
  const objectId = await fileInputObject(cdp);
  if (!objectId) return { attached: false, files: [] };
  await cdp.send('DOM.setFileInputFiles', { files: usable, objectId });
  await sleep(700);
  const names = await cdp.evaluate(`(()=>{const q=[document];while(q.length){const root=q.shift();const input=root?.querySelector?.('input[type="file"]');if(input)return [...input.files].map(f=>f.name);for(const e of root?.querySelectorAll?.('*')||[]){if(e.shadowRoot)q.push(e.shadowRoot)}}return []})()`);
  return {
    attached: Array.isArray(names) && names.length >= usable.length,
    files: Array.isArray(names) ? names : [],
  };
}

export function withTrackingEvidence(base, tracking, upload) {
  return {
    ...base,
    tracking_evidence_attached: Boolean(upload?.attached),
    tracking_evidence_files: Array.isArray(upload?.files) ? upload.files : [],
    tracking_id: tracking?.tracking_id || null,
    tracking_evidence_sha256: tracking?.sha256 || null,
  };
}
