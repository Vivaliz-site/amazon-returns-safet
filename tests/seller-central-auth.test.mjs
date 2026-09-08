import test from 'node:test';
import assert from 'node:assert/strict';
import {
  classifyAmazonAuthState,
  ensureSellerCentralAuthenticated,
  parseTotpOutput,
  requestRemoteTotp,
} from '../scripts/amazon-returns/seller-central-auth.mjs';

test('classifies authenticated Seller Central separately from login challenges', () => {
  assert.equal(classifyAmazonAuthState({ href: 'https://sellercentral.amazon.com.br/safet-claims', title: 'SAFE-T', text: 'Reivindicações SAFE-T' }), 'AUTHENTICATED');
  assert.equal(classifyAmazonAuthState({ href: 'https://www.amazon.com/ap/signin', title: 'Amazon Sign-In', text: 'E-mail ou número de telefone Senha' }), 'SIGN_IN');
  assert.equal(classifyAmazonAuthState({ href: 'https://www.amazon.com/ap/mfa', title: 'Verificação em duas etapas', text: 'Digite o código do aplicativo autenticador' }), 'TOTP');
  assert.equal(classifyAmazonAuthState({ href: 'https://www.amazon.com/ap/cvf', title: 'Verifique sua identidade', text: 'CAPTCHA Digite os caracteres' }), 'HUMAN_CHALLENGE');
  assert.equal(classifyAmazonAuthState({ href: 'https://www.amazon.com/ap/challenge', title: 'Confirme sua identidade', text: 'Aprove esta solicitação em outro dispositivo' }), 'HUMAN_CHALLENGE');
  assert.equal(classifyAmazonAuthState({ href: 'https://www.amazon.com/ap/unknown', title: 'Amazon', text: 'Etapa inesperada' }), 'UNKNOWN');
});

test('accepts only a bare six-digit TOTP response', () => {
  assert.equal(parseTotpOutput('123456\n'), '123456');
  for (const invalid of ['12345', '1234567', 'code=123456', '123456\n654321', 'JBSWY3DPEHPK3PXP']) {
    assert.throws(() => parseTotpOutput(invalid), /TOTP/i);
  }
});

test('remote TOTP request uses pinned restrictive SSH options and no shell', async () => {
  let call = null;
  const code = await requestRemoteTotp({
    host: 'amazon-totp@10.0.0.8',
    keyFile: '/var/lib/shopvivaliz/amazon-browser/totp_ed25519',
    knownHostsFile: '/var/lib/shopvivaliz/amazon-browser/totp_known_hosts',
    sshBinary: '/usr/bin/ssh',
    runner: async (file, args, options) => {
      call = { file, args, options };
      return { exitCode: 0, stdout: '834201\n', stderr: '' };
    },
  });
  assert.equal(code, '834201');
  assert.equal(call.file, '/usr/bin/ssh');
  assert.deepEqual(call.args, [
    '-T',
    '-o', 'BatchMode=yes',
    '-o', 'IdentitiesOnly=yes',
    '-o', 'StrictHostKeyChecking=yes',
    '-o', 'UserKnownHostsFile=/var/lib/shopvivaliz/amazon-browser/totp_known_hosts',
    '-o', 'ConnectTimeout=10',
    '-i', '/var/lib/shopvivaliz/amazon-browser/totp_ed25519',
    'amazon-totp@10.0.0.8',
  ]);
  assert.equal(call.options.shell, false);
});

test('remote TOTP request never accepts command noise or failed ssh', async () => {
  await assert.rejects(() => requestRemoteTotp({
    host: 'amazon-totp@10.0.0.8', keyFile: '/tmp/key', knownHostsFile: '/tmp/known',
    runner: async () => ({ exitCode: 0, stdout: 'OTP 123456\n', stderr: '' }),
  }), /TOTP/i);
  await assert.rejects(() => requestRemoteTotp({
    host: 'amazon-totp@10.0.0.8', keyFile: '/tmp/key', knownHostsFile: '/tmp/known',
    runner: async () => ({ exitCode: 255, stdout: '', stderr: 'permission denied' }),
  }), /remote TOTP unavailable/i);
});

test('reuses an authenticated Seller Central session without touching credentials or TOTP', async () => {
  const cdp = { pageState: async () => ({ href: 'https://sellercentral.amazon.com.br/home', title: 'Seller Central', text: 'Início' }) };
  const result = await ensureSellerCentralAuthenticated(cdp, {
    readSecret: () => { throw new Error('must not read credentials'); },
    totpRequester: async () => { throw new Error('must not request TOTP'); },
  });
  assert.deepEqual(result, { status: 'AUTHENTICATED', reason: 'SESSION_REUSED' });
});

