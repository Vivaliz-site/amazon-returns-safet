import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../scripts/amazon-returns/seller-central-auth.mjs', import.meta.url), 'utf8');

test('identifier stage submits the Amazon form natively to bypass WebAuthn interception', () => {
  assert.match(
    source,
    /stage==='IDENTIFIER'.*HTMLFormElement\.prototype\.submit\.call\(email\.form\)/s,
    'The e-mail step must use native form.submit so Amazon passkey JavaScript cannot intercept Continue.',
  );
  assert.match(source, /return 'IDENTIFIER_SUBMITTED'/, 'Native identifier submit must report the identifier transition.');
});
