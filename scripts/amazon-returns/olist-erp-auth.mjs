const AUTH_HOSTS = new Set(['accounts.tiny.com.br', 'id.olist.com']);
const ERP_HOST = 'erp.olist.com';
const TARGET_URL = 'https://erp.olist.com/devolucoes_vendas#list';

export function classifyOlistLocation(rawUrl) {
  try {
    const url = new URL(String(rawUrl || ''));
    const host = url.hostname.toLowerCase();
    if (host === ERP_HOST && /^\/login(?:\/|$)/i.test(url.pathname)) return 'AUTH';
    if (host === ERP_HOST && (url.pathname === '/' || url.pathname === '')) return 'ERP_ENTRY';
    if (host === ERP_HOST) return 'ERP';
    if (AUTH_HOSTS.has(host)) return 'AUTH';
    return 'OTHER';
  } catch {
    return 'OTHER';
  }
}

async function firstVisible(page, selectors) {
  for (const selector of selectors) {
    const locator = page.locator(selector).first();
    if (await locator.isVisible().catch(() => false)) return locator;
  }
  return null;
}

async function submitVisibleForm(page) {
  const submit = await firstVisible(page, ['button[type="submit"]', 'input[type="submit"]', '#kc-login']);
  if (!submit) return false;
  await submit.click();
  return true;
}

async function recoverConcurrentSessionEntry(page, timeout) {
  let back = null;
  for (const role of ['button', 'link']) {
    try {
      const candidate = page.getByRole(role, { name: /^voltar para o login$/i }).first();
      if (await candidate.isVisible().catch(() => false)) {
        back = candidate;
        break;
      }
    } catch {}
  }
  if (!back) return false;
  try {
    await back.click();
    if (typeof page.waitForTimeout === 'function') await page.waitForTimeout(250);
    await page.goto(TARGET_URL, { waitUntil: 'domcontentloaded', timeout });
    return classifyOlistLocation(page.url()) === 'ERP';
  } catch {
    return false;
  }
}

export async function ensureOlistAuthenticated(page, credentials = {}, options = {}) {
  let state = classifyOlistLocation(page.url());
  if (state === 'ERP') return { status: 'AUTHENTICATED', reason: 'SESSION_REUSED' };
  if (state === 'ERP_ENTRY') {
    const timeout = Number(options.timeoutMs || 15000);
    try {
      let login = page.getByRole('button', { name: /^login$/i }).first();
      if (!await login.isVisible().catch(() => false)) {
        login = null;
        // Observed ERP da Olist login callback has only "voltar para o login",
        // not the older "Login" button. It must reach the Tiny AUTH flow.
        for (const role of ['button', 'link']) {
          const candidate = page.getByRole(role, { name: /^voltar para o login$/i }).first();
          if (await candidate.isVisible().catch(() => false)) {
            login = candidate;
            break;
          }
        }
      }
      if (!login) return { status: 'AUTH_REQUIRED', reason: 'ERP_ENTRY_UNSUPPORTED' };
      await login.click();
      // The callback may restore an existing ERP session OR redirect to
      // Tiny identity; only treat the former as authenticated.
      await page.waitForURL(url => ['ERP', 'AUTH'].includes(classifyOlistLocation(String(url))), { timeout });
      state = classifyOlistLocation(page.url());
      if (state === 'ERP') {
        if (!page.url().startsWith(TARGET_URL)) {
          await page.goto(TARGET_URL, { waitUntil: 'domcontentloaded', timeout });
        }
        if (classifyOlistLocation(page.url()) !== 'ERP') {
          return { status: 'AUTH_REQUIRED', reason: 'ERP_ENTRY_FAILED' };
        }
        return { status: 'AUTHENTICATED', reason: 'CONCURRENT_SESSION_CONFIRMED' };
      }
      // AUTH is not evidence of a logged-in session: continue below,
      // using only the existing protected credentials if provisioned.
    } catch {
      if (await recoverConcurrentSessionEntry(page, timeout)) {
        return { status: 'AUTHENTICATED', reason: 'CONCURRENT_SESSION_CALLBACK_RECOVERED' };
      }
      if (classifyOlistLocation(page.url()) !== 'AUTH') {
        return { status: 'AUTH_REQUIRED', reason: 'ERP_ENTRY_FAILED' };
      }
      state = 'AUTH';
    }
  }
  if (state !== 'AUTH') return { status: 'AUTH_REQUIRED', reason: 'UNEXPECTED_LOCATION' };

  const email = String(credentials.email || '').trim();
  const password = String(credentials.password || '');
  if (!email || !password) return { status: 'AUTH_REQUIRED', reason: 'FALLBACK_CREDENTIALS_MISSING' };

  const timeout = Number(options.timeoutMs || 15000);
  try {
    const emailInput = await firstVisible(page, ['input[name="username"]', 'input[type="email"]', 'input[name="email"]', '#username']);
    const passwordInput = await firstVisible(page, ['input[type="password"]', 'input[name="password"]', '#password']);

    if (emailInput) await emailInput.fill(email);
    if (passwordInput) {
      await passwordInput.fill(password);
      if (!await submitVisibleForm(page)) return { status: 'AUTH_REQUIRED', reason: 'LOGIN_FORM_UNSUPPORTED' };
    } else {
      if (!emailInput || !await submitVisibleForm(page)) return { status: 'AUTH_REQUIRED', reason: 'LOGIN_FORM_UNSUPPORTED' };
      await page.waitForTimeout(250);
      const nextPassword = await firstVisible(page, ['input[type="password"]', 'input[name="password"]', '#password']);
      if (!nextPassword) return { status: 'AUTH_REQUIRED', reason: 'LOGIN_CHALLENGE' };
      await nextPassword.fill(password);
      if (!await submitVisibleForm(page)) return { status: 'AUTH_REQUIRED', reason: 'LOGIN_FORM_UNSUPPORTED' };
    }

    await page.waitForURL(url => classifyOlistLocation(String(url)) === 'ERP', { timeout });
    if (!page.url().startsWith(TARGET_URL)) {
      await page.goto(TARGET_URL, { waitUntil: 'domcontentloaded', timeout });
    }
    return { status: 'AUTHENTICATED', reason: 'SESSION_REAUTHENTICATED' };
  } catch {
    return { status: 'AUTH_REQUIRED', reason: 'LOGIN_FAILED' };
  }
}

export { TARGET_URL as OLIST_ERP_TARGET_URL };