test('performs one username/password plus remote TOTP flow and never returns secrets', async () => {
  const states = [
    { href: 'https://www.amazon.com/ap/signin', title: 'Amazon Sign-In', text: 'E-mail Senha' },
    { href: 'https://www.amazon.com/ap/mfa', title: 'Verificação em duas etapas', text: 'Aplicativo autenticador' },
    { href: 'https://sellercentral.amazon.com.br/home', title: 'Seller Central', text: 'Início' },
  ];
  let stateIndex = 0;
  const applied = [];
  const cdp = { pageState: async () => states[Math.min(stateIndex, states.length - 1)] };
  const result = await ensureSellerCentralAuthenticated(cdp, {
    usernameFile: '/secure/account',
    passwordFile: '/secure/password',
    readSecret: file => file.endsWith('account') ? 'account-test-value' : 'password-test-value',
    applyCredentials: async (_cdp, username, password) => {
      applied.push(['credentials', username, password]);
      stateIndex++;
      return true;
    },
    totpRequester: async () => '654321',
    applyTotp: async (_cdp, code) => {
      applied.push(['totp', code]);
      stateIndex++;
      return true;
    },
    sleep: async () => {},
  });
  assert.deepEqual(result, { status: 'AUTHENTICATED', reason: 'SESSION_REAUTHENTICATED' });
  assert.deepEqual(applied, [
    ['credentials', 'account-test-value', 'password-test-value'],
    ['totp', '654321'],
  ]);
  assert.doesNotMatch(JSON.stringify(result), /account-test-value|password-test-value|654321/);
});

test('fails closed on a human or unknown Amazon challenge without requesting a TOTP', async () => {
  for (const state of [
    { href: 'https://www.amazon.com/ap/cvf', title: 'Verifique sua identidade', text: 'CAPTCHA' },
    { href: 'https://www.amazon.com/ap/unknown', title: 'Amazon', text: 'Etapa inesperada' },
  ]) {
    let requested = false;
    const result = await ensureSellerCentralAuthenticated({ pageState: async () => state }, {
      readSecret: () => { throw new Error('must not read credentials'); },
      totpRequester: async () => { requested = true; return '123456'; },
    });
    assert.equal(requested, false);
    assert.ok(['HUMAN_CHALLENGE', 'AUTH_REQUIRED'].includes(result.status));
  }
});


test('submits credentials only once when Amazon remains on sign-in', async () => {
  const state = { href: 'https://www.amazon.com/ap/signin', title: 'Amazon Sign-In', text: 'E-mail Senha' };
  let submissions = 0;
  const result = await ensureSellerCentralAuthenticated({ pageState: async () => state }, {
    usernameFile: '/secure/account', passwordFile: '/secure/password',
    readSecret: file => file.endsWith('account') ? 'account-test-value' : 'password-test-value',
    applyCredentials: async () => { submissions++; return true; },
    totpRequester: async () => { throw new Error('TOTP must not be requested while sign-in is unresolved'); },
    sleep: async () => {},
  });
  assert.equal(submissions, 1);
  assert.deepEqual(result, { status: 'AUTH_REQUIRED', reason: 'SIGN_IN_NOT_COMPLETED' });
});


test('does not resubmit credentials when sign-in remains dynamic after the first submit', async () => {
  const states = [
    { href: 'https://www.amazon.com/ap/signin', title: 'Amazon Sign-In', text: 'E-mail Senha banner 1' },
    { href: 'https://www.amazon.com/ap/signin', title: 'Amazon Sign-In', text: 'E-mail Senha banner 2' },
  ];
  let stateIndex = 0;
  let submissions = 0;
  const result = await ensureSellerCentralAuthenticated({ pageState: async () => states[Math.min(stateIndex, 1)] }, {
    usernameFile: '/secure/account', passwordFile: '/secure/password',
    readSecret: file => file.endsWith('account') ? 'account-test-value' : 'password-test-value',
    applyCredentials: async () => { submissions++; stateIndex++; return true; },
    totpRequester: async () => { throw new Error('TOTP must not be requested while sign-in is unresolved'); },
    sleep: async () => {},
  });
  assert.equal(submissions, 1);
  assert.deepEqual(result, { status: 'AUTH_REQUIRED', reason: 'SIGN_IN_NOT_COMPLETED' });
});


