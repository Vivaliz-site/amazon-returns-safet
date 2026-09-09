import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../scripts/amazon-returns/seller-central-auth.mjs', import.meta.url), 'utf8');

test('hidden passkey autofill password field is excluded from credential-stage detection and filling', () => {
  const exclusions = source.match(/:not\(#ap-credential-autofill-hint\)/g) || [];
  assert.ok(
    exclusions.length >= 4,
    'Both password selectors must exclude the hidden #ap-credential-autofill-hint field for name=password and type=password fallbacks.',
  );
  assert.match(source, /:not\(\.hide\)/, 'Hidden password fallback controls must not be treated as the real password input.');
  assert.match(source, /:not\(\[hidden\]\)/, 'HTML-hidden password fallback controls must not be treated as the real password input.');
});
