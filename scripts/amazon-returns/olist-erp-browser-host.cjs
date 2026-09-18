'use strict';
const fs = require('fs');
const process = require('process');

const modulePath = process.env.OLIST_ERP_PLAYWRIGHT_CORE || '/home/ubuntu/amazon-returns-deploy/shared/olist-erp-browser/runtime/node_modules/playwright-core';
const browserPath = process.env.OLIST_ERP_BROWSER || '/home/ubuntu/.cache/ms-playwright-arm64/chromium-1243/chrome-linux-arm64/chrome';
const profileDir = process.env.OLIST_ERP_BROWSER_PROFILE_DIR || '/home/ubuntu/amazon-returns-deploy/shared/olist-erp-browser/profile';
const cdpPort = Number(process.env.OLIST_ERP_CDP_PORT || 9226);
const targetUrl = 'https://erp.olist.com/devolucoes_vendas#list';

if (!Number.isInteger(cdpPort) || cdpPort < 1024 || cdpPort > 65535) throw new Error('Invalid Olist ERP CDP port.');
if (!fs.existsSync(browserPath)) throw new Error('Olist ERP Chromium binary missing.');
if (!fs.existsSync(modulePath)) throw new Error('Olist ERP Playwright runtime missing.');
fs.mkdirSync(profileDir, { recursive: true, mode: 0o700 });
const { chromium } = require(modulePath);

let context;
let stopping = false;
let reauthInFlight = null;
let primaryPage = null;
async function shutdown(code = 0) {
  if (stopping) return;
  stopping = true;
  try { await context?.close(); } catch {}
  process.exit(code);
}
process.once('SIGTERM', () => void shutdown(0));
process.once('SIGINT', () => void shutdown(130));

(async () => {
  const { classifyOlistLocation, ensureOlistAuthenticated } = await import('./olist-erp-auth.mjs');
  const credentials = {
    email: process.env.OLIST_ERP_LOGIN_EMAIL || '',
    password: process.env.OLIST_ERP_LOGIN_PASSWORD || '',
  };

  async function pruneDuplicateOlistPages(primary) {
    for (const candidate of context?.pages?.() || []) {
      if (candidate === primary) continue;
      const state = classifyOlistLocation(candidate.url());
      if (['AUTH', 'ERP_ENTRY', 'ERP'].includes(state)) {
        await candidate.close().catch(() => {});
      }
    }
  }

  async function maybeReauthenticate(page) {
    if (stopping) return;
    if (reauthInFlight) {
      await reauthInFlight;
      if (stopping) return;
    }
    const state = classifyOlistLocation(page.url());
    if (!['AUTH', 'ERP_ENTRY'].includes(state)) return;
    if (reauthInFlight) return reauthInFlight;

    const current = (async () => {
      const result = await ensureOlistAuthenticated(page, credentials)
        .catch(() => ({ status: 'AUTH_REQUIRED', reason: 'LOGIN_FAILED' }));
      if (result?.status === 'AUTHENTICATED') {
        primaryPage = page;
        await pruneDuplicateOlistPages(page);
      }
      return result;
    })();
    reauthInFlight = current;
    try {
      return await current;
    } finally {
      if (reauthInFlight === current) reauthInFlight = null;
    }
  }

  function triggerPageReauth(page) {
    if (['AUTH', 'ERP_ENTRY'].includes(classifyOlistLocation(page.url()))) {
      void maybeReauthenticate(page);
    }
  }

  function wirePage(page) {
    page.on('framenavigated', frame => {
      if (frame === page.mainFrame()) triggerPageReauth(page);
    });
    page.on('domcontentloaded', () => triggerPageReauth(page));
  }

  context = await chromium.launchPersistentContext(profileDir, {
    headless: true,
    executablePath: browserPath,
    args: [
      '--no-sandbox',
      '--remote-debugging-address=127.0.0.1',
      `--remote-debugging-port=${cdpPort}`,
    ],
  });
  const existingPages = context.pages();
  primaryPage = existingPages.find(candidate => candidate.url().startsWith(targetUrl))
    || existingPages[0]
    || await context.newPage();
  for (const existingPage of existingPages) wirePage(existingPage);
  if (!existingPages.includes(primaryPage)) wirePage(primaryPage);
  await pruneDuplicateOlistPages(primaryPage);
  context.on('page', wirePage);

  await primaryPage.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
  if (['AUTH', 'ERP_ENTRY'].includes(classifyOlistLocation(primaryPage.url()))) await maybeReauthenticate(primaryPage);
  await new Promise(() => {});
})().catch(error => {
  console.error('Olist ERP browser host failed:', error?.name || 'Error', String(error?.message || '').split('\n')[0].slice(0, 300));
  void shutdown(1);
});
