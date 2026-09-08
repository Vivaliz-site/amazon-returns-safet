import fs from 'node:fs';
import { spawn } from 'node:child_process';

const normalize = value => String(value ?? '').toLowerCase();

export function classifyAmazonAuthState(state = {}) {
  const href = normalize(state.href);
  const title = normalize(state.title);
  const text = normalize(state.text);
  const combined = `${href}\n${title}\n${text}`;

  const humanMarkers = [
    'captcha', 'digite os caracteres', '/ap/cvf', 'verifique sua identidade',
    'confirme sua identidade', 'aprove esta solicitação', 'outro dispositivo',
    'chave de segurança', 'security key', 'passkey', 'recuperar sua conta',
    'account recovery',
  ];
  if (humanMarkers.some(marker => combined.includes(marker))) return 'HUMAN_CHALLENGE';

  const totpMarkers = [
    '/ap/mfa', 'verificação em duas etapas', 'two-step verification',
    'aplicativo autenticador', 'authenticator app', 'auth-mfa-otpcode',
  ];
  if (totpMarkers.some(marker => combined.includes(marker))) return 'TOTP';

  const signInMarkers = [
    '/ap/signin', 'amazon sign-in', 'iniciar sessão', 'sign in', 'acessar amazon',
  ];
  if (signInMarkers.some(marker => combined.includes(marker))) return 'SIGN_IN';

  if (href.includes('sellercentral.amazon.com.br') && !href.includes('/ap/')) return 'AUTHENTICATED';
  return 'UNKNOWN';
}

export function parseTotpOutput(stdout) {
  const value = String(stdout ?? '').trim();
  if (!/^\d{6}$/.test(value)) throw new Error('Invalid TOTP response.');
  return value;
}

function defaultRunner(file, args, options = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(file, args, {
      shell: false,
      windowsHide: true,
      stdio: ['ignore', 'pipe', 'pipe'],
      ...options,
    });
    let stdout = '';
    let stderr = '';
    const limit = 4096;
    const timer = setTimeout(() => {
      child.kill();
      reject(new Error('Remote TOTP unavailable.'));
    }, 15000);
    child.stdout.on('data', chunk => { if (stdout.length < limit) stdout += String(chunk).slice(0, limit - stdout.length); });
    child.stderr.on('data', chunk => { if (stderr.length < limit) stderr += String(chunk).slice(0, limit - stderr.length); });
    child.once('error', () => { clearTimeout(timer); reject(new Error('Remote TOTP unavailable.')); });
    child.once('close', code => { clearTimeout(timer); resolve({ exitCode: Number(code ?? 255), stdout, stderr }); });
  });
}

export async function requestRemoteTotp({ host, keyFile, knownHostsFile, sshBinary = '/usr/bin/ssh', runner = defaultRunner } = {}) {
  for (const [label, value] of Object.entries({ host, keyFile, knownHostsFile })) {
    if (!String(value ?? '').trim()) throw new Error(`Remote TOTP ${label} is required.`);
  }
  const args = [
    '-o', 'BatchMode=yes',
    '-o', 'IdentitiesOnly=yes',
    '-o', 'StrictHostKeyChecking=yes',
    '-o', `UserKnownHostsFile=${knownHostsFile}`,
    '-o', 'ConnectTimeout=10',
    '-i', keyFile,
    host,
  ];
  const response = await runner(sshBinary, args, { shell: false, windowsHide: true });
  if (Number(response?.exitCode ?? 255) !== 0) throw new Error('Remote TOTP unavailable.');
  return parseTotpOutput(response?.stdout);
}

export function readSecretFile(file) {
  const path = String(file ?? '').trim();
  if (!path) throw new Error('Seller Central credential file is not configured.');
  const value = fs.readFileSync(path, 'utf8').trim();
  if (!value) throw new Error('Seller Central credential file is empty.');
  return value;
}

const defaultSleep = ms => new Promise(resolve => setTimeout(resolve, ms));