test('allows one identifier transition followed by one password submit', async () => {
  const states = [
    { href: 'https://www.amazon.com/ap/signin', title: 'Amazon Sign-In', text: 'E-mail' },
    { href: 'https://www.amazon.com/ap/signin', title: 'Amazon Sign-In', text: 'Senha' },
    { href: 'https://www.amazon.com/ap/mfa', title: 'Verificação em duas etapas', text: 'Aplicativo autenticador' },
    { href: 'https://sellercentral.amazon.com.br/home', title: 'Seller Central', text: 'Início' },
  ];
  let stateIndex = 0;
  const submissions = [];
  const result = await ensureSellerCentralAuthenticated({ pageState: async () => states[stateIndex] }, {
    usernameFile: '/secure/account', passwordFile: '/secure/password', readSecret: () => 'secret-test-value',
    applyCredentials: async (_cdp, _u, _p, previousStage) => { submissions.push(previousStage); stateIndex++; return submissions.length === 1 ? 'IDENTIFIER_SUBMITTED' : 'PASSWORD_SUBMITTED'; },
    totpRequester: async () => '654321', applyTotp: async () => { stateIndex++; return true; }, sleep: async () => {},
  });
  assert.deepEqual(submissions, [null, 'IDENTIFIER']);
  assert.deepEqual(result, { status: 'AUTHENTICATED', reason: 'SESSION_REAUTHENTICATED' });
});

test('waits for a delayed identifier-to-password transition without resubmitting', async () => {
  let stage = 'IDENTIFIER';
  let auth = 'SIGN_IN';
  let sleeps = 0;
  const submissions = [];
  const cdp = { pageState: async () => auth === 'AUTHENTICATED'
    ? { href: 'https://sellercentral.amazon.com.br/home', title: 'Seller Central', text: 'Início' }
    : auth === 'TOTP'
      ? { href: 'https://www.amazon.com/ap/mfa', title: 'Verificação em duas etapas', text: 'Aplicativo autenticador' }
      : { href: 'https://www.amazon.com/ap/signin', title: 'Amazon Sign-In', text: stage } };
  const result = await ensureSellerCentralAuthenticated(cdp, {
    usernameFile: '/secure/account', passwordFile: '/secure/password', readSecret: () => 'secret-test-value',
    applyCredentials: async (_cdp, _u, _p, previousStage) => {
      if (stage === previousStage) return 'STAGE_UNCHANGED';
      submissions.push(stage);
      if (stage === 'IDENTIFIER') return 'IDENTIFIER_SUBMITTED';
      auth = 'TOTP'; return 'PASSWORD_SUBMITTED';
    },
    sleep: async () => { sleeps++; if (sleeps === 3) stage = 'PASSWORD'; },
    totpRequester: async () => '654321', applyTotp: async () => { auth = 'AUTHENTICATED'; return true; },
  });
  assert.deepEqual(submissions, ['IDENTIFIER', 'PASSWORD']);
  assert.ok(sleeps >= 3);
  assert.deepEqual(result, { status: 'AUTHENTICATED', reason: 'SESSION_REAUTHENTICATED' });
});

test('waits after one password submit without posting it twice', async () => {
  let auth = 'SIGN_IN';
  let sleeps = 0;
  let submissions = 0;
  const cdp = { pageState: async () => auth === 'TOTP'
    ? { href: 'https://www.amazon.com/ap/mfa', title: 'Verificação em duas etapas', text: 'Aplicativo autenticador' }
    : auth === 'AUTHENTICATED'
      ? { href: 'https://sellercentral.amazon.com.br/home', title: 'Seller Central', text: 'Início' }
      : { href: 'https://www.amazon.com/ap/signin', title: 'Amazon Sign-In', text: 'Senha' } };
  const result = await ensureSellerCentralAuthenticated(cdp, {
    usernameFile: '/secure/account', passwordFile: '/secure/password', readSecret: () => 'secret-test-value',
    applyCredentials: async (_cdp, _u, _p, previousStage) => {
      if (previousStage === 'PASSWORD') return 'STAGE_UNCHANGED';
      submissions++; return 'PASSWORD_SUBMITTED';
    },
    sleep: async () => { sleeps++; if (sleeps === 3) auth = 'TOTP'; },
    totpRequester: async () => '654321', applyTotp: async () => { auth = 'AUTHENTICATED'; return true; },
  });
  assert.equal(submissions, 1);
  assert.deepEqual(result, { status: 'AUTHENTICATED', reason: 'SESSION_REAUTHENTICATED' });
});

