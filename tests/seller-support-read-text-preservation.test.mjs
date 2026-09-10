import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs.readFileSync(new URL('../scripts/amazon-returns/seller-central-safe-t-read-worker.mjs', import.meta.url), 'utf8');

test('Seller Support read preserves letters while normalizing whitespace', () => {
  const start = source.indexOf('async function supportRead');
  const end = source.indexOf('\nfunction log(', start);
  assert.ok(start >= 0 && end > start, 'supportRead must remain independently auditable');
  const block = source.slice(start, end);
  const match = block.match(/value\.replace\(\/([^/]+)\/g,' '\)/);
  assert.ok(match, 'support text whitespace normalization must remain explicit');
  const generatedPattern = Function(`return \`${match[1]}\`;`)();
  assert.equal(generatedPattern, '\\s+', 'CDP must receive a whitespace regex, not a literal s regex');
  assert.equal('successfully processed'.replace(new RegExp(generatedPattern, 'g'), ' '), 'successfully processed');
});