async function defaultApplyCredentials(cdp, username, password, previousStage = null) {
  const u = JSON.stringify(String(username));
  const p = JSON.stringify(String(password));
  const previous = JSON.stringify(String(previousStage ?? ''));
  const expression = `(()=>{\n`
    + `const set=(el,val)=>{if(!el)return false;const proto=el.tagName==='TEXTAREA'?HTMLTextAreaElement.prototype:HTMLInputElement.prototype;const d=Object.getOwnPropertyDescriptor(proto,'value');d?.set?.call(el,val);el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));return true};\n`
    + `const email=document.querySelector('#ap_email,input[name="email"],input[type="email"]');\n`
    + `const pass=document.querySelector('#ap_password,input[name="password"],input[type="password"]');\n`
    + `const stage=pass?'PASSWORD':(email?'IDENTIFIER':'UNSUPPORTED');if(stage==='UNSUPPORTED')return 'UNSUPPORTED';if(stage===${previous})return 'STAGE_UNCHANGED';\n`
    + `let touched=false;if(email)touched=set(email,${u})||touched;if(pass)touched=set(pass,${p})||touched;if(!touched)return 'UNSUPPORTED';\n`
    + `const button=(pass?document.querySelector('#signInSubmit,input[type="submit"],button[type="submit"]'):document.querySelector('#continue,input[type="submit"],button[type="submit"]'))||document.querySelector('#signInSubmit,#continue');\n`
    + `if(!button||button.disabled)return 'UNSUPPORTED';button.click();return stage+'_SUBMITTED'})()`;
  return cdp.evaluate(expression);
}

async function defaultApplyTotp(cdp, code) {
  const value = JSON.stringify(String(code));
  const expression = `(()=>{\n`
    + `const input=document.querySelector('#auth-mfa-otpcode,input[name="otpCode"],input[autocomplete="one-time-code"]');if(!input)return false;\n`
    + `const d=Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value');d?.set?.call(input,${value});input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));\n`
    + `const remember=document.querySelector('#auth-mfa-remember-device,input[name="rememberDevice"]');if(remember&&remember.type==='checkbox'&&!remember.checked)remember.click();\n`
    + `const submit=document.querySelector('#auth-signin-button,#signInSubmit,input[type="submit"],button[type="submit"]');if(!submit||submit.disabled)return false;submit.click();return true})()`;
  return (await cdp.evaluate(expression)) === true;
}

