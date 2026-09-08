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
