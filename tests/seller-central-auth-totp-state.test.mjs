import test from 'node:test';
import assert from 'node:assert/strict';
import { requestRemoteTotp, ensureSellerCentralAuthenticated } from '../scripts/amazon-returns/seller-central-auth.mjs';

test('surfaces an unconfigured remote TOTP seed without exposing command output', async () => {
  await assert.rejects(
    requestRemoteTotp({
      host:'amazon-totp@10.0.1.38', keyFile:'k', knownHostsFile:'h', sshBinary:'ssh.exe',
      runner:async()=>({exitCode:78,stdout:'',stderr:'SEED_NOT_CONFIGURED\n'}),
    }),
    error => error?.code === 'SEED_NOT_CONFIGURED' && error.message === 'Remote TOTP seed is not configured.'
  );
});

test('maps an unconfigured TOTP seed to a distinct fail-closed auth reason', async () => {
  const cdp={pageState:async()=>({href:'https://www.amazon.com/ap/mfa',title:'Verificação em duas etapas',text:'Aplicativo autenticador'})};
  const result=await ensureSellerCentralAuthenticated(cdp,{
    totpRequester:async()=>{const e=new Error('Remote TOTP seed is not configured.');e.code='SEED_NOT_CONFIGURED';throw e;},
  });
  assert.deepEqual(result,{status:'AUTH_REQUIRED',reason:'TOTP_SEED_NOT_CONFIGURED'});
});