export async function ensureSellerCentralAuthenticated(cdp, options = {}) {
  if (!cdp || typeof cdp.pageState !== 'function') throw new Error('Seller Central CDP pageState is required.');
  const readSecret = options.readSecret || readSecretFile;
  const applyCredentials = options.applyCredentials || defaultApplyCredentials;
  const applyTotp = options.applyTotp || defaultApplyTotp;
  const sleep = options.sleep || defaultSleep;
  const usernameFile = options.usernameFile ?? process.env.SELLER_CENTRAL_USERNAME_FILE;
  const passwordFile = options.passwordFile ?? process.env.SELLER_CENTRAL_PASSWORD_FILE;
  const totpRequester = options.totpRequester || (() => requestRemoteTotp({
    host: options.totpHost ?? process.env.SELLER_CENTRAL_TOTP_HOST,
    keyFile: options.totpKeyFile ?? process.env.SELLER_CENTRAL_TOTP_KEY_FILE,
    knownHostsFile: options.totpKnownHostsFile ?? process.env.SELLER_CENTRAL_TOTP_KNOWN_HOSTS_FILE,
    sshBinary: options.sshBinary ?? process.env.SELLER_CENTRAL_TOTP_SSH_BINARY ?? '/usr/bin/ssh',
  }));

  let state = await cdp.pageState();
  let auth = classifyAmazonAuthState(state);
  if (auth === 'AUTHENTICATED') return { status: 'AUTHENTICATED', reason: 'SESSION_REUSED' };
  if (auth === 'HUMAN_CHALLENGE') return { status: 'HUMAN_CHALLENGE', reason: 'AMAZON_HUMAN_CHALLENGE' };
  if (auth === 'UNKNOWN') return { status: 'AUTH_REQUIRED', reason: 'UNKNOWN_AUTH_CHALLENGE' };

  let reauthenticated = false;
  let previousCredentialStage = null;
  let credentialSubmissions = 0;
  let unchangedPolls = 0;
  const configuredStagePolls = Number(options.signInStagePolls ?? 10);
  const maxStagePolls = Number.isFinite(configuredStagePolls)
    ? Math.max(1, Math.min(20, Math.floor(configuredStagePolls))) : 10;
  while (auth === 'SIGN_IN') {
    if (credentialSubmissions >= 2 || previousCredentialStage === 'UNKNOWN') {
      if (++unchangedPolls >= maxStagePolls) return { status: 'AUTH_REQUIRED', reason: 'SIGN_IN_NOT_COMPLETED' };
      await sleep(500);
      state = await cdp.pageState();
      auth = classifyAmazonAuthState(state);
      if (auth === 'HUMAN_CHALLENGE') return { status: 'HUMAN_CHALLENGE', reason: 'AMAZON_HUMAN_CHALLENGE' };
      if (auth === 'UNKNOWN') return { status: 'AUTH_REQUIRED', reason: 'UNKNOWN_AUTH_CHALLENGE' };
      continue;
    }
    let username;
    let password;
    try {
      username = readSecret(usernameFile);
      password = readSecret(passwordFile);
    } catch {
      return { status: 'AUTH_REQUIRED', reason: 'CREDENTIALS_UNAVAILABLE' };
    }
    let applied = false;
    try {
      applied = await applyCredentials(cdp, username, password, previousCredentialStage);
    } finally {
      username = null;
      password = null;
    }
    if (!applied || applied === 'UNSUPPORTED') return { status: 'AUTH_REQUIRED', reason: 'SIGN_IN_UI_UNSUPPORTED' };
    if (applied === 'STAGE_UNCHANGED') {
      if (++unchangedPolls >= maxStagePolls) return { status: 'AUTH_REQUIRED', reason: 'SIGN_IN_NOT_COMPLETED' };
      await sleep(500);
      state = await cdp.pageState();
      auth = classifyAmazonAuthState(state);
      if (auth === 'HUMAN_CHALLENGE') return { status: 'HUMAN_CHALLENGE', reason: 'AMAZON_HUMAN_CHALLENGE' };
      if (auth === 'UNKNOWN') return { status: 'AUTH_REQUIRED', reason: 'UNKNOWN_AUTH_CHALLENGE' };
      continue;
    }
    const submittedStage = applied === 'IDENTIFIER_SUBMITTED' ? 'IDENTIFIER'
      : applied === 'PASSWORD_SUBMITTED' ? 'PASSWORD' : 'UNKNOWN';
    previousCredentialStage = submittedStage;
    credentialSubmissions++;
    unchangedPolls = 0;
    reauthenticated = true;
    await sleep(1200);
    state = await cdp.pageState();
    auth = classifyAmazonAuthState(state);
    if (auth === 'HUMAN_CHALLENGE') return { status: 'HUMAN_CHALLENGE', reason: 'AMAZON_HUMAN_CHALLENGE' };
    if (auth === 'UNKNOWN') return { status: 'AUTH_REQUIRED', reason: 'UNKNOWN_AUTH_CHALLENGE' };
  }
  if (auth === 'SIGN_IN') return { status: 'AUTH_REQUIRED', reason: 'SIGN_IN_NOT_COMPLETED' };

  if (auth === 'TOTP') {
    let code;
    try {
      code = parseTotpOutput(await totpRequester());
    } catch {
      return { status: 'AUTH_REQUIRED', reason: 'TOTP_UNAVAILABLE' };
    }
    let applied = false;
    try {
      applied = await applyTotp(cdp, code);
    } finally {
      code = null;
    }
    if (!applied) return { status: 'AUTH_REQUIRED', reason: 'TOTP_UI_UNSUPPORTED' };
    reauthenticated = true;
    await sleep(1500);
    state = await cdp.pageState();
    auth = classifyAmazonAuthState(state);
  }

  if (auth === 'AUTHENTICATED') return { status: 'AUTHENTICATED', reason: reauthenticated ? 'SESSION_REAUTHENTICATED' : 'SESSION_REUSED' };
  if (auth === 'HUMAN_CHALLENGE') return { status: 'HUMAN_CHALLENGE', reason: 'AMAZON_HUMAN_CHALLENGE' };
  return { status: 'AUTH_REQUIRED', reason: auth === 'UNKNOWN' ? 'UNKNOWN_AUTH_CHALLENGE' : 'REAUTHENTICATION_NOT_COMPLETED' };
}
