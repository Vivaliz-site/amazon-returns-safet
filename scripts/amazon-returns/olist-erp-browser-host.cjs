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

  async function maybeReauthenticate(page) {
    if (stopping || reauthInFlight || classifyOlistLocation(page.url()) !== 'AUTH') return;
    reauthInFlight = ensureOlistAuthenticated(page, credentials)
      .catch(() => ({ status: 'AUTH_REQUIRED', reason: 'LOGIN_FAILED' }))
      .finally(() => { reauthInFlight = null; });
    await reauthInFlight;
  }

  function wirePage(page) {
    page.on('framenavigated', frame => {
      if (frame === page.mainFrame() && classifyOlistLocation(page.url()) === 'AUTH') {
        void maybeReauthenticate(page);
      }
    });
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
  context.on('page', wirePage);
  for (const existingPage of context.pages()) wirePage(existingPage);

  const page = context.pages()[0] || await context.newPage();
  await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
  if (classifyOlistLocation(page.url()) === 'AUTH') await maybeReauthenticate(page);
  await new Promise(() => {});
})().catch(error => {
  console.error('Olist ERP browser host failed:', error?.name || 'Error', String(error?.message || '').split('\n')[0].slice(0, 300));
  void shutdown(1);
});
