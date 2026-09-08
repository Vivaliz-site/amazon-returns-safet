import test from 'node:test';
import assert from 'node:assert/strict';
import {
  classifyAmazonAuthState,
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
