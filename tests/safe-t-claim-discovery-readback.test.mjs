import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url), 'utf8');

test('SAFE-T post-submit readback recognizes a real claim ID and rejects malformed values', () => {
  const match = source.match(/discoveredMatches\.map\(row=>text\(row\?\.safe\)\)\.filter\(value=>(\/\^.*?\/\.test\(value\))\)/);
  assert.ok(match, 'discovery post-filter must exist');
  const predicate = Function('value', `return ${match[1]}`);
  assert.equal(predicate('12345-12345-1234567'), true);
  assert.equal(predicate('12345-12345-123456'), false);
  assert.equal(predicate('not-a-claim'), false);
});