test('retries a pending credential stage without counting a submission', async () => {
  let auth = 'SIGN_IN';
  let applyCalls = 0;
  const cdp = { pageState: async () => auth === 'TOTP'
    ? { href: 'https://www.amazon.com/ap/mfa', title: 'Verificação em duas etapas', text: 'Aplicativo autenticador' }
    : auth === 'AUTHENTICATED'
      ? { href: 'https://sellercentral.amazon.com.br/home', title: 'Seller Central', text: 'Início' }
      : { href: 'https://www.amazon.com/ap/signin', title: 'Amazon Sign-In', text: 'Senha' } };
  const result = await ensureSellerCentralAuthenticated(cdp, {
    usernameFile: '/secure/account', passwordFile: '/secure/password', readSecret: () => 'secret-test-value',
    applyCredentials: async () => {
      applyCalls++;
      if (applyCalls < 3) return 'STAGE_PENDING';
      auth = 'TOTP'; return 'PASSWORD_SUBMITTED';
    },
    sleep: async () => {},
    totpRequester: async () => '654321', applyTotp: async () => { auth = 'AUTHENTICATED'; return true; },
  });
  assert.equal(applyCalls, 3);
  assert.deepEqual(result, { status: 'AUTHENTICATED', reason: 'SESSION_REAUTHENTICATED' });
});

test('default credential helper marks a disabled submit button as pending', async () => {
  const { readFileSync } = await import('node:fs');
  const source = readFileSync(new URL('../scripts/amazon-returns/seller-central-auth.mjs', import.meta.url), 'utf8');
  assert.match(source, /button\.disabled\)return 'STAGE_PENDING'/);
  assert.doesNotMatch(source, /button\.disabled\)return 'UNSUPPORTED'/);
});

test('preserves a legacy boolean credential hook across identifier and password stages', async () => {
  let stage = 'IDENTIFIER';
  let auth = 'SIGN_IN';
  const submissions = [];
  const cdp = { pageState: async () => auth === 'TOTP'
    ? { href: 'https://www.amazon.com/ap/mfa', title: 'Verificação em duas etapas', text: 'Aplicativo autenticador' }
    : auth === 'AUTHENTICATED'
      ? { href: 'https://sellercentral.amazon.com.br/home', title: 'Seller Central', text: 'Início' }
      : { href: 'https://www.amazon.com/ap/signin', title: 'Amazon Sign-In', text: stage } };
  const result = await ensureSellerCentralAuthenticated(cdp, {
    usernameFile: '/secure/account', passwordFile: '/secure/password', readSecret: () => 'secret-test-value',
    credentialStage: async () => stage,
    applyCredentials: async () => {
      submissions.push(stage);
      if (stage === 'IDENTIFIER') stage = 'PASSWORD';
      else auth = 'TOTP';
      return true;
    },
    sleep: async () => {},
    totpRequester: async () => '654321', applyTotp: async () => { auth = 'AUTHENTICATED'; return true; },
  });
  assert.deepEqual(submissions, ['IDENTIFIER', 'PASSWORD']);
  assert.deepEqual(result, { status: 'AUTHENTICATED', reason: 'SESSION_REAUTHENTICATED' });
});

test('waits through a transient unknown stage after a legacy boolean submit', async () => {
  let stage = 'IDENTIFIER';
  let auth = 'SIGN_IN';
  let unknownPolls = 0;
  const submissions = [];
  const cdp = { pageState: async () => auth === 'TOTP'
    ? { href: 'https://www.amazon.com/ap/mfa', title: 'Verificação em duas etapas', text: 'Aplicativo autenticador' }
    : auth === 'AUTHENTICATED'
      ? { href: 'https://sellercentral.amazon.com.br/home', title: 'Seller Central', text: 'Início' }
      : { href: 'https://www.amazon.com/ap/signin', title: 'Amazon Sign-In', text: stage } };
  const result = await ensureSellerCentralAuthenticated(cdp, {
    usernameFile: '/secure/account', passwordFile: '/secure/password', readSecret: () => 'secret-test-value',
    credentialStage: async () => {
      if (stage !== 'TRANSITION') return stage;
      unknownPolls++;
      if (unknownPolls >= 3) stage = 'PASSWORD';
      return stage === 'PASSWORD' ? 'PASSWORD' : 'UNKNOWN';
    },
    applyCredentials: async () => {
      submissions.push(stage);
      if (stage === 'IDENTIFIER') stage = 'TRANSITION';
      else if (stage === 'PASSWORD') auth = 'TOTP';
      return true;
    },
    sleep: async () => {},
    totpRequester: async () => '654321', applyTotp: async () => { auth = 'AUTHENTICATED'; return true; },
  });
  assert.deepEqual(submissions, ['IDENTIFIER', 'PASSWORD']);
  assert.ok(unknownPolls >= 3);
  assert.deepEqual(result, { status: 'AUTHENTICATED', reason: 'SESSION_REAUTHENTICATED' });
});
